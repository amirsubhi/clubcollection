<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * How an update attempt ended, written by code that runs without Laravel: storage/app/updater/outcome.json (consumed
 * once by the next request that boots Laravel) and the history file the Updates page lists.
 */
final class Outcome
{
    /** outcome.json / history `outcome` values (constraints, Stage 2 rules). */
    public const OUTCOMES = ['finished', 'rolled_back', 'undone', 'failed', 'blocked'];

    private const KEYS = ['outcome', 'from', 'to', 'step', 'reason', 'at', 'started_at', 'database_in_backup', 'report', 'whats_new'];

    /**
     * Writes history/<YmdHis>-<from>-<to>.json first (YmdHis is started_at, or at, in UTC: one attempt keeps one file
     * even when it is written again), then outcome.json. Extra keys (e.g. `steps`) go to the history only; `log` lines
     * go only into the report, which Report::text cleans.
     *
     * A `blocked` outcome also writes the `blocked` flag file (the Update button is switched off) before outcome.json.
     *
     * @param  array<string, mixed>  $o  outcome, from, to, step, reason, at, started_at, database_in_backup, plus
     *                                   optional log, php_version, prechecks_failed and extra history keys
     */
    public static function write(string $updaterDir, array $o): void
    {
        $outcome = $o['outcome'] ?? null;
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new UpdaterError('Unknown update outcome.');
        }
        $updaterDir = rtrim($updaterDir, '/\\');
        $app = dirname($updaterDir, 3); // <app>/storage/app/updater
        $at = is_int($o['at'] ?? null) ? $o['at'] : time();
        $startedAt = is_int($o['started_at'] ?? null) ? $o['started_at'] : null;

        $out = [
            'outcome' => $outcome,
            'from' => self::text($o['from'] ?? null),
            'to' => self::text($o['to'] ?? null),
            'step' => isset($o['step']) && is_string($o['step']) ? $o['step'] : null,
            'reason' => isset($o['reason']) && is_string($o['reason']) ? $o['reason'] : null,
            'at' => $at,
            'started_at' => $startedAt,
            // false: the backup step could not copy the database on this host (the cPanel backup is the copy)
            'database_in_backup' => is_bool($o['database_in_backup'] ?? null) ? $o['database_in_backup'] : null,
        ];
        $whatsNew = [];
        foreach (is_array($o['whats_new'] ?? null) ? $o['whats_new'] : [] as $line) {
            if (is_string($line) && trim($line) !== '' && count($whatsNew) < 5) {
                $whatsNew[] = trim($line);
            }
        }
        if ($whatsNew !== []) {
            $out['whats_new'] = $whatsNew;
        }
        $out['report'] = Report::text($out + [
            'log' => is_array($o['log'] ?? null) ? array_values($o['log']) : [],
            'php_version' => is_string($o['php_version'] ?? null) ? $o['php_version'] : PHP_VERSION,
            'prechecks_failed' => is_array($o['prechecks_failed'] ?? null) ? array_values($o['prechecks_failed']) : [],
        ], $app);

        $extra = array_diff_key($o, array_flip(array_merge(self::KEYS, ['log', 'php_version', 'prechecks_failed'])));
        $history = $out + $extra;

        $historyDir = $updaterDir.'/history';
        if (! is_dir($historyDir) && ! @mkdir($historyDir, 0755, true) && ! is_dir($historyDir)) {
            throw new UpdaterError('The update history folder cannot be created.');
        }
        $name = gmdate('YmdHis', $startedAt ?? $at).'-'.self::safe($out['from']).'-'.self::safe($out['to']).'.json';
        Files::writeAtomic($historyDir.'/'.$name, self::json($history));
        if ($outcome === 'blocked') {
            // Switched off now, not when Laravel takes the outcome: another update must never start on a half-changed
            // database (the same file and shape Laravel writes, App\Support\Updates\UpdateOutcomes).
            Files::writeAtomic($updaterDir.'/blocked', json_encode(['at' => $at, 'reason' => $out['reason']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
        Files::writeAtomic($updaterDir.'/outcome.json', self::json($out));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '?';
    }

    /** A version for a file name: only letters, digits, dots, plus and dashes; never a path. */
    private static function safe(string $version): string
    {
        $clean = trim((string) preg_replace('/[^0-9A-Za-z.+]+/', '-', $version), '.-');
        $clean = (string) preg_replace('/\.{2,}/', '.', $clean);

        return $clean === '' ? 'unknown' : substr($clean, 0, 40);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new UpdaterError('The update outcome cannot be encoded.');
        }

        return $json."\n";
    }
}
