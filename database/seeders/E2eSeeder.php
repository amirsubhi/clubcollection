<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ExpenseCategory;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Fixed fixtures for the end-to-end smoke test (tests/e2e/smoke.mjs).
 *
 *   php artisan db:seed --class=Database\\Seeders\\E2eSeeder
 *
 * Creates one club with fake ToyyibPay credentials and sample bank-transfer
 * details, two members with one pending RM 50.00 fee each (one pays online,
 * one by transfer slip), two members with three open fees each ("Bayar
 * semua": RM 150.00, one by FPX, one by slip) and two club admins (maker
 * and checker). The
 * passwords are public, so it refuses to run outside local/testing.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'E2e-Smoke-Pass-2026';

    public const MEMBER_EMAIL = 'e2e.member@clubportal.test';

    public const ADMIN_A_EMAIL = 'e2e.admin.a@clubportal.test';

    public const ADMIN_B_EMAIL = 'e2e.admin.b@clubportal.test';

    /** Second member: pays by bank transfer with a slip (P2). */
    public const TRANSFER_MEMBER_EMAIL = 'e2e.member.transfer@clubportal.test';

    /** "Bayar semua" (R1b): three open fees each, paid with one FPX bill / one slip. */
    public const PAY_ALL_EMAIL = 'e2e.member.payall@clubportal.test';

    public const PAY_ALL_TRANSFER_EMAIL = 'e2e.member.payall.transfer@clubportal.test';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('E2eSeeder only runs when APP_ENV is local or testing.');
        }

        $club = Club::create([
            'name' => 'E2E Smoke Club',
            'email' => 'e2e.club@clubportal.test',
            'is_active' => true,
            'payment_gateway' => 'toyyibpay',
            // Obviously fake but not the .env.example placeholder, so the club
            // counts as configured. Bill creation is faked by
            // E2eFakeGatewayServiceProvider (local + E2E_FAKE_GATEWAY=1 only).
            'toyyibpay_secret_key' => 'e2e-fake-secret-key-not-real',
            'toyyibpay_category_code' => 'e2efakecat',
            // Manual bank / DuitNow transfer with sample (not real) details.
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '1234 5678 9012',
        ]);

        FeeRate::create([
            'club_id' => $club->id,
            'job_level' => 'executive',
            'monthly_amount' => 50.00,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'effective_to' => null,
        ]);

        foreach (['Event', 'Supplies', 'Miscellaneous'] as $name) {
            ExpenseCategory::create(['club_id' => $club->id, 'name' => $name, 'name_ms' => ExpenseCategory::DEFAULTS[$name]]);
        }

        $member = $this->user('E2E Ahli Ujian', self::MEMBER_EMAIL, 'member');
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);

        foreach ([['E2E Admin A (maker)', self::ADMIN_A_EMAIL], ['E2E Admin B (checker)', self::ADMIN_B_EMAIL]] as [$name, $email]) {
            $admin = $this->user($name, $email, 'admin');
            $club->members()->attach($admin, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);
        }

        $transferMember = $this->user('E2E Ahli Pindahan', self::TRANSFER_MEMBER_EMAIL, 'member');
        $club->members()->attach($transferMember, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);

        $start = now()->startOfMonth();
        foreach ([$member, $transferMember] as $payer) {
            Payment::create([
                'club_id' => $club->id,
                'user_id' => $payer->id,
                'recorded_by' => $payer->id,
                'amount' => 50.00,
                'frequency' => 'monthly',
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'due_date' => $start->copy()->addDays(14)->toDateString(),
                'status' => 'pending',
            ]);
        }

        // Three open fees each, oldest overdue: RM 50 × 3 = RM 150.
        foreach ([['E2E Ahli Bayar Semua', self::PAY_ALL_EMAIL], ['E2E Ahli Semua Pindahan', self::PAY_ALL_TRANSFER_EMAIL]] as [$name, $email]) {
            $payer = $this->user($name, $email, 'member');
            $club->members()->attach($payer, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->subYear()->toDateString(), 'is_active' => true]);
            foreach ([-2, -1, 0] as $offset) {
                $month = $start->copy()->addMonthsNoOverflow($offset);
                Payment::create([
                    'club_id' => $club->id,
                    'user_id' => $payer->id,
                    'recorded_by' => $payer->id,
                    'amount' => 50.00,
                    'frequency' => 'monthly',
                    'period_start' => $month->toDateString(),
                    'period_end' => $month->copy()->endOfMonth()->toDateString(),
                    'due_date' => $month->copy()->addDays(14)->toDateString(),
                    'status' => $offset < -1 ? 'overdue' : 'pending',
                ]);
            }
        }
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
        ]);
    }
}
