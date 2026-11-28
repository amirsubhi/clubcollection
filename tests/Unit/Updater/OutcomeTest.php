<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Outcome;
use ClubPortalUpdater\UpdaterError;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class OutcomeTest extends TestCase
{
    use Support;

    private string $app;

    private string $dir;

    protected function setUp(): void
    {
        $this->app = $this->tempDir();
        $this->dir = $this->app.'/storage/app/updater';
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function sample(array $extra = []): array
    {
        return $extra + [
            'outcome' => 'undone',
            'from' => '2.2.0',
            'to' => '2.2.1',
            'step' => 'migrate',
            'reason' => 'The update page was left.',
            'at' => 1764300000,
            'started_at' => 1764299000,
            'log' => ['moved '.$this->app.'/vendor aside'],
        ];
    }

    public function test_it_writes_the_outcome_with_the_fixed_keys_and_a_report(): void
    {
        Outcome::write($this->dir, $this->sample());

        $o = json_decode((string) file_get_contents($this->dir.'/outcome.json'), true);
        $this->assertSame(['outcome', 'from', 'to', 'step', 'reason', 'at', 'started_at', 'report'], array_keys($o));
        $this->assertSame('undone', $o['outcome']);
        $this->assertSame(1764300000, $o['at']);
        $this->assertStringContainsString('Outcome: undone', $o['report']);
        $this->assertStringContainsString('moved <app>/vendor aside', $o['report']);
        $this->assertStringNotContainsString($this->app, (string) file_get_contents($this->dir.'/outcome.json'));
        $this->assertSame([], glob($this->dir.'/*.tmp'));
    }

    public function test_it_writes_the_history_file_named_by_start_time_and_versions(): void
    {
        Outcome::write($this->dir, $this->sample(['steps' => ['download' => ['state' => 'done']]]));

        $file = $this->dir.'/history/'.gmdate('YmdHis', 1764299000).'-2.2.0-2.2.1.json';
        $this->assertFileExists($file);
        $h = json_decode((string) file_get_contents($file), true);
        $this->assertSame('undone', $h['outcome']);
        $this->assertSame(['download' => ['state' => 'done']], $h['steps']);
        $this->assertArrayNotHasKey('log', $h);
        $this->assertSame(json_decode((string) file_get_contents($this->dir.'/outcome.json'), true)['report'], $h['report']);
    }

    public function test_odd_version_strings_never_leave_the_history_folder(): void
    {
        Outcome::write($this->dir, $this->sample(['from' => '../../x', 'to' => "2.2.1/../\0y"]));

        $files = glob($this->dir.'/history/*.json');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('..', basename($files[0]));
        $this->assertSame([], glob($this->app.'/storage/*.json'));
    }

    public function test_an_unknown_outcome_is_refused(): void
    {
        $this->expectException(UpdaterError::class);
        Outcome::write($this->dir, $this->sample(['outcome' => 'maybe']));
    }
}
