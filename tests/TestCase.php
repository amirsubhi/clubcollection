<?php

namespace Tests;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the app appears installed so requests are not redirected to /install
        if (! file_exists(storage_path('app'))) {
            mkdir(storage_path('app'), 0755, true);
        }
        touch(storage_path('app/.installed'));
    }

    /**
     * Create a super_admin user, log them in, and return the user.
     * 2FA is NOT enabled (two_factor_confirmed_at = null) so the
     * RequiresTwoFactor middleware passes through automatically.
     */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user);
        return $user;
    }

    /**
     * Create a user with role=admin, attach them as club admin via the
     * club_user pivot, log them in, and return the user.
     */
    protected function actingAsClubAdmin(Club $club): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $club->members()->attach($user, [
            'role'        => 'admin',
            'job_level'   => 'manager',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ]);
        $this->actingAs($user);
        return $user;
    }

    /**
     * Create a plain member, attach them to $club, log them in, and return the user.
     */
    protected function actingAsMember(Club $club): User
    {
        $user = User::factory()->create(['role' => 'member']);
        $club->members()->attach($user, [
            'role'        => 'member',
            'job_level'   => 'executive',
            'joined_date' => now()->toDateString(),
            'is_active'   => true,
        ]);
        $this->actingAs($user);
        return $user;
    }

    /** A ToyyibPay webhook secret long enough to be accepted (>= 32 characters). */
    protected const TOYYIB_SECRET = 'test-webhook-secret-2026-0123456789abcdef';

    /** The per-bill ToyyibPay callback token for a bill reference. */
    protected function toyyibToken(string $reference, string $secret = self::TOYYIB_SECRET): string
    {
        return hash_hmac('sha256', $reference, $secret);
    }

    private ?string $toyyibConfirmAmount = null;

    private int $toyyibConfirmCount = 1;

    private bool $toyyibConfirmFaked = false;

    /**
     * Answer ToyyibPay getBillTransactions (the callback confirm step) with
     * $count successful transactions for the bill's amount, or $amount
     * (ringgit) when given; an unknown bill gets an empty list unless $amount
     * is given. Bills are looked up in payment_bills, then payments.bill_code.
     * Other URLs are faked by $extra. Calling it again changes the answer.
     */
    protected function fakeToyyibConfirm(?string $amount = null, array $extra = [], int $count = 1): void
    {
        $this->toyyibConfirmAmount = $amount;
        $this->toyyibConfirmCount = $count;
        if ($extra) {
            Http::fake($extra);
        }
        if ($this->toyyibConfirmFaked) {
            return;
        }
        $this->toyyibConfirmFaked = true;

        Http::fake([
            '*/index.php/api/getBillTransactions' => function (HttpRequest $request) {
                $code = (string) ($request['billCode'] ?? '');
                $bill = PaymentBill::where('gateway', 'toyyibpay')->where('bill_code', $code)->first();
                $payment = Payment::where('bill_code', $code)->first();
                $sen = $bill?->amount_sen ?? ($payment ? (int) round(((float) ($payment->bill_amount ?? $payment->amount)) * 100) : null);
                $amount = $this->toyyibConfirmAmount;
                if ($amount === null && $sen === null) {
                    return Http::response([]);
                }

                return Http::response(array_fill(0, $this->toyyibConfirmCount, [
                    'billpaymentStatus' => '1',
                    'billpaymentAmount' => $amount ?? number_format($sen / 100, 2, '.', ''),
                ]));
            },
        ]);
    }
}
