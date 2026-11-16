<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClubBillingSettingsTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'allow_monthly' => '1',
            'allow_quarterly' => '1',
            'allow_yearly' => '1',
            'default_plan' => 'yearly',
            'gateway_fee_payer' => 'member',
            'gateway_fee' => '1.50',
        ], $overrides);
    }

    public function test_new_clubs_default_to_all_plans_yearly_and_club_absorbs_rm1(): void
    {
        $club = Club::factory()->create()->fresh();

        $this->assertSame(['monthly', 'quarterly', 'yearly'], $club->allowedPlans());
        $this->assertSame('yearly', $club->defaultPlan());
        $this->assertSame('club', $club->gatewayFeePayer());
        $this->assertSame(100, $club->gatewayFeeSen());
    }

    public function test_club_admin_can_view_and_update_settings_and_it_is_audited(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz']);
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.billing.edit', $club))->assertOk()->assertSee('Billing settings')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Clubs/Billing')
                ->where('gatewayLabel', 'Billplz')
                ->where('memberFeeAllowed', true)
                ->has('plans', 3)
                ->where('plans.2.key', 'yearly')
                ->where('settings.allow_monthly', true)
                ->where('settings.default_plan', 'yearly')
                ->where('settings.gateway_fee_payer', 'club')
                ->where('settings.gateway_fee', '1.00')
                ->where('settings.transfer_enabled', false)
                ->where('pendingBank', null)
                ->where('urls.update', route('admin.clubs.billing.update', $club))
                ->where('urls.approve', null)
                ->where('nav.groups.0.items', fn ($items) => collect($items)->firstWhere('key', 'billing')['external'] === false));

        $this->put(route('admin.clubs.billing.update', $club), $this->valid(['allow_monthly' => '0', 'default_plan' => 'quarterly']))
            ->assertRedirect(route('admin.clubs.billing.edit', $club))
            ->assertSessionHasNoErrors();

        $club->refresh();
        $this->assertFalse($club->allow_monthly);
        $this->assertSame(['quarterly', 'yearly'], $club->allowedPlans());
        $this->assertSame('quarterly', $club->default_plan);
        $this->assertSame('member', $club->gateway_fee_payer);
        $this->assertSame(150, $club->gateway_fee_sen);

        $log = AuditLog::where('action', 'club.billing_updated')->firstOrFail();
        $this->assertSame($club->id, $log->club_id);
        $this->assertSame('club', $log->old_values['gateway_fee_payer']);
        $this->assertSame(150, $log->new_values['gateway_fee_sen']);
    }

    public function test_super_admin_can_update_settings(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz']);
        $this->actingAsSuperAdmin();

        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertSessionHasNoErrors();
        $this->assertSame('member', $club->fresh()->gateway_fee_payer);
    }

    public function test_admin_of_another_club_and_members_are_refused(): void
    {
        $club = Club::factory()->create();

        $this->actingAsClubAdmin(Club::factory()->create());
        $this->get(route('admin.clubs.billing.edit', $club))->assertForbidden();
        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertForbidden();

        $this->actingAsMember($club);
        $this->get(route('admin.clubs.billing.edit', $club))->assertForbidden();
        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertForbidden();

        // Inactive admin of this club.
        $admin = User::factory()->create(['role' => 'admin']);
        $club->members()->attach($admin, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => false]);
        $this->actingAs($admin);
        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertForbidden();

        $this->assertSame('club', $club->fresh()->gateway_fee_payer);
        $this->assertSame(0, AuditLog::where('action', 'club.billing_updated')->count());
    }

    public function test_validation_rules(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $url = route('admin.clubs.billing.update', $club);

        $this->put($url, $this->valid(['gateway_fee_payer' => 'treasurer']))->assertSessionHasErrors('gateway_fee_payer');
        $this->put($url, $this->valid(['default_plan' => 'weekly']))->assertSessionHasErrors('default_plan');
        $this->put($url, $this->valid(['gateway_fee' => '-1']))->assertSessionHasErrors('gateway_fee');
        $this->put($url, $this->valid(['gateway_fee' => '100']))->assertSessionHasErrors('gateway_fee');
        $this->put($url, $this->valid(['gateway_fee' => '1.005']))->assertSessionHasErrors('gateway_fee');
        $this->put($url, $this->valid(['gateway_fee' => '']))->assertSessionHasErrors('gateway_fee');
        // At least one plan, and the default must be one of them.
        $this->put($url, $this->valid(['allow_monthly' => '0', 'allow_quarterly' => '0', 'allow_yearly' => '0']))->assertSessionHasErrors('allow_monthly');
        $this->put($url, $this->valid(['allow_yearly' => '0', 'default_plan' => 'yearly']))->assertSessionHasErrors('default_plan');

        $fresh = $club->fresh();
        $this->assertSame('club', $fresh->gateway_fee_payer);
        $this->assertSame(['monthly', 'quarterly', 'yearly'], $fresh->allowedPlans());
    }

    public function test_toyyibpay_member_pays_is_refused_until_the_charge_flag_is_verified(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => false]);
        $club = Club::factory()->create(['payment_gateway' => 'toyyibpay']);
        $this->actingAsClubAdmin($club);

        // The member option is offered disabled, with its reason, and the form starts on "club".
        $this->get(route('admin.clubs.billing.edit', $club))->assertOk()
            ->assertSee('Not available yet for ToyyibPay')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Clubs/Billing')
                ->where('memberFeeAllowed', false)
                ->where('memberFeeReason', fn ($r) => str_contains($r, 'Not available yet for ToyyibPay'))
                ->where('settings.gateway_fee_payer', 'club'));

        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertSessionHasErrors('gateway_fee_payer');
        $this->putJson(route('admin.clubs.billing.update', $club), $this->valid())->assertStatus(422)->assertJsonValidationErrors('gateway_fee_payer');
        $this->assertSame('club', $club->fresh()->gateway_fee_payer);

        // Club absorbs is still accepted.
        $this->put(route('admin.clubs.billing.update', $club), $this->valid(['gateway_fee_payer' => 'club']))->assertSessionHasNoErrors();
    }

    public function test_toyyibpay_member_pays_is_allowed_once_verified(): void
    {
        config(['toyyibpay.charge_to_customer_verified' => true]);
        $club = Club::factory()->create(['payment_gateway' => 'toyyibpay']);
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.billing.edit', $club))->assertDontSee('Not available yet for ToyyibPay')
            ->assertInertia(fn (Assert $page) => $page->where('memberFeeAllowed', true)->where('memberFeeReason', null));
        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertSessionHasNoErrors();
        $this->assertSame('member', $club->fresh()->gatewayFeePayer());
    }

    // ── P2: bank / DuitNow transfer details ──────────────────────────────

    private function bank(array $overrides = []): array
    {
        return $this->valid(array_merge([
            'gateway_fee_payer' => 'club',
            'transfer_enabled' => '1',
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '1234 5678 9012',
            'duitnow_id' => '',
        ], $overrides));
    }

    /** A second club admin approves the pending bank change (S1), then the first admin is acting again. */
    private function approveAsSecondAdmin(Club $club): void
    {
        $first = auth()->user();
        $second = User::factory()->create(['role' => 'admin']);
        $club->members()->attach($second, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $this->actingAs($second);
        $version = app(\App\Services\BankDetailsChangeService::class)->version($club->fresh());
        $this->post(route('admin.clubs.billing.bank-change.approve', $club), ['version' => $version])->assertSessionHasNoErrors();
        $this->actingAs($first);
    }

    public function test_transfer_details_are_saved_and_audited(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->put(route('admin.clubs.billing.update', $club), $this->bank())->assertSessionHasNoErrors();
        // Waiting for a second admin: members don't see the details yet.
        $this->assertFalse($club->fresh()->transferReady());
        $this->assertTrue($club->fresh()->hasPendingBankChange());
        $this->approveAsSecondAdmin($club);

        $club->refresh();
        $this->assertTrue($club->transferReady());
        $this->assertSame('1234 5678 9012', $club->bank_account_no);
        $this->assertNull($club->duitnow_id);
        $log = AuditLog::where('action', 'club.bank_change_approved')->latest('id')->firstOrFail();
        $this->assertFalse($log->old_values['transfer_enabled']);
        $this->assertSame('Bank Contoh', $log->new_values['bank_name']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'club.bank_change_requested', 'club_id' => $club->id]);
    }

    public function test_transfer_cannot_be_enabled_without_complete_details(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->put(route('admin.clubs.billing.update', $club), $this->bank(['bank_account_no' => '', 'duitnow_id' => '']))
            ->assertSessionHasErrors('transfer_enabled');
        $this->put(route('admin.clubs.billing.update', $club), $this->bank(['bank_account_name' => '']))
            ->assertSessionHasErrors('transfer_enabled');
        $this->assertFalse((bool) $club->fresh()->transfer_enabled);
        $this->assertFalse($club->fresh()->hasPendingBankChange());

        // A DuitNow ID alone is enough (once approved); switching off is immediate.
        $this->put(route('admin.clubs.billing.update', $club), $this->bank(['bank_account_no' => '', 'duitnow_id' => '0123456789']))
            ->assertSessionHasNoErrors();
        $this->approveAsSecondAdmin($club);
        $this->assertTrue($club->fresh()->transferReady());
        $this->put(route('admin.clubs.billing.update', $club), $this->bank(['transfer_enabled' => '0', 'bank_name' => '']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($club->fresh()->transferReady());
    }

    public function test_account_number_must_be_digits_spaces_or_dashes(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        foreach (['12345', 'ABC123456', '123456789012345678901', '1234 5678 90X'] as $bad) {
            $this->put(route('admin.clubs.billing.update', $club), $this->bank(['bank_account_no' => $bad]))
                ->assertSessionHasErrors('bank_account_no');
        }
        foreach (['123456', '1234-5678-9012', '12345678901234567890'] as $good) {
            $this->put(route('admin.clubs.billing.update', $club), $this->bank(['bank_account_no' => $good]))
                ->assertSessionHasNoErrors();
        }
    }

    public function test_another_clubs_admin_cannot_change_bank_details(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin(Club::factory()->create());

        $this->put(route('admin.clubs.billing.update', $club), $this->bank())->assertForbidden();
        $this->assertNull($club->fresh()->bank_name);
    }
}
