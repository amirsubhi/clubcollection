<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class InstallController extends Controller
{
    /** Shortest install token accepted. */
    public const MIN_TOKEN_LENGTH = 16;

    /** Lowest PHP the app runs on: composer.lock (Laravel 13's dependencies) needs 8.4. */
    public const MIN_PHP = '8.4.0';

    /** The PHP version the requirement check uses; tests on an older local PHP lower it. */
    public static string $minimumPhp = self::MIN_PHP;

    public function index()
    {
        $requirements = $this->checkRequirements();
        $installerEnabled = self::installToken() !== null;

        return response()->view('install.index', compact('requirements', 'installerEnabled'), $installerEnabled ? 200 : 403);
    }

    /**
     * The install token from INSTALL_TOKEN, else from storage/app/.install-token;
     * null (installer disabled) when neither is set or it is too short (S1).
     */
    public static function installToken(): ?string
    {
        $token = trim((string) config('app.install_token'));
        if ($token === '' && is_file($file = storage_path('app/.install-token'))) {
            $token = trim((string) @file_get_contents($file));
        }

        return strlen($token) >= self::MIN_TOKEN_LENGTH ? $token : null;
    }

    public function process(Request $request)
    {
        // Disabled without a token; a wrong token is refused before anything
        // else runs, so a fresh upload can't be claimed by whoever finds it first.
        $token = self::installToken();
        if ($token === null) {
            abort(403, 'The installer is disabled. Set INSTALL_TOKEN in .env to run it.');
        }
        if (! hash_equals($token, (string) $request->input('install_token', ''))) {
            Log::warning('Install: wrong install token', ['ip' => $request->ip()]);

            return back()->withInput($request->except(['install_token', 'admin_password', 'admin_password_confirmation']))
                ->withErrors(['install_token' => 'The install token is not correct.']);
        }

        $requirements = $this->checkRequirements();

        if (! $requirements['all_pass']) {
            return back()->with('error', 'One or more system requirements are not met. Please resolve them before continuing.');
        }

        // Refuse to re-run the wizard if the marker already exists. The route
        // is also gated by RedirectIfInstalled, but defend in depth.
        if (file_exists(storage_path('app/.installed'))) {
            return redirect()->route('login')
                ->with('error', 'The application is already installed.');
        }

        $data = $request->validate([
            // Written into .env inside double quotes: no quotes, backslashes, $ (variable
            // interpolation) or line breaks.
            'app_name'       => ['required', 'string', 'max:100', 'not_regex:/["\\\\$\r\n]/'],
            'app_url'        => 'required|url|max:255',
            'admin_name'     => 'required|string|max:255',
            'admin_email'    => 'required|email|max:255',
            'admin_password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'app_name.not_regex' => 'The application name may not contain quotes, backslashes, $ or line breaks.',
        ]);
        $https = str_starts_with(strtolower($data['app_url']), 'https://');

        // Update .env first — non-fatal if it fails (we revert below on rollback).
        // Production settings (S1); a secure-only session cookie needs https.
        $envBackup = @file_get_contents(base_path('.env'));
        $this->updateEnv([
            'APP_NAME' => '"' . $data['app_name'] . '"',
            'APP_URL'  => rtrim($data['app_url'], '/'),
            'APP_ENV'  => 'production',
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => 'Asia/Kuala_Lumpur',
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
            'LOG_LEVEL' => 'warning',
        ]);

        // Run migrations.
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        if ($exitCode !== 0) {
            $this->restoreEnv($envBackup);
            Log::error('Install: migrations failed', ['output' => Artisan::output()]);
            return back()->with('error', 'Migrations failed. See application logs.');
        }

        // Create the super admin in a transaction, then atomically write the
        // marker file. If creation fails, we delete the marker so the wizard
        // remains reachable.
        try {
            DB::transaction(function () use ($data) {
                User::create([
                    'name'     => $data['admin_name'],
                    'email'    => $data['admin_email'],
                    'password' => Hash::make($data['admin_password']),
                    'role'     => 'super_admin',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Install: super-admin creation failed', ['error' => $e->getMessage()]);
            $this->restoreEnv($envBackup);
            return back()->with('error', 'Failed to create the super admin. See application logs.');
        }

        // Atomically write the marker so a partial state can never appear
        // installed: write to a temp file, then rename in one syscall.
        $marker = storage_path('app/.installed');
        $tmp    = $marker . '.tmp';
        if (@file_put_contents($tmp, (string) now()) === false || ! @rename($tmp, $marker)) {
            Log::error('Install: failed to write .installed marker');
            return back()->with('error', 'Failed to finalize installation. See application logs.');
        }

        // The token is single-use.
        $this->updateEnv(['INSTALL_TOKEN' => '']);
        @unlink(storage_path('app/.install-token'));

        return redirect()->route('login')
            ->with('success', 'Installation complete! You can now log in.'.($https ? '' : ' Your URL is not https, so session cookies are not marked secure: switch to https before going live.'));
    }

    private function checkRequirements(): array
    {
        // composer.lock needs PHP 8.4, even though composer.json still says ^8.3.
        $minPhp     = self::$minimumPhp;
        $phpOk      = version_compare(PHP_VERSION, $minPhp, '>=');
        $sqliteOk   = extension_loaded('pdo_sqlite');
        $storageOk  = is_writable(storage_path('app'));
        $envOk      = file_exists(base_path('.env')) && is_writable(base_path('.env'));

        return [
            'php'      => ['pass' => $phpOk,     'label' => 'PHP >= '.preg_replace('/\.0$/', '', $minPhp), 'value' => PHP_VERSION],
            'sqlite'   => ['pass' => $sqliteOk,  'label' => 'PDO SQLite Extension', 'value' => $sqliteOk ? 'Enabled' : 'Missing'],
            'storage'  => ['pass' => $storageOk, 'label' => 'Storage Writable',     'value' => $storageOk ? 'Writable' : 'Not writable'],
            'env'      => ['pass' => $envOk,     'label' => '.env File Writable',   'value' => $envOk ? 'Writable' : 'Missing or not writable'],
            'all_pass' => $phpOk && $sqliteOk && $storageOk && $envOk,
        ];
    }

    private function updateEnv(array $values): void
    {
        $path   = base_path('.env');
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            // Advisory exclusive lock to prevent two install POSTs from
            // racing and corrupting the file.
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            $env = stream_get_contents($handle);

            foreach ($values as $key => $value) {
                $pattern = "/^{$key}=.*/m";
                if (preg_match($pattern, $env)) {
                    // Use callback so $ in $value is not treated as a backreference.
                    $env = preg_replace_callback($pattern, fn() => "{$key}={$value}", $env);
                } else {
                    $env .= "\n{$key}={$value}";
                }
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $env);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function restoreEnv(string|false|null $backup): void
    {
        if ($backup === null || $backup === false) {
            return;
        }
        @file_put_contents(base_path('.env'), $backup);
    }
}
