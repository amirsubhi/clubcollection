<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Support\Doctor\HostProbe;
use App\Support\Payments\WebhookSecret;
use App\Support\Requirements;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2: one read-only command that tells the person installing the portal
 * whether this host is ready to go live, in plain English, with a one-line
 * fix for each problem. Exit code 1 when anything FAILs.
 *
 * Outside APP_ENV=production, checks that only matter on the live site
 * (debug off, https, upload limits, the cron job...) show as WARN, not FAIL.
 * Never prints a secret: keys and secrets are only reported as set or not.
 */
#[Signature('app:doctor {--json : Print the result as JSON instead of a table}')]
#[Description('Check that this server is ready to run the portal (read-only)')]
class AppDoctor extends Command
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** @var list<array{status: string, check: string, result: string, fix: string}> */
    private array $rows = [];

    private bool $production = false;

    public function handle(HostProbe $probe): int
    {
        $this->production = app()->environment('production');

        $this->checkPhp($probe);
        $this->checkAppSettings();
        $this->checkPhpLimits($probe);
        $this->checkFiles();
        $clubs = $this->checkDatabase();
        $this->checkGateways($clubs);
        $this->checkMail();
        $this->checkScheduler();
        $this->checkBackups();

        $counts = collect($this->rows)->countBy('status');
        $fails = (int) ($counts[self::FAIL] ?? 0);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'environment' => (string) config('app.env'),
                'version' => (string) config('app.version'),
                'ok' => $fails === 0,
                'summary' => ['pass' => (int) ($counts[self::PASS] ?? 0), 'warn' => (int) ($counts[self::WARN] ?? 0), 'fail' => $fails],
                'checks' => $this->rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $fails > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Club Portal host check (read-only: nothing is changed)');
        $this->line('Version: '.config('app.version').(config('app.commit') ? ' (commit '.substr((string) config('app.commit'), 0, 12).')' : ''));
        $this->line('Environment: '.config('app.env').($this->production ? '' : ' (not production: live-site-only checks show WARN instead of FAIL)'));
        $this->table(['Status', 'Check', 'Result', 'How to fix'], array_map(fn ($r) => [$r['status'], $r['check'], $r['result'], $r['fix']], $this->rows));
        $this->line(sprintf('%d FAIL, %d WARN, %d PASS.', $fails, (int) ($counts[self::WARN] ?? 0), (int) ($counts[self::PASS] ?? 0)));
        $this->line('PHP version, extensions and limits are those of the command-line PHP; the website can use other values (cPanel → MultiPHP Manager / MultiPHP INI Editor).');
        if ($fails > 0) {
            $this->error('Not ready: fix the FAIL rows, run php artisan config:cache after any .env change, then run php artisan app:doctor again.');
        } else {
            $this->info('No FAIL rows.'.(($counts[self::WARN] ?? 0) > 0 ? ' Read the WARN rows before going live.' : ''));
        }

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * $ok → PASS. Otherwise WARN for $severity 'warn', and FAIL for 'fail',
     * except that a $liveOnly check is only a WARN outside production.
     */
    private function add(string $check, bool $ok, string $result, string $fix, string $severity = 'fail', bool $liveOnly = false): void
    {
        $status = match (true) {
            $ok => self::PASS,
            $severity === 'warn', $liveOnly && ! $this->production => self::WARN,
            default => self::FAIL,
        };
        $this->rows[] = ['status' => $status, 'check' => $check, 'result' => $result, 'fix' => $ok ? '' : $fix];
    }

    private function checkPhp(HostProbe $probe): void
    {
        $version = $probe->phpVersion();
        // The requirements (PHP version, extensions) are in bootstrap/requirements.php, shared with /install.
        $series = Requirements::phpSeries();
        $this->add('PHP version', version_compare($version, Requirements::php(), '>='), $version.' (needs '.Requirements::php().' or newer)',
            "Choose PHP {$series} in cPanel MultiPHP Manager; in the Terminal and the cron job use the {$series} binary (e.g. /usr/local/bin/ea-php".str_replace('.', '', $series).').');

        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $pdo = Requirements::pdoExtension($driver);
        $needed = [...Requirements::extensions(), $pdo];
        $missing = array_values(array_filter($needed, fn ($ext) => ! $probe->extensionLoaded($ext)));
        $this->add('PHP extensions', $missing === [], $missing === [] ? 'all present ('.$pdo.' for '.$driver.')' : 'missing: '.implode(', ', $missing),
            "Enable them in cPanel → Select PHP Version → Extensions (for PHP {$series}).");
    }

    private function checkAppSettings(): void
    {
        $this->add('APP_KEY', filled(config('app.key')), filled(config('app.key')) ? 'set (hidden)' : 'not set',
            'Run php artisan key:generate once (never on a site with data: files are encrypted with it), then back the key up off the server.');

        $env = (string) config('app.env');
        $this->add('APP_ENV', $env === 'production', $env, 'Set APP_ENV=production in .env on the live site.', 'fail', true);

        $debug = (bool) config('app.debug');
        $this->add('APP_DEBUG', ! $debug, $debug ? 'true (error pages show code and settings)' : 'false',
            'Set APP_DEBUG=false in .env.', 'fail', true);

        $url = (string) config('app.url');
        $this->add('APP_URL uses https', str_starts_with($url, 'https://'), $url !== '' ? $url : '(empty)',
            'Set APP_URL to the exact https:// address members type (links and payment callbacks are built from it).', 'fail', true);

        $secure = config('session.secure') === true;
        $this->add('SESSION_SECURE_COOKIE', $secure, $secure ? 'true' : 'not true',
            'Set SESSION_SECURE_COOKIE=true in .env (sign-in cookies only over https).', 'fail', true);

        $tz = (string) config('app.timezone');
        $this->add('APP_TIMEZONE', $tz === 'Asia/Kuala_Lumpur', $tz,
            'Set APP_TIMEZONE=Asia/Kuala_Lumpur (due dates, "today" and the 08:00 reminders use it).', 'fail', true);

        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver", $store);
        $this->add('Cache store', ! in_array($driver, ['array', 'null'], true), $store.($driver !== $store ? " ({$driver})" : ''),
            'Set CACHE_STORE=database (or file): the pay lock and rate limits need a cache that lasts between requests.');
    }

    private function checkPhpLimits(HostProbe $probe): void
    {
        foreach ([['upload_max_filesize', 12], ['post_max_size', 32], ['memory_limit', 128]] as [$key, $minMb]) {
            $raw = $probe->ini($key);
            $bytes = HostProbe::bytes($raw);
            // -1 or 0 means no limit; an unset value can't be checked.
            $set = $raw !== false && trim($raw) !== '';
            $ok = $bytes === -1 ? $set : $bytes >= $minMb * 1024 * 1024;
            $this->add($key, $ok, ($raw === false || $raw === '' ? '(not set)' : $raw).($bytes === -1 && $ok ? ' (no limit)' : " (needs {$minMb}M or more)"),
                "Set {$key} = {$minMb}M in cPanel MultiPHP INI Editor (see docs/INSTALL.md step 10).", 'fail', true);
        }
    }

    private function checkFiles(): void
    {
        $private = (string) config('filesystems.disks.local.root');
        $real = realpath($private);
        $public = realpath(public_path()) ?: public_path();
        $insidePublic = $real !== false && str_starts_with($real.DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
        $writable = $real !== false && is_dir($real) && is_writable($real);
        $this->add('Private files folder', $writable && ! $insidePublic,
            match (true) {
                $real === false => 'storage/app/private does not exist',
                $insidePublic => 'it is inside the public folder (anyone could download the files)',
                ! $writable => 'not writable by PHP',
                default => 'writable, outside the public folder',
            },
            $insidePublic ? 'Point the domain at the app\'s public/ folder only, and keep storage/ outside it.' : 'Run chmod -R 775 storage bootstrap/cache in the app folder.');

        $link = public_path('storage');
        $this->add('storage:link', is_link($link) || is_dir($link), is_link($link) || is_dir($link) ? 'public/storage is in place' : 'public/storage is missing (club logos do not show)',
            'Run php artisan storage:link.', 'fail', true);

        $manifest = is_file(public_path('build/manifest.json'));
        $this->add('Front-end build', $manifest, $manifest ? 'public/build/manifest.json present' : 'public/build/manifest.json missing',
            'Upload the release\'s public/build folder (built with npm run build).', 'fail', true);

        $hot = is_file(public_path('hot'));
        $this->add('No Vite dev-server file', ! $hot, $hot ? 'public/hot exists (pages try to load a dev server)' : 'public/hot absent',
            'Delete public/hot.', 'fail', true);
    }

    /** @return Collection<int, Club>|null the active clubs, or null when the database can't be read */
    private function checkDatabase(): ?Collection
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $this->add('Database', false, "{$driver}: cannot connect", 'Check the DB_ settings in .env (for SQLite, that database/database.sqlite exists and is writable).');

            return null;
        }
        try {
            if (! Schema::hasTable('clubs')) {
                $this->add('Database', false, "{$driver}: connected, but the tables are missing", 'Run php artisan migrate --force.');

                return null;
            }
            $clubs = Club::where('is_active', true)->get();
            $this->add('Database', true, "{$driver}: connected, ".$clubs->count().' active club(s)', '');

            return $clubs;
        } catch (\Throwable $e) {
            // Not the message: it can quote settings. The class says enough to start with.
            $this->add('Database', false, "{$driver}: connected, but reading the clubs failed (".class_basename($e).')',
                'Run php artisan migrate --force; if it persists, see storage/logs/laravel.log.');
            report($e);

            return null;
        }
    }

    /** @param  Collection<int, Club>|null  $clubs */
    private function checkGateways(?Collection $clubs): void
    {
        if ($clubs === null) {
            $this->add('Webhook secrets', false, 'not checked (database unavailable)', 'Fix the Database row first.', 'warn');

            return;
        }
        if ($clubs->isEmpty()) {
            $this->add('Webhook secrets', true, 'no active club yet: run this again after creating the club', '');
            $this->add('ToyyibPay mode', true, config('toyyibpay.sandbox') ? 'sandbox (no active club yet)' : 'live', '');

            return;
        }

        $toyyib = $clubs->filter(fn (Club $c) => $c->activeGateway() === 'toyyibpay');
        $billplz = $clubs->filter(fn (Club $c) => $c->activeGateway() === 'billplz');

        if ($toyyib->isNotEmpty()) {
            $ok = WebhookSecret::isUsable((string) config('toyyibpay.webhook_secret'));
            $this->add('ToyyibPay webhook secret', $ok, $ok ? 'set (hidden)' : 'missing, too short or a placeholder: callbacks are refused and no bill can be opened',
                'Set TOYYIBPAY_WEBHOOK_SECRET to a random value of 32+ characters (php -r "echo bin2hex(random_bytes(32));").', 'fail', true);
        }
        if ($billplz->isNotEmpty()) {
            $bad = $billplz->filter(function (Club $c) {
                try {
                    $key = $c->hasBillplzCredentials() ? (string) $c->billplz_x_signature_key : (string) config('billplz.x_signature_key');
                } catch (\Throwable) {
                    return true; // can't be decrypted with this APP_KEY
                }

                return ! WebhookSecret::isUsable($key, 1);
            });
            $this->add('Billplz X-Signature key', $bad->isEmpty(), $bad->isEmpty() ? 'set (hidden) for every Billplz club' : 'missing for: '.$bad->map(fn (Club $c) => "{$c->name} (#{$c->id})")->implode(', '),
                'Set BILLPLZ_X_SIGNATURE_KEY in .env, or the club\'s own key on its page (Clubs → Edit).', 'fail', true);
        }

        if (config('toyyibpay.sandbox') && $toyyib->isNotEmpty()) {
            $this->add('ToyyibPay mode', false, 'TOYYIBPAY_SANDBOX=true while '.$toyyib->count().' active club(s) use ToyyibPay: a simulated payment marks a fee paid with no real money',
                'Expected on the staging site (docs/SANDBOX-TEST.md). On the live site set TOYYIBPAY_SANDBOX=false once the sandbox test has passed.', 'warn');
        } else {
            $this->add('ToyyibPay mode', true, config('toyyibpay.sandbox') ? 'sandbox (no active club uses ToyyibPay)' : 'live', '');
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $notSent = in_array($mailer, ['log', 'array'], true);
        $this->add('Mail', ! ($notSent && $this->production), $mailer.($notSent ? ' (emails are not sent)' : ''),
            'Set MAIL_MAILER=smtp and the MAIL_ settings (docs/INSTALL.md step 8): welcome passwords and receipts are not sent.', 'warn');
    }

    private function checkScheduler(): void
    {
        $last = SchedulerHeartbeat::last();
        $stale = $last === null || $last->lt(now()->subMinutes(SchedulerHeartbeat::STALE_MINUTES));
        $this->add('Scheduler (cron job)', ! $stale,
            $last === null ? 'has never run' : 'last ran '.$last->diffForHumans(),
            'Add the every-minute cron line from docs/INSTALL.md step 12 (reminders, payment checks and backups need it).', 'fail', true);
    }

    private function checkBackups(): void
    {
        $latest = collect(glob(BackupRun::directory().'/*.zip') ?: [])->map(fn ($f) => filemtime($f))->max();
        $ok = $latest !== null && $latest >= now()->subDays(2)->getTimestamp();
        $this->add('Backups', $ok,
            $latest === null ? 'no backup in storage/app/backups yet' : 'latest '.now()->setTimestamp($latest)->diffForHumans(),
            'Run php artisan backup:run (the scheduler makes one daily); download the zips off the server.', 'warn');
    }
}
