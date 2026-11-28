<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\BootedApp;
use ClubPortalUpdater\Context;
use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\Steps;

/**
 * An installed app (v2.2.0) in a temp folder with a real release-files.json, a release zip for v2.2.1, a `ready` job and
 * a Context whose Laravel boot, download and PHP cache are fakes. The steps then run for real against these files.
 */
trait UpdateRunFixture
{
    use Support;

    protected string $root;

    protected string $site;

    protected string $dir;

    protected string $zip;

    protected string $token;

    protected FakeBootedApp $laravel;

    /** @var array<string, string> */
    protected array $oldFiles = [
        'artisan' => "#!/usr/bin/env php\nold\n",
        'app/A.php' => '<?php // old A',
        'bootstrap/app.php' => '<?php // old boot',
        'public/index.php' => '<?php // old index',
        'public/update-guard.php' => '<?php // old guard',
        'public/update/index.php' => '<?php // old updater',
        'public/update/lib/load.php' => '<?php // old lib',
    ];

    /** @var array<string, string> */
    protected array $newFiles = [
        'artisan' => "#!/usr/bin/env php\nnew\n",
        'app/A.php' => '<?php // new A',
        'app/B.php' => '<?php // new B',
        'bootstrap/app.php' => '<?php // new boot',
        'public/index.php' => '<?php // new index',
        'public/update-guard.php' => '<?php // new guard',
        'public/update/index.php' => '<?php // new updater',
        'public/update/lib/load.php' => '<?php // new lib',
    ];

    protected function makeSite(): void
    {
        $this->root = $this->tempDir();
        $this->site = $this->root.'/site';
        $this->dir = $this->site.'/storage/app/updater';
        $stub = (string) file_get_contents(dirname(__DIR__, 3).'/'.Maintenance::STUB);
        $this->oldFiles[Maintenance::STUB] = $stub;
        $this->newFiles[Maintenance::STUB] = $stub;
        $hashes = [];
        foreach ($this->oldFiles as $rel => $body) {
            $this->writeFile($this->site.'/'.$rel, $body);
            $hashes[$rel] = hash('sha256', $body);
        }
        $this->writeFile($this->site.'/release-files.json', (string) json_encode(['format' => 1, 'version' => '2.2.0', 'files' => $hashes]));
        mkdir($this->dir, 0755, true);
        mkdir($this->site.'/storage/framework', 0755, true);
        $this->zip = $this->makeZip($this->root.'/source.zip', $this->newFiles, 'clubportal-2.2.1');
        $this->laravel = new FakeBootedApp;
        $this->token = bin2hex(random_bytes(32));
        $this->writeReadyJob();
    }

    protected function writeReadyJob(array $over = []): void
    {
        $now = time();
        file_put_contents($this->dir.'/job.json', (string) json_encode($over + [
            'manifest' => [
                'version' => '2.2.1',
                'zip' => ['url' => Steps::ZIP_URL_PREFIX.'v2.2.1/clubportal-2.2.1.zip', 'size' => filesize($this->zip), 'sha256' => hash_file('sha256', $this->zip)],
                'whats_new' => ['Receipts now show the club\'s logo'],
            ],
            'token_hash' => hash('sha256', $this->token),
            'user_id' => 1,
            'user_name' => 'Aminah',
            'created_at' => $now - 5,
            'last_step_at' => $now - 5,
            'expires_at' => $now + 1795,
            'status' => 'ready',
            'db_backup_ticked' => false,
            'manual_ticked' => false,
            'from_version' => '2.2.0',
            'to_version' => '2.2.1',
            'timezone' => 'Asia/Kuala_Lumpur',
            'locale' => 'ms',
            'portal_name' => 'Kelab Sukan',
        ]));
    }

    protected function context(): Context
    {
        $c = new Context($this->site);
        $c->boot = fn (string $app): BootedApp => $this->laravel->booted($app);
        $c->fetch = function (string $url, string $dest, int $seconds): array {
            copy($this->zip, $dest);

            return ['done' => true, 'bytes' => (int) filesize($dest)];
        };
        $c->opcacheReset = static fn (): bool => true;
        $c->opcacheWait = static fn (): ?int => null;
        $c->input = ['up' => 200, 'login' => 200, 'login_marker' => true];

        return $c;
    }

    /**
     * Runs steps in order as the page would, up to (not including) $stopBefore, or until one fails.
     *
     * @return array<string, mixed> the last answer
     */
    protected function runSteps(Context $c, ?string $stopBefore = null): array
    {
        $next = 'prechecks';
        $last = [];
        for ($i = 0; $i < 100 && $next !== null && $next !== $stopBefore; $i++) {
            $last = Steps::run($next, $c);
            if (! in_array($last['status'], ['done', 'more'], true)) {
                return $last;
            }
            $next = $last['next'];
        }

        return $last;
    }

    protected function job(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/job.json'), true);
    }

    protected function outcome(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/outcome.json'), true);
    }

    protected function journal(): Journal
    {
        return Journal::open($this->dir);
    }

    protected function read(string $rel): string
    {
        return (string) @file_get_contents($this->site.'/'.$rel);
    }

    protected function assertOldSite(): void
    {
        foreach ($this->oldFiles as $rel => $body) {
            $this->assertSame($body, $this->read($rel), "$rel is the old file");
        }
        $this->assertFileDoesNotExist($this->site.'/app/B.php');
    }

    protected function assertNotPaused(): void
    {
        foreach (['updating', 'down', 'maintenance.php'] as $name) {
            $this->assertFileDoesNotExist($this->site.'/storage/framework/'.$name, "$name is removed");
        }
    }

    protected function assertPlain(string $text): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(swap|migrate|unpack|opcache|journal|maintenance mode)\b/i', $text);
    }

    private function writeFile(string $path, string $body): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $body);
    }
}

/** A Laravel app that records what the steps ask of it. */
final class FakeBootedApp implements BootedApp
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, int> exit code per command */
    public array $codes = [];

    /** @var list<string> */
    public array $failedChecks = [];

    public ?string $healthProblem = null;

    public int $boots = 0;

    public function booted(string $app): self
    {
        $this->boots++;

        return $this;
    }

    public function call(string $command, array $parameters = []): array
    {
        $this->calls[] = trim($command.' '.implode(' ', array_keys(array_filter($parameters))));
        $code = $this->codes[$command] ?? 0;
        $output = match ($command) {
            'backup:run' => $code === 0
                ? "Backup written: storage/app/backups/kelab-20261128-213000.zip (0.1 MB, database: database.sqlite, 0 file(s)).\n"
                : "Backup failed: disk full\n",
            'migrate' => $code === 0 ? "INFO  Running migrations.\n" : "SQLSTATE[42S21]: Column already exists\n",
            default => "done\n",
        };

        return ['code' => $code, 'output' => $output];
    }

    public function preChecks(array $job): array
    {
        $this->calls[] = 'prechecks';

        return $this->failedChecks;
    }

    public function pausePage(string $locale, string $portalName): string
    {
        return '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="30;url=/"></head><body>'.$portalName.' sedang dikemas kini ('.$locale.')</body></html>';
    }

    public function health(string $version): ?string
    {
        $this->calls[] = 'health '.$version;

        return $this->healthProblem;
    }
}
