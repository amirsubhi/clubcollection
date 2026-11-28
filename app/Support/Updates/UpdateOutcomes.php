<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Http\Controllers\WebhookController;
use App\Mail\PortalUpdateResult;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What the Laravel side does with the way an update attempt ended (spec J1, J4, J5, J7 and section 12): the standalone
 * updater writes outcome.json and a history file without Laravel; the next request that boots Laravel consumes the
 * outcome once (audit row, `blocked` flag, email to the super admins), and replays the payment callbacks the swap guard
 * saved while the portal was paused. The history file is what the Updates page and the dashboard notice read.
 */
final class UpdateOutcomes
{
    /** The audit action per outcome. */
    private const AUDIT_ACTIONS = [
        'finished' => 'portal.update_finished',
        'rolled_back' => 'portal.update_rolled_back',
        'undone' => 'portal.update_rolled_back',
        'blocked' => 'portal.update_rolled_back',
        'failed' => 'portal.update_failed_check',
    ];

    /** The handler that takes each saved callback path. */
    private const WEBHOOK_METHODS = ['/webhook/toyyibpay' => 'toyyibpay', '/webhook/billplz' => 'billplz'];

    public const REPLAY_PER_REQUEST = 50;

    public const MAX_TRIES = 3;

    /** A callback being replayed by a request that died is put back after this many seconds. */
    private const CLAIM_STALE_SECONDS = 600;

    /** The two cheap checks the middleware makes: is there anything to do at all. */
    public static function pending(): bool
    {
        return is_file(UpdaterFiles::outcomePath()) || is_dir(UpdaterFiles::webhooksDir());
    }

    /** Takes the outcome (once) and replays saved callbacks. Nothing happens while the swap guard is up. */
    public static function consume(): void
    {
        if (is_file(UpdaterFiles::guardPath())) {
            return;
        }
        self::takeOutcome();
        self::replayWebhooks();
    }

    private static function takeOutcome(): void
    {
        $file = UpdaterFiles::outcomePath();
        if (! is_file($file)) {
            return;
        }
        // Only the request whose rename succeeds goes on: two requests never both consume.
        $taken = $file.'.consuming-'.bin2hex(random_bytes(6));
        if (! @rename($file, $taken)) {
            return;
        }
        $raw = (string) @file_get_contents($taken);
        @unlink($taken);
        $outcome = json_decode($raw, true);
        if (! is_array($outcome) || ! isset(self::AUDIT_ACTIONS[$outcome['outcome'] ?? ''])) {
            Log::warning('The update outcome file could not be read and was discarded.');

            return;
        }

        try {
            self::ensureHistory($outcome);
        } catch (Throwable $e) {
            Log::warning('The update history could not be written: '.$e->getMessage());
        }
        if ($outcome['outcome'] === 'blocked') {
            @file_put_contents(UpdaterFiles::blockedPath(), json_encode(['at' => $outcome['at'] ?? time(), 'reason' => $outcome['reason'] ?? null])."\n");
        }
        try {
            $d = self::describe($outcome);
            AuditService::asSystem(fn () => AuditService::log(
                self::AUDIT_ACTIONS[$outcome['outcome']],
                strip_tags($d['title']),
                null,
                null,
                [],
                [
                    'outcome' => $outcome['outcome'],
                    'from' => $outcome['from'] ?? null,
                    'to' => $outcome['to'] ?? null,
                    'step' => $outcome['step'] ?? null,
                    'reason' => $outcome['reason'] ?? null,
                ],
            ));
        } catch (Throwable $e) {
            Log::warning('The update outcome could not be written to the audit log: '.$e->getMessage());
        }
        self::mail($outcome);
    }

    /** One email per super admin, sent now (never queued), best effort. */
    private static function mail(array $outcome): void
    {
        try {
            $admins = User::query()->where('role', 'super_admin')->whereNotNull('email')->get();
        } catch (Throwable $e) {
            Log::warning('The update email was not sent: '.$e->getMessage());

            return;
        }
        $d = self::describe($outcome);
        foreach ($admins as $user) {
            try {
                // Mail::to($user): an admin's emails are English (UserLocale::forMail).
                Mail::to($user)->send(new PortalUpdateResult($d, (string) ($outcome['report'] ?? '')));
            } catch (Throwable $e) {
                Log::warning('The update email was not sent: '.$e->getMessage());
            }
        }
    }

