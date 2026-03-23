<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

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

        $data = $request->validate([
            'app_name'              => 'required|string|max:100',
            'app_url'               => 'required|url|max:255',
            'admin_name'            => 'required|string|max:255',
            'admin_email'           => 'required|email|max:255',
            'admin_password'        => 'required|string|min:8|confirmed',
        ]);

        // Update .env
        $this->updateEnv([
            'APP_NAME' => '"' . $data['app_name'] . '"',
            'APP_URL'  => $data['app_url'],
        ]);

        // Run migrations
        Artisan::call('migrate', ['--force' => true]);

        // Seed default expense categories (no club — these are system-level defaults used when clubs are created)
        // (Categories are per-club, so we skip seeding here and let admins add per club)

        // Create the super admin
        User::create([
            'name'     => $data['admin_name'],
            'email'    => $data['admin_email'],
            'password' => Hash::make($data['admin_password']),
            'role'     => 'super_admin',
        ]);

        // Mark as installed
        touch(storage_path('app/.installed'));

        return redirect()->route('login')
            ->with('success', 'Installation complete! You can now log in.');
    }

    private function checkRequirements(): array
    {
        $phpOk      = version_compare(PHP_VERSION, '8.2.0', '>=');
        $sqliteOk   = extension_loaded('pdo_sqlite');
        $storageOk  = is_writable(storage_path('app'));
        $envOk      = file_exists(base_path('.env')) && is_writable(base_path('.env'));

        return [
            'php'      => ['pass' => $phpOk,     'label' => 'PHP >= 8.2',           'value' => PHP_VERSION],
            'sqlite'   => ['pass' => $sqliteOk,  'label' => 'PDO SQLite Extension', 'value' => $sqliteOk ? 'Enabled' : 'Missing'],
            'storage'  => ['pass' => $storageOk, 'label' => 'Storage Writable',     'value' => $storageOk ? 'Writable' : 'Not writable'],
            'env'      => ['pass' => $envOk,     'label' => '.env File Writable',   'value' => $envOk ? 'Writable' : 'Missing or not writable'],
            'all_pass' => $phpOk && $sqliteOk && $storageOk && $envOk,
        ];
    }

    private function updateEnv(array $values): void
    {
        $env = file_get_contents(base_path('.env'));

        foreach ($values as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
            } else {
                $env .= "\n{$key}={$value}";
            }
        }

        file_put_contents(base_path('.env'), $env);
    }
}
