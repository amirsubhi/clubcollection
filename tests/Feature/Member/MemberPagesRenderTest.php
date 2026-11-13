<?php

namespace Tests\Feature\Member;

use App\Models\Club;
use App\Models\Payment;
use Tests\TestCase;

/**
 * Smoke tests: every member-facing page must render.
 *
 * Guards against Blade compile errors in layouts/member.blade.php, which
 * previously broke every member page with a 500 and went unnoticed because
 * no test rendered a member view.
 */
class MemberPagesRenderTest extends TestCase
{
    private Club $club;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::factory()->create();
        $member = $this->actingAsMember($this->club);
        $this->payment = Payment::factory()
            ->forClub($this->club)
            ->forMember($member)
            ->pending()
            ->create(['notes' => 'INTERNAL-ADMIN-NOTE-should-not-leak']);
    }

    public function test_member_dashboard_renders(): void
    {
        $this->get(route('member.dashboard'))->assertOk();
    }

    public function test_member_payments_index_renders(): void
    {
        $this->get(route('member.payments.index', $this->club))->assertOk();
    }

    public function test_member_invoice_renders(): void
    {
        $this->get(route('member.payments.invoice', $this->payment))->assertOk();
    }

    public function test_member_thankyou_renders(): void
    {
        $this->get(route('member.payments.thankyou', $this->payment))->assertOk();
    }

    public function test_invoice_does_not_show_admin_only_notes(): void
    {
        $this->get(route('member.payments.invoice', $this->payment))
            ->assertOk()
            ->assertDontSee('INTERNAL-ADMIN-NOTE-should-not-leak');
    }
}
