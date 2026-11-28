<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * public/update/: the page that runs the update (GET) and the step endpoint (POST ?step=<name>, JSON). Reachable
 * without signing in to the portal: the steps need the job's token cookie; the result screens need nothing. Every
 * path comes from the updater's own location (Context), never from the request.
 *
 * Every visit first runs the same idle recovery as the swap guard, then completes an interrupted finish and ends a job
 * abandoned before the pause.
 */
final class Page
{
    public const ISSUE_URL = 'https://github.com/amirsubhi/clubcollection/issues/new';

    /** A report link longer than this is shortened (browsers and GitHub refuse very long addresses). */
    public const MAX_URL = 7000;

    /** A result older than this is not shown any more ("No update in progress"). */
    public const RESULT_FOR = 86400;

    /**
     * What the browser looks for in the portal page it gets from /login with the bypass cookie: the sign-in form's
     * token field, or the app's csrf-token meta when the signed-in admin is sent on to the dashboard.
     */
    public const LOGIN_MARKER = 'name="(csrf-token|_token)"';

    /** `swap` and `opcache` share one row on the screen. */
    private const SHARED_ROWS = ['swap' => 'switch', 'opcache' => 'switch'];

    /**
     * @param  array{method?: string, query?: array<string, mixed>, cookies?: array<string, mixed>, server?: array<string, mixed>, body?: string}  $req
     * @param  ?\Closure(string): Context  $contextFactory  tests pass one with fakes
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<array{name: string, value: string, expires: int}>}
     */
    public static function handle(string $app, array $req, ?\Closure $contextFactory = null): array
    {
        $c = $contextFactory !== null ? $contextFactory($app) : new Context($app);
        $server = $req['server'] ?? [];
        $c->https = self::isHttps($server);
        $cookie = $req['cookies'][Auth::COOKIE] ?? null;
        $cookie = is_string($cookie) ? $cookie : null;

        try {
            Recovery::runIfIdle($app); // records its own failure (recovery_error) for the screen below
        } catch (\Throwable) {
        }

        if (strtoupper((string) ($req['method'] ?? 'GET')) === 'POST') {
            return self::post($c, $req, $server, $cookie);
        }

        return self::get($c, $cookie);
    }

    // ---------------------------------------------------------------- POST ?step=

    /** @return array{status: int, headers: array<string, string>, body: string, cookies: list<array>} */
    private static function post(Context $c, array $req, array $server, ?string $cookie): array
    {
        $step = $req['query']['step'] ?? null;
        if (! is_string($step) || preg_match('/^[a-z_]{1,20}$/', $step) !== 1) {
            return self::json(['status' => 'denied', 'message' => 'Unknown request.'], 400);
        }
        if (! self::sameOrigin($server) || ($server['HTTP_X_UPDATER'] ?? null) !== '1') {
            return self::json(['status' => 'denied', 'message' => 'Refused.'], 403);
        }
        if (! Auth::check($c->files->dir, $cookie, $c->now())) {
            return self::json(['step' => $step, 'status' => 'denied', 'next' => null, 'message' => 'This update is no longer running here.'], 403);
        }
        if ($step === 'status') {
            return self::json(Steps::status($c));
        }
        $input = json_decode((string) ($req['body'] ?? ''), true);
        $c->input = is_array($input) ? $input : [];

        $result = Steps::run($step, $c);
        $result['ended'] = self::ended($c);

        return self::json($result, 200, $c->cookies);
    }

    /** True when the page should reload to show a result: the job has ended, or the undo needs a person. */
    private static function ended(Context $c): bool
    {
        $job = Auth::job($c->files->dir) ?? [];
        if (! in_array($job['status'] ?? null, ['ready', 'running'], true)) {
            return true;
        }
        $raw = @file_get_contents($c->files->journalPath);
        $journal = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($journal) && ($journal['meta']['recovery_error'] ?? null) !== null;
    }

    // ---------------------------------------------------------------- GET

