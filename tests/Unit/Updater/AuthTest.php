<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Auth;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class AuthTest extends TestCase
{
    use Support;

    private string $dir;

    private string $token;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->token = bin2hex(random_bytes(32));
    }

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function job(array $over = []): void
    {
        file_put_contents($this->dir.'/job.json', json_encode($over + [
            'status' => 'ready',
            'token_hash' => hash('sha256', $this->token),
            'last_step_at' => time(),
            'expires_at' => time() + 1800,
        ]));
    }

    public function test_the_token_holder_of_a_ready_or_running_job_passes(): void
    {
        $this->job();
        $this->assertTrue(Auth::check($this->dir, $this->token));
        $this->job(['status' => 'running']);
        $this->assertTrue(Auth::check($this->dir, $this->token));
    }

    public function test_a_wrong_or_malformed_token_fails(): void
    {
        $this->job();
        $this->assertFalse(Auth::check($this->dir, bin2hex(random_bytes(32))));
        $this->assertFalse(Auth::check($this->dir, strtoupper($this->token)));
        $this->assertFalse(Auth::check($this->dir, hash('sha256', $this->token)), 'the stored hash is not the token');
        $this->assertFalse(Auth::check($this->dir, ''));
        $this->assertFalse(Auth::check($this->dir, null));
    }

    public function test_a_job_30_minutes_after_its_last_step_has_expired(): void
    {
        $this->job(['last_step_at' => time() - 1801]);
        $this->assertFalse(Auth::check($this->dir, $this->token));
        $this->job(['last_step_at' => time() - 1700]);
        $this->assertTrue(Auth::check($this->dir, $this->token));
    }

    public function test_an_ended_job_fails(): void
    {
        foreach (['finished', 'undone', 'failed', 'rolled_back'] as $status) {
            $this->job(['status' => $status]);
            $this->assertFalse(Auth::check($this->dir, $this->token), $status);
            $this->assertTrue(Auth::isTokenHolder($this->dir, $this->token), "$status: still the token holder (sees the report)");
        }
    }

    public function test_no_job_or_an_unreadable_one_fails(): void
    {
        $this->assertFalse(Auth::check($this->dir, $this->token));
        file_put_contents($this->dir.'/job.json', '{nope');
        $this->assertFalse(Auth::check($this->dir, $this->token));
        $this->assertFalse(Auth::isTokenHolder($this->dir, $this->token));
    }
}
