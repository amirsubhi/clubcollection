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
    public function index()
    {
        $requirements = $this->checkRequirements();
        return view('install.index', compact('requirements'));
    }

    public function process(Request $request)
    {
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
            'app_name'       => 'required|string|max:100',
            'app_url'        => 'required|url|max:255',
            'admin_name'     => 'required|string|max:255',
            'admin_email'    => 'required|email|max:255',
            'admin_password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        // Update .env first — non-fatal if it fails (we revert below on rollback).
        $envBackup = @file_get_contents(base_path('.env'));
        $this->updateEnv([
            'APP_NAME' => '"' . $data['app_name'] . '"',
            'APP_URL'  => $data['app_url'],
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

        return redirect()->route('login')
            ->with('success', 'Installation complete! You can now log in.');
    }

    private function checkRequirements(): array
    {
        // Require PHP 8.3 to match composer.json. (README and this controller
        // previously claimed 8.2; trust composer.)
        $phpOk      = version_compare(PHP_VERSION, '8.3.0', '>=');
        $sqliteOk   = extension_loaded('pdo_sqlite');
        $storageOk  = is_writable(storage_path('app'));
        $envOk      = file_exists(base_path('.env')) && is_writable(base_path('.env'));

        return [
            'php'      => ['pass' => $phpOk,     'label' => 'PHP >= 8.3',           'value' => PHP_VERSION],
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

    private function restoreEnv(?string $backup): void
    {
        if ($backup === null || $backup === false) {
            return;
        }
        @file_put_contents(base_path('.env'), $backup);
    }
}
