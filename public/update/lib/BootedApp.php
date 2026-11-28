<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

use App\Support\Updates\Manifest;
use App\Support\Updates\PausePage;
use App\Support\Updates\PreChecks;
use App\Support\Updates\UpdaterHost;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * The installed Laravel app, booted in this process on purpose: the old code in `prechecks` and `backup`, the new code
 * in `migrate` and the boot part of `health`. Nothing else in the updater touches Laravel, the database or mail.
 */
interface BootedApp
{
    /** Runs an artisan command. @return array{code: int, output: string} */
    public function call(string $command, array $parameters = []): array;

    /**
     * The update's pre-checks (spec section 3, all of them) through PreChecks::run($m, true), without the "no other
     * update is running" row (this update is the one running), with the job's ticks for the two "done by a person" rows.
     *
     * @param  array<string, mixed>  $job
     * @return list<string> one plain line per failing check; empty when the update may go on
     */
    public function preChecks(array $job): array;

    /** The members' pause page, from App\Support\Updates\PausePage::render. */
    public function pausePage(string $locale, string $portalName): string;

    /** Null when this app is release $version and can reach its database, else the reason it is not working. */
    public function health(string $version): ?string;
}

/** The production BootedApp: Laravel's console kernel, bootstrapped from the given folder. */
final class LaravelApp implements BootedApp
{
    private function __construct(private readonly object $kernel) {}

    /** An app whose console kernel is already bootstrapped (the feature tests use the running one). */
    public static function forKernel(object $kernel): self
    {
        return new self($kernel);
    }

    public static function boot(string $app): self
    {
        $app = rtrim($app, '/\\');
        require_once $app.'/vendor/autoload.php';
        $laravel = require $app.'/bootstrap/app.php';
        $kernel = $laravel->make(Kernel::class);
        $kernel->bootstrap();

        return new self($kernel);
    }

    public function call(string $command, array $parameters = []): array
    {
        $code = (int) $this->kernel->call($command, $parameters);

        return ['code' => $code, 'output' => (string) $this->kernel->output()];
    }

    public function preChecks(array $job): array
    {
        $manifest = Manifest::fromJson((string) json_encode($job['manifest'] ?? null));
        $checks = array_values(array_filter(
            PreChecks::run($manifest, true),
            static fn ($c): bool => $c->key !== 'no_update_running',
        ));
        $dbTicked = ($job['db_backup_ticked'] ?? false) === true;
        $manualTicked = ($job['manual_ticked'] ?? false) === true;
        if (PreChecks::canStart($checks, $dbTicked, $manualTicked)) {
            return [];
        }
        $failed = [];
        foreach ($checks as $c) {
            $ticked = ($c->key === 'backup_database' && $dbTicked) || ($c->key === 'manual_action' && $manualTicked);
            if (! $c->ok && ($c->blocking || ! $ticked)) {
                $failed[] = $c->label.': '.$c->message;
            }
        }

        return $failed;
    }

    public function pausePage(string $locale, string $portalName): string
    {
        return PausePage::render($locale, $portalName);
    }

    public function health(string $version): ?string
    {
        $installed = (new UpdaterHost)->installedVersion();
        if (ltrim($installed, 'vV') !== ltrim($version, 'vV')) {
            return "The new version reports itself as $installed, not $version.";
        }
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            return 'The new version cannot reach the database: '.$e->getMessage();
        }

        return null;
    }
}
