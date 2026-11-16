<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public static function allowedIn(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    public function run(): void
    {
        // Demo data only (S1): the seeder provisions a super admin and sample
        // people, so it refuses anywhere but local development and tests (an
        // accidental `php artisan db:seed` on a server must never create an
        // admin account). Use the install wizard there instead.
        if (! self::allowedIn((string) app()->environment())) {
            $this->command?->warn(
                'DatabaseSeeder refused: it only runs when APP_ENV is local or testing. '.
                'Use the install wizard to create the super admin.'
            );
            return;
        }

        // Demo super admin: SEED_SUPER_ADMIN_PASSWORD when set, otherwise a
        // random password printed once. There is no well-known default.
        $password = (string) env('SEED_SUPER_ADMIN_PASSWORD', '');
        if ($password === '') {
            $password = Str::password(20, symbols: false);
            $this->command?->info("Demo super admin: superadmin@example.com / {$password} (shown once; local only)");
        }
        User::create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@example.com',
            'password' => Hash::make($password),
            'role'     => 'super_admin',
        ]);

        // Sample club
        $club = Club::create([
            'name'      => 'Recreation Club',
            'email'     => 'recreation@company.com',
            'is_active' => true,
            // Sample (not real) details so members see the bank-transfer option.
            'transfer_enabled'  => true,
            'bank_name'         => 'Bank Contoh',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no'   => '1234 5678 9012',
        ]);

        // Default fee rates
        $rates = [
            'gm'        => 50.00,
            'agm'       => 30.00,
            'manager'   => 20.00,
            'executive' => 10.00,
            'non_exec'  => 5.00,
        ];

        foreach ($rates as $level => $amount) {
            FeeRate::create([
                'club_id'        => $club->id,
                'job_level'      => $level,
                'monthly_amount' => $amount,
                'effective_from' => now()->startOfMonth()->toDateString(),
                'effective_to'   => null,
            ]);
        }

        // Default expense categories (English for admins, BM for members)
        foreach (ExpenseCategory::DEFAULTS as $name => $nameMs) {
            ExpenseCategory::create([
                'club_id' => $club->id,
                'name'    => $name,
                'name_ms' => $nameMs,
            ]);
        }

        $this->seedDemoFunds($club);
    }

    /**
     * Demo members, paid fees and Fund Usage records covering every status
     * (draft, submitted, returned, approved, void). No documents are seeded.
     */
    private function seedDemoFunds(Club $club): void
    {
        $super = User::where('email', 'superadmin@example.com')->first();
        $members = collect(['Aminah binti Yusof', 'Tan Wei Ming', 'Priya a/p Subramaniam'])->map(function ($name, $i) use ($club) {
            $u = User::create([
                'name'     => $name,
                'email'    => 'member'.($i + 1).'@clubportal.test',
                'password' => Hash::make(Str::random(32)),
                'role'     => 'member',
            ]);
            $club->members()->attach($u, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);

            return $u;
        });

        for ($m = 5; $m >= 0; $m--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($m);
            foreach ($members as $u) {
                Payment::create([
                    'club_id' => $club->id, 'user_id' => $u->id, 'recorded_by' => $super->id,
                    'amount' => 10.00, 'frequency' => 'monthly',
                    'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                    'due_date' => $start->copy()->addDays(14)->toDateString(),
                    'paid_date' => $start->copy()->addDays(5)->toDateString(), 'status' => 'paid',
                ]);
            }
        }

        // Demo maker (no usable password); the super admin acts as checker.
        $maker = User::create(['name' => 'Siti Bendahari (demo)', 'email' => 'treasurer@clubportal.test', 'password' => Hash::make(Str::random(32)), 'role' => 'admin']);
        $club->members()->attach($maker, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);

        $cat = fn (string $name) => ExpenseCategory::where('club_id', $club->id)->where('name', $name)->value('id');
        $rows = [
            ['status' => 'approved', 'cat' => 'Food & Beverage', 'desc' => 'Refreshments for committee meeting', 'payee' => 'Restoran Pelita', 'amount' => 86.50, 'ago' => 40],
            ['status' => 'approved', 'cat' => 'Printing & Stationery', 'desc' => 'AGM notices and ballot papers', 'payee' => 'Kedai Cetak Jaya', 'amount' => 42.00, 'ago' => 20],
            ['status' => 'submitted', 'cat' => 'Event', 'desc' => 'Badminton court rental, November', 'payee' => 'Dewan Komuniti', 'amount' => 120.00, 'ago' => 3],
            ['status' => 'draft', 'cat' => 'Supplies', 'desc' => 'Shuttlecocks (2 tubes)', 'payee' => 'Sports Direct', 'amount' => 64.00, 'ago' => 1, 'return' => 'Receipt is blurry, please re-upload.'],
            ['status' => 'void', 'cat' => 'Transportation', 'desc' => 'Bus deposit (duplicate)', 'payee' => 'Syarikat Bas Maju', 'amount' => 200.00, 'ago' => 60],
        ];
        foreach ($rows as $r) {
            $e = new Expense([
                'type' => 'expense', 'expense_category_id' => $cat($r['cat']), 'description' => $r['desc'],
                'payee' => $r['payee'], 'amount' => $r['amount'],
                'expense_date' => now()->subDays($r['ago'])->toDateString(), 'paid_date' => now()->subDays($r['ago'])->toDateString(),
            ]);
            $e->club_id = $club->id;
            $e->recorded_by = $maker->id;
            $e->status = $r['status'];
            $e->return_reason = $r['return'] ?? null;
            if ($r['status'] !== 'draft') {
                $e->submitted_at = now()->subDays($r['ago']);
            }
            if (in_array($r['status'], ['approved', 'void'], true)) {
                $e->approved_by = $super->id;
                $e->approved_at = now()->subDays($r['ago']);
            }
            if ($r['status'] === 'void') {
                $e->voided_by = $super->id;
                $e->void_requested_at = now()->subDays($r['ago'] - 1);
                $e->void_reason = 'Duplicate of an earlier deposit record';
                $e->void_approved_by = $maker->id;
                $e->voided_at = now()->subDays($r['ago'] - 2);
            }
            $e->save();
        }
    }
}