    /** The history file for this attempt; written here only when the standalone updater did not write it. */
    private static function ensureHistory(array $o): void
    {
        $dir = UpdaterFiles::historyDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }
        $at = is_int($o['at'] ?? null) ? $o['at'] : time();
        $started = is_int($o['started_at'] ?? null) ? $o['started_at'] : null;
        $name = gmdate('YmdHis', $started ?? $at).'-'.self::safeVersion($o['from'] ?? null).'-'.self::safeVersion($o['to'] ?? null).'.json';
        if (is_file($dir.'/'.$name)) {
            return;
        }
        @file_put_contents($dir.'/'.$name, json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }

    /** The same file-name form as the standalone updater's history files. */
    private static function safeVersion(mixed $version): string
    {
        $version = is_string($version) && $version !== '' ? $version : '?';
        $clean = trim((string) preg_replace('/[^0-9A-Za-z.+]+/', '-', $version), '.-');
        $clean = (string) preg_replace('/\.{2,}/', '.', $clean);

        return $clean === '' ? 'unknown' : substr($clean, 0, 40);
    }

    // ── Saved payment callbacks ─────────────────────────────────────────────────────────────────────────────────

    /** Replays up to 50 saved callbacks, oldest first, through the normal webhook handler. */
    public static function replayWebhooks(): void
    {
        $dir = UpdaterFiles::webhooksDir();
        $names = is_dir($dir) ? @scandir($dir) : false;
        if (! is_array($names)) {
            return;
        }
        $waiting = [];
        foreach ($names as $name) {
            if (preg_match('/^(\d+-[0-9a-f]+\.json)\.claim-[0-9a-f]+$/', $name, $m) === 1) {
                // A request that died mid-replay: put the callback back for the next one.
                if (@filemtime($dir.'/'.$name) < time() - self::CLAIM_STALE_SECONDS) {
                    @rename($dir.'/'.$name, $dir.'/'.$m[1]);
                }
            } elseif (preg_match('/^\d+-[0-9a-f]+\.json$/', $name) === 1) {
                $waiting[] = $name;
            }
        }
        usort($waiting, 'strnatcmp');

        foreach (array_slice($waiting, 0, self::REPLAY_PER_REQUEST) as $name) {
            // Claimed by a rename, so two requests never replay the same callback together.
            $claim = $dir.'/'.$name.'.claim-'.bin2hex(random_bytes(6));
            if (! @rename($dir.'/'.$name, $claim)) {
                continue;
            }
            @touch($claim);
            $saved = json_decode((string) @file_get_contents($claim), true);
            $status = is_array($saved) ? self::replayOne($saved) : -1;
            if ($status >= 200 && $status < 300) {
                @unlink($claim);

                continue;
            }
            $tries = (is_array($saved) && is_int($saved['tries'] ?? null) ? $saved['tries'] : 0) + 1;
            if ($status < 0 || $tries >= self::MAX_TRIES) {
                $failed = $dir.'/failed';
                if ((is_dir($failed) || @mkdir($failed, 0750, true) || is_dir($failed)) && @rename($claim, $failed.'/'.$name)) {
                    Log::warning('A saved payment callback could not be replayed and was set aside.', ['file' => $name, 'status' => $status]);
                } else {
                    @unlink($claim);
                }

                continue;
            }
            $saved['tries'] = $tries;
            $json = json_encode($saved, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json) && @file_put_contents($claim, $json) !== false) {
                @rename($claim, $dir.'/'.$name);
            }
        }
    }

    /** The HTTP status the handler answered; 0 when the handler failed, -1 when the saved file can never be replayed. */
    private static function replayOne(array $saved): int
    {
        $path = $saved['path'] ?? null;
        $body = $saved['body'] ?? null;
        if (! is_string($path) || ! isset(self::WEBHOOK_METHODS[$path]) || ! is_string($body)) {
            return -1;
        }
        $server = [];
        $type = '';
        foreach (is_array($saved['headers'] ?? null) ? $saved['headers'] : [] as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            $name = strtolower($name);
            if ($name === 'content-type') {
                $server['CONTENT_TYPE'] = $type = $value;
            } else {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }
        $params = [];
        if (stripos($type, 'application/x-www-form-urlencoded') !== false) {
            parse_str($body, $params);
        }
        $query = is_string($saved['query'] ?? null) ? $saved['query'] : '';
        $request = Request::create($path.($query === '' ? '' : '?'.$query), 'POST', $params, [], [], $server, $body);

        try {
            $response = AuditService::asSystem(fn () => app()->call([app(WebhookController::class), self::WEBHOOK_METHODS[$path]], ['request' => $request]));

            return $response->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode();
        } catch (Throwable $e) {
            Log::warning('A saved payment callback failed while replaying: '.$e->getMessage());

            return 0;
        }
    }

    // ── History ───────────────────────────────────────────────────────────────────────────────────────────────

    /** The newest history entry (any outcome), with `file` set to its file name; null when there is none. */
    public static function latest(): ?array
    {
        $dir = UpdaterFiles::historyDir();
        $names = is_dir($dir) ? @scandir($dir) : false;
        if (! is_array($names)) {
            return null;
        }
        $names = array_values(array_filter($names, fn ($n) => preg_match('/^\d{14}-[^\/]+\.json$/', $n) === 1));
        rsort($names);
        foreach (array_slice($names, 0, 5) as $name) {
            $h = json_decode((string) @file_get_contents($dir.'/'.$name), true);
            if (is_array($h) && isset(self::AUDIT_ACTIONS[$h['outcome'] ?? ''])) {
                return $h + ['file' => $name];
            }
        }

        return null;
    }

    /**
     * The notice (J7) a super admin still has to dismiss: the newest attempt did not finish, and this admin has not
     * dismissed it. Null for anyone else, and once a later update has finished.
     */
    public static function noticeFor(?User $user): ?array
    {
        if ($user === null || ! $user->isSuperAdmin()) {
            return null;
        }
        $h = self::latest();
        if ($h === null || $h['outcome'] === 'finished' || in_array($user->id, (array) ($h['dismissed_by'] ?? []), true)) {
            return null;
        }

        return self::describe($h) + ['file' => $h['file']];
    }

    /** Remembers, in the history file, that this admin dismissed the notice. False for a name that is not a history file. */
    public static function dismiss(User $user, string $file): bool
    {
        if (preg_match('/^\d{14}-[0-9A-Za-z.+-]+\.json$/', $file) !== 1 || str_contains($file, '..')) {
            return false;
        }
        $path = UpdaterFiles::historyDir().'/'.$file;
        if (! is_file($path) || ! ($fh = @fopen($path, 'c+'))) {
            return false;
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                return false;
            }
            $h = json_decode((string) stream_get_contents($fh), true);
            if (! is_array($h)) {
                return false;
            }
            $by = array_values(array_unique(array_merge(array_map('intval', (array) ($h['dismissed_by'] ?? [])), [$user->id])));
            $h['dismissed_by'] = $by;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            fflush($fh);

            return true;
        } finally {
            fclose($fh);
        }
    }

    // ── The words ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The screen text of an outcome (J1 done, J4 undone, J5 put back, J5 blocked, and a failure before anything
     * changed), shared by the Updates page, the dashboard notice and the email.
     *
     * @param  array<string, mixed>  $h  an outcome.json or history entry
     * @return array{outcome: string, subject: string, title: string, lines: list<string>, what_happened: ?string, what_to_do: ?string, notice: string, label: string, when: string}
     */
    public static function describe(array $h): array
    {
        $outcome = (string) ($h['outcome'] ?? 'failed');
        $from = self::version($h['from'] ?? null);
        $to = self::version($h['to'] ?? null);
        $tz = is_string($h['timezone'] ?? null) ? $h['timezone'] : (string) config('app.timezone');
        $at = is_int($h['at'] ?? null) ? $h['at'] : null;
        $started = is_int($h['started_at'] ?? null) ? $h['started_at'] : $at;
        $time = $at === null ? 'a little while ago' : self::time($at, $tz, 'g:i a');
        $reason = is_string($h['reason'] ?? null) && trim($h['reason']) !== '' ? trim($h['reason']) : null;
        $update = $to === null ? 'The update' : "The update to $to";
        $onFrom = $from === null ? 'on the version it had before' : "on $from";
        $nothingNow = $from === null ? 'Your portal is safe.' : "Your portal is safe on $from.";
        $help = "If you'd like help, send the report to whoever installed the portal, or to the Club Portal team.";
        $d = [
            'outcome' => $outcome,
            'when' => $started === null ? '' : self::time($started, $tz, 'j M, g:i a'),
        ];

        switch ($outcome) {
            case 'finished':
                $d += [
                    'subject' => $to === null ? 'Club Portal updated' : "Club Portal updated to $to",
                    'title' => $to === null ? 'Done. Club Portal is updated.' : "Done. Club Portal is now $to.",
                    'lines' => array_merge(
                        ['Members can use the portal again.'],
                        array_map(fn ($l) => '• '.$l, array_values(array_filter((array) ($h['whats_new'] ?? []), 'is_string'))),
                        ["A copy of your club's data from before the update is kept on your site (with your other backups)."],
                    ),
                    'notice' => '',
                    'label' => 'installed',
                ];
                break;
            case 'undone':
                $d += [
                    'subject' => "$update didn't finish",
                    'title' => "$update didn't finish, because the update page was closed or the phone's screen went off.",
                    'lines' => ["Your portal was put back $onFrom at $time and is working normally. Nothing was lost."],
                    'notice' => "$update didn't finish and was undone at $time. Your portal has been working normally since.",
                    'label' => 'undone, because it did not finish',
                ];
                break;
            case 'rolled_back':
                $d += [
                    'subject' => "$update was put back",
                    'title' => "The update didn't work on your site, so we put it back.",
                    'lines' => [($from === null ? 'Your portal is working normally.' : "Your portal is on $from and working normally.").' Nothing was lost.'],
                    'what_happened' => $reason,
                    'what_to_do' => "Nothing right now. $nothingNow You can try again after the next release. $help",
                    'notice' => "$update didn't work on your site and was put back at $time. Your portal has been working normally since.",
                    'label' => 'put back automatically',
                ];
                break;
            case 'blocked':
                $d += [
                    'subject' => "$update left one change unfinished",
                    'title' => ($from === null ? 'The portal is back on its earlier version' : "The portal is back on $from").", but one change to your club's records didn't finish.",
                    'lines' => ['Your members can still use the portal. The update button is switched off until this is sorted.'],
                    'what_happened' => $reason,
                    'what_to_do' => 'Send the report to whoever looks after the portal: they may need to restore the copy of your data saved before the update'
                        .($started === null ? '.' : ' (started at '.self::time($started, $tz, 'g:i a').').'),
                    'notice' => "One change to your club's records didn't finish when $update was put back at $time. The update button is switched off until this is sorted.",
                    'label' => 'needs someone to look at it',
                ];
                break;
            default: // failed: stopped before anything changed
                $d += [
                    'subject' => "$update did not start",
                    'title' => "$update stopped before anything was changed.",
                    'lines' => [($from === null ? 'Your portal is working normally.' : "Your portal is on $from and working normally.").' Nothing was lost.'],
                    'what_happened' => $reason,
                    'what_to_do' => "You can try again later. $help",
                    'notice' => "$update stopped before anything was changed (at $time). Your portal has been working normally since.",
                    'label' => 'stopped before anything changed',
                ];
        }

        return $d + ['what_happened' => null, 'what_to_do' => null];
    }

    /** "v2.2.1" for a version, null for the "?" a recovery with no job writes. */
    private static function version(mixed $v): ?string
    {
        return is_string($v) && $v !== '' && $v !== '?' ? (str_starts_with($v, 'v') ? $v : 'v'.$v) : null;
    }

    private static function time(int $unix, string $tz, string $format): string
    {
        try {
            return CarbonImmutable::createFromTimestamp($unix, $tz)->format($format);
        } catch (Throwable) {
            return CarbonImmutable::createFromTimestamp($unix, (string) config('app.timezone'))->format($format);
        }
    }
}
