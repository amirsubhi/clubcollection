<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Report;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class ReportTest extends TestCase
{
    use Support;

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    public function test_report_shows_the_facts_and_hides_paths_and_secrets(): void
    {
        $app = $this->tempDir();
        $token = str_repeat('ab12', 16);
        $text = Report::text([
            'outcome' => 'rolled_back',
            'from' => '2.2.0',
            'to' => '2.3.0',
            'step' => 'migrate',
            'reason' => "Migration failed in $app/database/migrations/x.php",
            'at' => 1_800_000_060,
            'started_at' => 1_800_000_000,
            'php_version' => '8.4.1',
            'prechecks_failed' => ['The PHP zip extension is off'],
            'log' => ["Reading $app/.env", 'APP_KEY=base64:SECRETKEYVALUE', 'DB_PASSWORD=hunter2', "cookie: $token", 'Migrating: 2026_01_01_x', 'my_secret: abc', 'auth_token = zzz'],
        ], $app);

        $this->assertStringContainsString('Outcome: rolled_back', $text);
        $this->assertStringContainsString('2.2.0', $text);
        $this->assertStringContainsString('2.3.0', $text);
        $this->assertStringContainsString('PHP version: 8.4.1', $text);
        $this->assertStringContainsString('Step: migrate', $text);
        $this->assertStringContainsString('The PHP zip extension is off', $text);
        $this->assertStringContainsString('Migrating: 2026_01_01_x', $text);
        $this->assertStringContainsString('<app>/database/migrations/x.php', $text);
        $this->assertStringContainsString('Reading <app>/.env', $text);

        $this->assertStringNotContainsString($app, $text);
        foreach (['APP_KEY', 'SECRETKEYVALUE', 'DB_PASSWORD', 'hunter2', $token, 'zzz', 'abc'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    public function test_more_secret_shapes_are_dropped_and_checksums_survive(): void
    {
        $a = str_repeat('a1', 32);
        $b = str_repeat('b2', 32);
        $text = Report::text(['outcome' => 'failed', 'log' => [
            '{"secret": "s3cr3tvalue"}',
            '{"password":"pw-value"}',
            "'token' : 'tok-value'",
            'Authorization: Basic dXNlcjpwYXNz',
            'Authorization: Bearer abc.def.ghi',
            'Cookie: laravel_maintenance=eyJleHBpcmVz',
            'Set-Cookie: session=zzz',
            'connecting to mysql://dbuser:dbpass@localhost/club',
            'key is base64:QUJDREVGRw==',
            "sha256 mismatch: expected $a got $b",
            'kept line',
        ]], '/nowhere');

        foreach (['s3cr3tvalue', 'pw-value', 'tok-value', 'dXNlcjpwYXNz', 'abc.def.ghi', 'laravel_maintenance', 'session=zzz', 'dbpass', 'QUJDREVGRw'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }
        $this->assertStringContainsString("sha256 mismatch: expected $a got $b", $text);
        $this->assertStringContainsString('kept line', $text);
    }

    public function test_the_realpath_of_the_app_is_hidden_too(): void
    {
        $real = $this->tempDir();
        $link = $real.'-link';
        symlink($real, $link);
        try {
            $text = Report::text(['outcome' => 'failed', 'log' => ["in $real/x and $link/y"]], $link);
            $this->assertStringNotContainsString($real, $text);
            $this->assertStringContainsString('<app>/x and <app>/y', $text);
        } finally {
            unlink($link);
        }
    }

    public function test_only_the_last_log_lines_are_kept_and_a_bare_history_still_works(): void
    {
        $log = [];
        for ($i = 1; $i <= 30; $i++) {
            $log[] = "line $i";
        }
        $text = Report::text(['outcome' => 'finished', 'log' => $log], '/nowhere');

        $this->assertStringContainsString('line 30', $text);
        $this->assertStringContainsString('line 11', $text);
        $this->assertStringNotContainsString('line 10'."\n", $text);

        $this->assertStringContainsString('Outcome: finished', Report::text(['outcome' => 'finished'], '/nowhere'));
    }
}