    /** @return array{status: int, headers: array<string, string>, body: string, cookies: list<array>} */
    private static function get(Context $c, ?string $cookie): array
    {
        Steps::completeFinishing($c);
        Steps::expireAbandoned($c);
        $nonce = bin2hex(random_bytes(12));

        $stuck = self::stuck($c);
        if ($stuck !== null) {
            return self::html(self::stuckScreen($c, $stuck, $nonce), $nonce);
        }
        if (Auth::check($c->files->dir, $cookie, $c->now())) {
            return self::html(self::runner($c, $nonce), $nonce);
        }
        $job = Auth::job($c->files->dir) ?? [];
        $open = in_array($job['status'] ?? null, ['ready', 'running'], true)
            && ! (($job['status'] ?? null) === 'ready' && is_int($job['expires_at'] ?? null) && $job['expires_at'] < $c->now());
        if ($open || is_file($c->files->guardPath)) {
            return self::html(self::inProgress($c, $job, $nonce), $nonce);
        }
        $result = self::latestResult($c);
        if ($result !== null) {
            return self::html(self::resultScreen($result, Auth::isTokenHolder($c->files->dir, $cookie), $nonce), $nonce);
        }

        return self::html(self::screen('No update in progress',
            '<h1>No update in progress</h1><p>There is no update running on this site right now.</p>'
            .'<p class="actions"><a class="button" href="/admin/updates">Go to the Updates page</a></p>', $nonce), $nonce);
    }

    /**
     * The "could not be undone by itself" case: the guard file is up and either the journal cannot be read, or the undo
     * failed (`recovery_error`) or stopped part-way (`swap.state` = `rolling_back`) and nothing is working on it now
     * ("idle" = the update lock is free). Returns what was found, or null.
     *
     * @return ?array{journal: ?array<string, mixed>, error: string}
     */
    private static function stuck(Context $c): ?array
    {
        clearstatcache();
        if (! is_file($c->files->guardPath) || ! is_file($c->files->journalPath)) {
            return null;
        }
        $raw = @file_get_contents($c->files->journalPath);
        $journal = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($journal)) {
            return ['journal' => null, 'error' => 'The update journal cannot be read.'];
        }
        $error = $journal['meta']['recovery_error'] ?? null;
        $rollingBack = ($journal['meta']['swap']['state'] ?? null) === 'rolling_back';
        if ($error === null && ! $rollingBack) {
            return null;
        }
        $lock = Lock::acquire($c->files->dir);
        if ($lock === null) {
            return null; // something is working on it right now
        }
        $lock->release();
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Putting the old files back stopped part-way.';

