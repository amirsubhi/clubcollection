<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
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

        $this->get(route('admin.clubs.billing.edit', $club))->assertOk()->assertSee('Billing settings');

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

        $this->get(route('admin.clubs.billing.edit', $club))->assertOk()
            ->assertSee('Not available yet for ToyyibPay');
        $this->assertMatchesRegularExpression('/id="payer_member"[^>]*\bdisabled\b/', $this->get(route('admin.clubs.billing.edit', $club))->getContent());

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

        $this->get(route('admin.clubs.billing.edit', $club))->assertDontSee('Not available yet for ToyyibPay');
        $this->put(route('admin.clubs.billing.update', $club), $this->valid())->assertSessionHasNoErrors();
        $this->assertSame('member', $club->fresh()->gatewayFeePayer());
    }
}
