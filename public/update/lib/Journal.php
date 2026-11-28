<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/**
 * storage/app/updater/journal.json: which steps are done, the one running, and a few facts later steps need.
 * Every write is a temp file plus rename. All times are unix ints.
 */
final class Journal
{
    /** The fixed step order. */
    public const STEPS = ['prechecks', 'download', 'backup', 'unpack', 'maintenance', 'swap', 'opcache', 'migrate', 'health', 'finish'];

    /** A step running for longer than this is treated as interrupted and may be begun again. */
    public const INTERRUPTED_AFTER = 120;

    private const LOG_LINES = 50;

    /** @var callable(): int */
    private $clock;

    /** @param array<string, mixed> $data */
    private function __construct(private readonly string $path, private array $data, callable $clock)
    {
        $this->clock = $clock;
    }

    /** @param ?callable(): int $clock tests pass their own */
    public static function open(string $dir, ?callable $clock = null): self
    {
        $clock ??= static fn (): int => time();
        $path = rtrim($dir, '/\\').'/journal.json';
        $data = ['steps' => [], 'meta' => [], 'recovering' => false, 'last_step_at' => null];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $read = $raw === false ? null : json_decode($raw, true);
            if (! is_array($read)) {
                throw new UpdaterError('The update journal cannot be read.');
            }
            $data = array_merge($data, $read);
        } elseif (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new UpdaterError('The updater folder cannot be created.');
        }

        return new self($path, $data, $clock);
    }

    /** @return array<string, array{state: string, started_at: int, finished_at: ?int, detail: mixed, log: list<string>}> */
    public function steps(): array
    {
        return $this->data['steps'];
    }

    public function begin(string $step): void
    {
        $this->assertKnown($step);
        if ($this->isRecovering()) {
            throw new UpdaterError('The update is being undone; no further step can start.');
        }
        foreach (self::STEPS as $earlier) {
            if ($earlier === $step) {
                break;
            }
            if (($this->data['steps'][$earlier]['state'] ?? null) !== 'done') {
                throw new UpdaterError("Step '$step' cannot start before '$earlier' is done.");
            }
        }
        $now = $this->now();
        $current = $this->data['steps'][$step] ?? null;
        if ($current !== null && $current['state'] === 'done') {
            throw new UpdaterError("Step '$step' is already done.");
        }
        if ($current !== null && $current['state'] === 'running' && $now - $current['started_at'] <= self::INTERRUPTED_AFTER) {
            throw new UpdaterError("Step '$step' is still running.");
        }
        $this->data['steps'][$step] = [
            'state' => 'running',
            'started_at' => $now,
            'finished_at' => null,
            'detail' => null,
            'log' => $current['log'] ?? [],
        ];
        $this->data['last_step_at'] = $now;
        $this->save();
    }

    public function finish(string $step, mixed $detail = null): void
    {
        $this->mustBeRunning($step);
        $now = $this->now();
        $this->data['steps'][$step]['state'] = 'done';
        $this->data['steps'][$step]['finished_at'] = $now;
        $this->data['steps'][$step]['detail'] = $detail;
        $this->data['last_step_at'] = $now;
        $this->save();
    }

    public function fail(string $step, string $message): void
    {
        $this->assertKnown($step);
        $now = $this->now();
        $entry = $this->data['steps'][$step] ?? ['state' => 'running', 'started_at' => $now, 'finished_at' => null, 'detail' => null, 'log' => []];
        $entry['state'] = 'failed';
        $entry['finished_at'] = $now;
        $entry['detail'] = $message;
        $this->data['steps'][$step] = $entry;
        $this->data['last_step_at'] = $now;
        $this->save();
    }

    /** Adds a line to the step's log (the report shows the last lines). Does not move the idle clock. */
    public function log(string $step, string $line): void
    {
        $this->mustExist($step);
        $log = $this->data['steps'][$step]['log'];
        $log[] = $line;
        $this->data['steps'][$step]['log'] = array_slice($log, -self::LOG_LINES);
        $this->save();
    }

    public function set(string $key, mixed $value): void
    {
        $this->data['meta'][$key] = $value;
        $this->save();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data['meta'][$key] ?? $default;
    }

    /** Marks progress: the 10-minute idle clock restarts. */
    public function touch(): void
    {
        $this->data['last_step_at'] = $this->now();
        $this->save();
    }

    /** Seconds since the last progress; 0 when nothing has run yet. */
    public function idleSeconds(): int
    {
        $last = $this->data['last_step_at'] ?? null;

        return is_int($last) ? max(0, $this->now() - $last) : 0;
    }

    /** True once the swap step has begun: from here on a failure needs the files put back. */
    public function isSwapStarted(): bool
    {
        return isset($this->data['steps']['swap']);
    }

    public function isRecovering(): bool
    {
        return ($this->data['recovering'] ?? false) === true;
    }

    public function markRecovering(): void
    {
        $this->data['recovering'] = true;
        $this->save();
    }

    /** The first step in the fixed order that is not done (failed and interrupted ones included); null when all are. */
    public function firstUnfinished(): ?string
    {
        foreach (self::STEPS as $step) {
            if (($this->data['steps'][$step]['state'] ?? null) !== 'done') {
                return $step;
            }
        }

        return null;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function assertKnown(string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new UpdaterError("Unknown update step '$step'.");
        }
    }

    private function mustExist(string $step): void
    {
        $this->assertKnown($step);
        if (! isset($this->data['steps'][$step])) {
            throw new UpdaterError("Step '$step' has not started.");
        }
    }

    private function mustBeRunning(string $step): void
    {
        $this->mustExist($step);
        if ($this->data['steps'][$step]['state'] !== 'running') {
            throw new UpdaterError("Step '$step' is not running.");
        }
    }

    /** Temp file in the same folder, then rename: a reader sees the old or the new file, never half of one. */
    private function save(): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if ($json === false || file_put_contents($tmp, $json."\n") !== strlen($json) + 1) {
            @unlink($tmp);
            throw new UpdaterError('The update journal cannot be written (disk full or folder not writable).');
        }
        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new UpdaterError('The update journal cannot be saved.');
        }
    }
}