        return ['journal' => $journal, 'error' => $message];
    }

    /** @return ?array<string, mixed> the newest history entry (or outcome.json) from the last day */
    private static function latestResult(Context $c): ?array
    {
        $files = glob($c->files->historyDir.'/*.json') ?: [];
        rsort($files, SORT_STRING);
        $candidates = $files === [] ? [] : [$files[0]];
        $candidates[] = $c->files->outcomePath;
        foreach ($candidates as $file) {
            $raw = is_file($file) ? @file_get_contents($file) : false;
            $h = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($h) && in_array($h['outcome'] ?? null, Outcome::OUTCOMES, true) && is_int($h['at'] ?? null)
                && $c->now() - $h['at'] <= self::RESULT_FOR) {
                return $h;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- screens

    private static function runner(Context $c, string $nonce): string
    {
        $job = Auth::job($c->files->dir) ?? [];
        $to = self::version($job['to_version'] ?? null) ?? 'the new version';
        $states = [];
        $next = 'prechecks';
        $swapStarted = false;
        $details = [];
        try {
            $j = Journal::open($c->files->dir, $c->clock);
            if (Steps::isThisAttempt($j, $job)) {
                foreach ($j->steps() as $step => $s) {
                    $states[$step] = $s['state'];
                    $details[] = "$step: {$s['state']}";
                    foreach (array_slice($s['log'], -5) as $line) {
                        $details[] = '  '.$line;
                    }
                }
                $next = $j->firstUnfinished();
                $swapStarted = $j->isSwapStarted();
            }
        } catch (UpdaterError $e) {
            $details[] = $e->getMessage();
        }

        $rows = '';
        $seen = [];
        foreach (Journal::STEPS as $step) {
            $row = self::SHARED_ROWS[$step] ?? $step;
            if (isset($seen[$row])) {
                continue;
            }
            $seen[$row] = true;
            $state = self::rowState($row, $states);
            $rows .= '<li data-row="'.self::e($row).'" class="state-'.$state.'"><span class="mark" aria-hidden="true">'
                .self::MARKS[$state].'</span> '.self::e(Steps::screenName($step, (string) ($job['to_version'] ?? ''))).'</li>';
        }
        $state = ['next' => $next, 'swapStarted' => $swapStarted, 'loginMarker' => self::LOGIN_MARKER];
        $keep = 'keep'.($c->https ? '' : ' bold');

        $body = '<h1>Updating to '.self::e($to).' — about 2 minutes</h1>'
            .'<p id="keep" class="'.$keep.'">Keep this page open and your screen on until it says Done.</p>'
            .'<p class="muted">While the portal is paused, members see a "back in a few minutes" page.</p>'
            .'<ol class="steps">'.$rows.'</ol>'
            .'<p id="note" role="status" aria-live="polite"></p>'
            .'<details><summary>Show details</summary><pre id="details">'.self::e(implode("\n", $details)."\n").'</pre></details>'
            .'<script type="application/json" id="state">'.self::jsonForHtml($state).'</script>'
            .'<script nonce="'.$nonce.'">'.self::RUNNER_JS.'</script>';

        return self::screen('Updating Club Portal', $body, $nonce);
    }

    /** @param array<string, mixed> $h */
    private static function resultScreen(array $h, bool $showReport, string $nonce): string
    {
        $outcome = (string) $h['outcome'];
        $from = self::version($h['from'] ?? null);
        $to = self::version($h['to'] ?? null);
        $tz = is_string($h['timezone'] ?? null) ? $h['timezone'] : 'UTC';
        $time = self::time($h['at'] ?? null, $tz, 'g:i a') ?? 'a little while ago';
        $update = $to === null ? 'The update' : "The update to $to";
        $reason = is_string($h['reason'] ?? null) && trim($h['reason']) !== '' ? trim($h['reason']) : null;
        $help = "If you'd like help, send the report to whoever installed the portal, or to the Club Portal team.";
        $extra = '';
        $tryAgain = false;

        switch ($outcome) {
            case 'finished':
                $title = $to === null ? 'Done. Club Portal is updated.' : "Done. Club Portal is now $to.";
                $lines = ['Members can use the portal again.'];
                $news = array_slice(array_values(array_filter((array) ($h['whats_new'] ?? []), 'is_string')), 0, 5);
                if ($news !== []) {
                    $extra .= '<h2>What\'s new</h2><ul>'.implode('', array_map(static fn (string $l): string => '<li>'.self::e($l).'</li>', $news)).'</ul>';
                }
                $extra .= ($h['database_in_backup'] ?? true) === false
                    ? '<p>Your club\'s data could not be copied automatically on this host; the cPanel backup taken before the update is your copy.</p>'
                    : '<p>A copy of your club\'s data from before the update is kept on your site (with your other backups).</p>';
                $local = array_values(array_filter((array) ($h['site_local'] ?? []), 'is_string'));
                if ($local !== []) {
                    $extra .= '<p>Files that belong to your site (not to Club Portal) were kept:</p><ul>'
                        .implode('', array_map(static fn (string $p): string => '<li><code>'.self::e($p).'</code></li>', $local)).'</ul>';
                }
                $todo = null;
                break;
            case 'undone':
                $title = "$update didn't finish, because this page was closed or the phone's screen went off.";
                $lines = ['Your portal was put back '.($from === null ? 'on the version it had before' : "on $from")." at $time and is working normally. Nothing was lost."];
                $todo = 'You can try again when it suits you.';
                $tryAgain = true;
                break;
            case 'rolled_back':
                $title = "The update didn't work on your site, so we put it back.";
                $lines = [($from === null ? 'Your portal is working normally.' : "Your portal is on $from and working normally."), 'Nothing was lost.'];
                $todo = 'Nothing right now. '.($from === null ? 'Your portal is safe.' : "Your portal is safe on $from.")." You can try again after the next release. $help";
                break;
            case 'blocked':
                $title = ($from === null ? 'The portal is back on its earlier version' : "The portal is back on $from").", but one change to your club's records didn't finish.";
                $lines = ['Your members can still use the portal. The update button is switched off until this is sorted.'];
                $saved = self::time($h['backup_at'] ?? ($h['started_at'] ?? null), $tz, 'g:i a');
                $todo = 'Send the report to whoever looks after the portal: they may need to restore the copy of your data saved'
                    .($saved === null ? ' before the update.' : " at $saved.");
                break;
            default:
                $title = "$update stopped before anything was changed.";
                $lines = [($from === null ? 'Your portal is working normally.' : "Your portal is on $from and working normally."), 'Nothing was lost.'];
                $failed = array_values(array_filter((array) ($h['checks_failed'] ?? []), 'is_string'));
                if ($failed !== []) {
                    $extra .= '<ul>'.implode('', array_map(static fn (string $l): string => '<li>'.self::e($l).'</li>', $failed)).'</ul>';
                }
                $todo = "You can try again later. $help";
                $tryAgain = true;
        }

        $body = '<h1>'.self::e($title).'</h1>';
        foreach ($lines as $line) {
            $body .= '<p>'.self::e($line).'</p>';
        }
        if ($outcome !== 'finished' && $outcome !== 'undone' && $reason !== null) {
            $body .= '<p><strong>What happened:</strong> '.self::e($reason).'</p>';
        }
        $body .= $extra;
        if ($todo !== null) {
            $body .= '<p><strong>What to do:</strong> '.self::e($todo).'</p>';
        }
        $report = is_string($h['report'] ?? null) ? $h['report'] : '';
        $body .= '<p class="actions">';
        if ($tryAgain) {
            $body .= '<a class="button" href="/admin/updates">Try again</a> ';
        }
        if ($showReport && $report !== '') {
            $body .= '<button type="button" class="button" id="copy-report">Copy report</button> '
                .'<a class="button" href="'.self::e(self::issueUrl(self::issueTitle($h), $report)).'" target="_blank" rel="noopener noreferrer">Report a problem</a> ';
        }
        $body .= '<a class="button" href="/home">Back to the dashboard</a></p>';
        if ($showReport && $report !== '') {
            $body .= '<p id="copied" role="status" aria-live="polite"></p>'
                .'<details><summary>Show details</summary><textarea id="report" readonly rows="14">'.self::e($report).'</textarea></details>'
                .'<script nonce="'.$nonce.'">'.self::COPY_JS.'</script>';
        } elseif ($outcome !== 'finished') {
            $body .= '<p class="muted">The full report is on the Updates page.</p>';
        }

        return self::screen($outcome === 'finished' ? 'Update done' : 'Update result', $body, $nonce);
    }

    /** @param array{journal: ?array<string, mixed>, error: string} $stuck */
    private static function stuckScreen(Context $c, array $stuck, string $nonce): string
    {
        $job = Auth::job($c->files->dir) ?? [];
        $from = is_string($job['from_version'] ?? null) ? $job['from_version'] : null;
        $journal = $stuck['journal'] ?? [];
        $oldDir = $journal['meta']['swap']['old_dir'] ?? null;
        if (! is_string($oldDir) && $from !== null) {
            try {
                $oldDir = Swap::oldDir($c->app, $from);
            } catch (\Throwable) {
                $oldDir = null;
            }
        }
        $where = is_string($oldDir) ? basename($oldDir) : '.clubportal-old-'.($from ?? '<version>');
        $place = is_string($oldDir) && str_starts_with($oldDir, $c->files->dir)
            ? "inside the portal's storage/app/updater folder"
            : "in the folder that holds the portal's folder";
        $log = [];
        foreach ((array) ($journal['steps'] ?? []) as $s) {
            if (is_array($s) && is_array($s['log'] ?? null)) {
                $log = array_merge($log, $s['log']);
            }
        }
        $report = Report::text([
            'outcome' => 'could not be undone',
            'from' => $from ?? '?',
            'to' => is_string($job['to_version'] ?? null) ? $job['to_version'] : '?',
            'step' => is_array($journal) ? (string) ($journal['meta']['swap']['state'] ?? '-') : 'journal unreadable',
            'reason' => $stuck['error'],
            'started_at' => is_int($job['created_at'] ?? null) ? $job['created_at'] : null,
            'at' => $c->now(),
            'log' => $log,
        ], $c->app);

        $body = '<h1>The update could not be undone by itself.</h1>'
            .'<p>Members see a page saying the portal is being updated and will be back in a few minutes. They cannot use the portal until this is fixed.</p>'
            .'<p>Your club\'s records and uploaded files are still on your site. The earlier version\'s program files are kept in a folder named <code>'.self::e($where).'</code> '.$place.'.</p>'
            .'<p><strong>What to do:</strong> send the report to whoever installed the portal, or to your host\'s support. Someone with access to the site\'s files needs to do this by hand:</p>'
            .'<ol><li>Open the folder <code>'.self::e($where).'</code> ('.$place.'). Move everything in it except the file <code>'.self::e(Unpack::OWNER).'</code> back into the portal\'s folder, to the same place, replacing what is there (what is in its <code>public</code> folder goes into the portal\'s <code>public</code> folder).</li>'
            .'<li>In the portal\'s <code>storage/framework</code> folder, delete the files <code>updating</code>, <code>down</code> and <code>maintenance.php</code>.</li>'
            .'<li>Open the portal\'s home page and check that it works.</li></ol>'
            .'<p class="actions"><button type="button" class="button" id="copy-report">Copy report</button> '
            .'<a class="button" href="'.self::e(self::issueUrl('Update could not be undone', $report)).'" target="_blank" rel="noopener noreferrer">Report a problem</a></p>'
            .'<p id="copied" role="status" aria-live="polite"></p>'
            .'<details><summary>Show details</summary><textarea id="report" readonly rows="14">'.self::e($report).'</textarea></details>'
            .'<script nonce="'.$nonce.'">'.self::COPY_JS.'</script>';

        return self::screen('Update needs help', $body, $nonce);
    }

    /** @param array<string, mixed> $job */
    private static function inProgress(Context $c, array $job, string $nonce): string
    {
        $to = self::version($job['to_version'] ?? null);
        $tz = is_string($job['timezone'] ?? null) ? $job['timezone'] : 'UTC';
        $when = self::time($job['created_at'] ?? null, $tz, 'g:i a');
        $body = '<h1>An update is in progress</h1>'
            .'<p>'.self::e('A super admin is updating the portal'.($to === null ? '' : " to $to").($when === null ? '' : " (started $when)").'.').'</p>';
        if (is_file($c->files->guardPath)) {
            $body .= '<p>Members see a "back in a few minutes" page until it is done.</p>';
        }
        $body .= '<p><strong>What to do:</strong> nothing. If the update stops, it is undone by itself within 10 minutes and the result shows on the Updates page.</p>'
            .'<p class="actions"><a class="button" href="/admin/updates">Go to the Updates page</a></p>';

        return self::screen('Update in progress', $body, $nonce);
    }

    // ---------------------------------------------------------------- report link

    /** The GitHub new-issue address with the report in the body, shortened so the whole address stays under MAX_URL. */
    public static function issueUrl(string $title, string $report): string
    {
        $base = self::ISSUE_URL.'?title='.rawurlencode($title).'&body=';
        $note = "\n[report shortened]";
        $body = $report;
        if (strlen($base.rawurlencode($body)) <= self::MAX_URL) {
            return $base.rawurlencode($body);
        }
        $length = mb_strlen($body);
        while ($length > 0 && strlen($base.rawurlencode(mb_substr($body, 0, $length).$note)) > self::MAX_URL) {
            $length = (int) floor($length * 0.9);
        }

        return $base.rawurlencode(mb_substr($body, 0, $length).$note);
    }

    /** @param array<string, mixed> $h */
    private static function issueTitle(array $h): string
    {
        $to = self::version($h['to'] ?? null) ?? 'a new version';

        return "Update to $to: ".str_replace('_', ' ', (string) ($h['outcome'] ?? 'problem'));
    }

    // ---------------------------------------------------------------- HTML

    private const MARKS = ['done' => '✓', 'running' => '●', 'pending' => '○', 'failed' => '✗'];

    /** @param array<string, string> $states */
    private static function rowState(string $row, array $states): string
    {
        $steps = $row === 'switch' ? ['swap', 'opcache'] : [$row];
        $all = array_map(static fn (string $s): ?string => $states[$s] ?? null, $steps);
        if (in_array('failed', $all, true)) {
            return 'failed';
        }
        if ($all === array_fill(0, count($all), 'done')) {
            return 'done';
        }

        return array_filter($all) === [] ? 'pending' : 'running';
    }

    private static function screen(string $title, string $body, string $nonce): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex">'
            .'<title>'.self::e($title).'</title><style nonce="'.$nonce.'">'.self::CSS.'</style></head>'
            .'<body><main>'.$body.'</main></body></html>';
    }

    /** @return array{status: int, headers: array<string, string>, body: string, cookies: list<array>} */
    private static function html(string $page, string $nonce): array
    {
        return ['status' => 200, 'headers' => self::headers('text/html; charset=UTF-8', $nonce), 'body' => $page, 'cookies' => []];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, value: string, expires: int}>  $cookies
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<array>}
     */
    private static function json(array $data, int $status = 200, array $cookies = []): array
    {
        return ['status' => $status, 'headers' => self::headers('application/json; charset=UTF-8', null),
            'body' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'cookies' => $cookies];
    }

    /** @return array<string, string> */
    private static function headers(string $type, ?string $nonce): array
    {
        $csp = $nonce === null
            ? "default-src 'none'; frame-ancestors 'none'"
            : "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

        return [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => $csp,
        ];
    }

    public static function isHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    /** A browser that sends Origin must send this site's own. */
    private static function sameOrigin(array $server): bool
    {
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (! is_string($origin) || $origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $self = strtolower((string) ($server['HTTP_HOST'] ?? ''));

        return is_string($host) && strtolower($host.($port !== null ? ':'.$port : '')) === $self;
    }

    private static function version(mixed $v): ?string
    {
        return is_string($v) && $v !== '' && $v !== '?' ? (str_starts_with($v, 'v') ? $v : 'v'.$v) : null;
    }

    private static function time(mixed $unix, string $tz, string $format): ?string
    {
        if (! is_int($unix)) {
            return null;
        }
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@'.$unix))->setTimezone($zone)->format($format);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function jsonForHtml(mixed $data): string
    {
        return (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    private const CSS = 'body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f6f8;color:#1f2933;line-height:1.5}'
        .'main{max-width:36rem;margin:6vh auto;padding:1.75rem 1.25rem;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
        .'h1{font-size:1.35rem;margin:0 0 .75rem}h2{font-size:1.05rem;margin:1rem 0 .25rem}p{margin:0 0 .75rem}'
        .'.muted{color:#52606d}.bold{font-weight:700}ol.steps{list-style:none;padding:0;margin:1rem 0}'
        .'ol.steps li{padding:.3rem 0}.mark{display:inline-block;width:1.4rem;text-align:center}'
        .'.state-done .mark{color:#1f7a3a}.state-running{font-weight:600}.state-running .mark{color:#1d4ed8}'
        .'.state-pending{color:#7b8794}.state-failed .mark{color:#b42318}#note{min-height:1.5rem;color:#1d4ed8}'
        .'.actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}'
        .'.button{display:inline-block;padding:.55rem .9rem;border-radius:8px;border:1px solid #cbd2d9;background:#fff;color:#1f2933;text-decoration:none;font:inherit;cursor:pointer}'
        .'details{margin-top:1rem}pre,textarea{width:100%;box-sizing:border-box;font:12px/1.4 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;background:#f4f6f8;border:1px solid #e4e7eb;border-radius:6px;padding:.5rem}'
        .'code{font-size:.9em}@media (prefers-color-scheme:dark){body{background:#111827;color:#e5e7eb}main{background:#1f2937}.button{background:#111827;color:#e5e7eb;border-color:#4b5563}pre,textarea{background:#111827;color:#e5e7eb;border-color:#374151}.muted,.state-pending{color:#9ca3af}}';

    private const COPY_JS = <<<'JS'
(function () {
  var button = document.getElementById('copy-report');
  var area = document.getElementById('report');
  var said = document.getElementById('copied');
  if (!button || !area) { return; }
  button.addEventListener('click', function () {
    function done() { said.textContent = 'Report copied.'; }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(area.value).then(done, fallback);
    } else {
      fallback();
    }
    function fallback() {
      area.closest('details').open = true;
      area.focus();
      area.select();
      try { document.execCommand('copy'); done(); } catch (e) { said.textContent = 'Select the text below and copy it.'; }
    }
  });
})();
JS;

    private const RUNNER_JS = <<<'JS'
(function () {
  'use strict';
  var state = JSON.parse(document.getElementById('state').textContent);
  var note = document.getElementById('note');
  var details = document.getElementById('details');
  var keep = document.getElementById('keep');
  var ROW = { swap: 'switch', opcache: 'switch' };
  var MARK = { done: '✓', running: '●', pending: '○', failed: '✗' };
  var RETRY_DELAYS = [5000, 10000, 15000];
  var FROM_SWITCH = ['swap', 'opcache', 'migrate', 'health'];
  var LOGIN_MARKER = new RegExp(state.loginMarker);
  var ended = false, inFlight = false, watching = false, stalled = false;
  var wakeLock = null, timer = null, watchTimer = null, hiddenAt = 0;

  function say(text) { note.textContent = text; }
  function log(line) { details.textContent += line + '\n'; }
  function mark(step, st) {
    if (step === 'swap' && st === 'done') { st = 'running'; }
    var li = document.querySelector('[data-row="' + (ROW[step] || step) + '"]');
    if (!li) { return; }
    li.className = 'state-' + st;
    li.querySelector('.mark').textContent = MARK[st];
  }
  function wait(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
  function post(step, body) {
    return fetch('?step=' + encodeURIComponent(step), {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-Updater': '1' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || typeof j.status !== 'string') { throw new Error('unexpected answer'); }
      return j;
    });
  }
  // A step whose request fails is retried 3 times over about 30 seconds.
  function attempt(step, body, n) {
    return post(step, body).catch(function (e) {
      if (n >= RETRY_DELAYS.length) { throw e; }
      say('Connection lost, retrying…');
      log(step + ': ' + (e && e.message ? e.message : 'no answer') + ', retrying');
      return wait(RETRY_DELAYS[n]).then(function () { return attempt(step, body, n + 1); });
    });
  }
  // The page's own requests to the portal: /up, and the sign-in page with the bypass cookie.
  function healthBody() {
    function probe(url, marker) {
      return fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow' }).then(function (r) {
        return r.text().then(function (t) { return { status: r.status, marker: marker ? marker.test(t) : true }; });
      }).catch(function () { return { status: 0, marker: false }; });
    }
    return probe('/up', null).then(function (up) {
      return probe('/login', LOGIN_MARKER).then(function (login) {
        return { up: up.status, login: login.status, login_marker: login.marker };
      });
    });
  }
  function end() {
    ended = true;
    if (wakeLock) { try { wakeLock.release(); } catch (e) {} }
  }
  function run(step) {
    if (ended || inFlight || !step) { return; }
    clearTimeout(timer);
    inFlight = true;
    stalled = false;
    mark(step, 'running');
    var body = step === 'health' ? healthBody() : Promise.resolve({});
    body.then(function (b) { return attempt(step, b, 0); }).then(function (j) {
      inFlight = false;
      handle(j);
    }, function (e) {
      inFlight = false;
      lost(step, e);
    });
  }
  function handle(j) {
    if (j.message) { log(j.step + ': ' + j.status + ' — ' + j.message); }
    if (j.detail) { log('  ' + j.detail); }
    if (j.swap_started) { state.swapStarted = true; }
    if (j.status === 'busy') { watch(); return; }
    if (j.status === 'denied' || (j.status === 'failed' && j.ended)) { end(); location.reload(); return; }
    if (j.status === 'failed') {
      // The update is still open (this step can be tried again): stop here rather than repeat it in a loop.
      mark(j.step, 'failed');
      end();
      say(j.message + ' Reload this page to try again.');
      return;
    }
    say(j.status === 'more' && j.message ? j.message : '');
    if (j.status === 'done') { mark(j.step, 'done'); }
    if (!j.next) {
      if (j.status === 'done') { end(); location.reload(); }
      return;
    }
    timer = setTimeout(function () { run(j.next); }, (j.wait || 0) * 1000);
  }
  function lost(step, e) {
    log(step + ': ' + (e && e.message ? e.message : 'no answer'));
    if (document.hidden) { stalled = true; return; } // carried on when the page is visible again
    if (state.swapStarted || FROM_SWITCH.indexOf(step) >= 0) {
      say('Connection lost. Putting the old version back…');
      inFlight = true;
      attempt('rollback', {}, 0).then(function (j) { inFlight = false; handle(j); }, function () {
        inFlight = false;
        stalled = true;
        say("We can't reach your site right now. Check your connection, then reload this page. If you can't, the portal puts the old version back by itself within 10 minutes.");
      });
      return;
    }
    stalled = true;
    say("We can't reach your site right now. Check your connection, then reload this page. If you can't, the update stops by itself within 10 minutes and nothing is changed.");
  }
  function refresh(then) {
    post('status', {}).then(function (s) {
      if (s.status === 'denied') { end(); location.reload(); return; }
      if (s.steps) { Object.keys(s.steps).forEach(function (k) { mark(k, s.steps[k]); }); }
      state.swapStarted = !!s.swap_started;
      then(s);
    }, function () {});
  }
  // Another tab holds the update: only watch, and carry on here if that tab has gone quiet.
  function watch() {
    if (watching) { return; }
    watching = true;
    say('This update is running in another tab.');
    watchTimer = setInterval(function () {
      refresh(function (s) {
        if (s.locked || s.idle < 30) { return; }
        clearInterval(watchTimer);
        watching = false;
        if (!s.next) { end(); location.reload(); return; }
        say('Carrying on…');
        run(s.next);
      });
    }, 5000);
  }
  function requestWake() {
    if (location.protocol !== 'https:' || !('wakeLock' in navigator)) { keep.classList.add('bold'); return; }
    navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }, function () { keep.classList.add('bold'); });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    if (ended) { return; }
    requestWake();
    if (inFlight || watching) { return; }
    if (stalled || Date.now() - hiddenAt > 3000) { say('Welcome back, carrying on…'); }
    refresh(function (s) {
      if (!s.next) { end(); location.reload(); return; }
      run(s.next);
    });
  });
  window.addEventListener('beforeunload', function (e) {
    if (ended) { return; }
    e.preventDefault();
    e.returnValue = '';
  });
  requestWake();
  if (state.next) { run(state.next); } else { end(); location.reload(); }
})();
JS;
}
