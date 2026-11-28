<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Journal;
use ClubPortalUpdater\Maintenance;
use ClubPortalUpdater\Swap;

/**
 * A small installed app in a temp folder, an update run up to a chosen step with the journal's clock set in the past,
 * so recovery and the swap guard can be tested against real files.
 */
trait RecoveryFixture
{
    use Support;

    protected string $root;

    protected string $site;

    protected string $staging;

    protected string $oldDir;

    protected string $dir;

    /** @var array<string, string> */
    protected array $oldTree = [
        'artisan' => "#!/usr/bin/env php\nold\n",
        'release-files.json' => '{"old":true}',
        'app/A.php' => '<?php // old A',
        'bootstrap/app.php' => '<?php // old boot',
        'public/index.php' => '<?php // old index',
    ];

    /** @var array<string, string> */
    protected array $newTree = [
        'artisan' => "#!/usr/bin/env php\nnew\n",
        'release-files.json' => '{"new":true}',
        'app/A.php' => '<?php // new A',
        'app/B.php' => '<?php // new B',
        'bootstrap/app.php' => '<?php // new boot',
        'public/index.php' => '<?php // new index',
    ];

    protected function makeApp(): void
    {
        $this->root = $this->tempDir();
        $this->site = $this->root.'/site';
        $this->staging = $this->root.'/.clubportal-update-2.2.1';
        $this->oldDir = $this->root.'/.clubportal-old-2.2.0';
        $this->dir = $this->site.'/storage/app/updater';
        $this->writeTree($this->site, $this->oldTree);
        $this->writeTree($this->staging, $this->newTree);
        mkdir($this->dir, 0755, true);
        mkdir($this->site.'/storage/framework', 0755, true);
        $stub = dirname(__DIR__, 3).'/'.Maintenance::STUB;
        mkdir(dirname($this->site.'/'.Maintenance::STUB), 0755, true);
        copy($stub, $this->site.'/'.Maintenance::STUB);
    }

    /** @param array<string, string> $files */
    protected function writeTree(string $dir, array $files): void
    {
        foreach ($files as $rel => $body) {
            if (! is_dir(dirname("$dir/$rel"))) {
                mkdir(dirname("$dir/$rel"), 0755, true);
            }
            file_put_contents("$dir/$rel", $body);
        }
    }

    protected function writeJob(array $over = []): void
    {
        file_put_contents($this->dir.'/job.json', json_encode($over + [
            'status' => 'running',
            'token_hash' => hash('sha256', 'x'),
            'created_at' => time() - 1200,
            'last_step_at' => time() - 660,
            'expires_at' => time() + 600,
            'from_version' => '2.2.0',
            'to_version' => '2.2.1',
        ]));
    }

    protected function job(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/job.json'), true);
    }

    /**
     * Runs the update with a clock $ago seconds in the past: every step before $stopAt done, and from `maintenance` on
     * the real maintenance files written. With $stopAt after `swap` the files are really switched.
     */
    protected function runUpdateUntil(string $stopAt, int $ago): string
    {
        $j = Journal::open($this->dir, static fn (): int => time() - $ago);
        $secret = Maintenance::newSecret();
        foreach (Journal::STEPS as $step) {
            if ($step === $stopAt) {
                $j->begin($step);
                break;
            }
            $j->begin($step);
            if ($step === 'maintenance') {
                Maintenance::on($this->site, $secret, '<html>paused</html>');
            }
            if ($step === 'swap') {
                $plan = Swap::plan($this->site, $this->staging, $this->paths($this->oldTree), $this->paths($this->newTree));
                Swap::run($j, $plan, $this->oldDir);
            }
            $j->finish($step);
        }
        if (is_file($this->site.'/storage/framework/updating')) {
            touch($this->site.'/storage/framework/updating', time() - $ago);
        }

        return $secret;
    }

    /** @return array<string, string> */
    private function paths(array $tree): array
    {
        $out = [];
        foreach ($tree as $rel => $body) {
            if ($rel !== 'release-files.json') {
                $out[$rel] = hash('sha256', $body);
            }
        }

        return $out;
    }

    protected function read(string $rel): string
    {
        return (string) file_get_contents($this->site.'/'.$rel);
    }

    protected function assertOldTree(): void
    {
        foreach ($this->oldTree as $rel => $body) {
            $this->assertSame($body, $this->read($rel), "$rel is the old file again");
        }
        $this->assertFileDoesNotExist($this->site.'/app/B.php');
    }

    protected function assertMaintenanceGone(): void
    {
        foreach (['updating', 'down', 'maintenance.php'] as $name) {
            $this->assertFileDoesNotExist($this->site.'/storage/framework/'.$name, "$name is removed");
        }
    }
}
