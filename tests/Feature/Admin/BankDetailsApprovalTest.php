<?php

namespace Tests\Feature\Admin;

use App\Mail\ClubAdminNotice;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Services\BankDetailsChangeService;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * S1: a change to the bank / DuitNow details members pay into waits for a
 * second admin (or the super admin). The requester can cancel but never
 * approve; all club admins are emailed; members see the old details until
 * approval; switching transfers off is immediate.
 */
class BankDetailsApprovalTest extends TestCase
{
    private Club $club;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        $this->club = Club::factory()->create([
            'transfer_enabled' => true,
            'bank_name' => 'Bank Lama',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '1111 2222 3333',
        ]);
        $this->adminA = $this->admin();
        $this->adminB = $this->admin();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->club->members()->attach($u, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $u;
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'allow_monthly' => '1', 'allow_quarterly' => '1', 'allow_yearly' => '1',
            'default_plan' => 'yearly', 'gateway_fee_payer' => 'club', 'gateway_fee' => '1.00',
            'transfer_enabled' => '1',
            'bank_name' => 'Bank Baru',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '9999 8888 7777',
            'duitnow_id' => '',
        ], $overrides);
    }

    private function version(): string
    {
        return app(BankDetailsChangeService::class)->version($this->club->fresh());
    }

    public function test_change_is_pending_until_a_second_admin_approves(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form())->assertSessionHasNoErrors();

        $club = $this->club->fresh();
        $this->assertSame('1111 2222 3333', $club->bank_account_no, 'members still see the old account');
        $this->assertTrue($club->transferReady());
        $this->assertSame('9999 8888 7777', $club->pending_bank_change['bank_account_no']);
        $this->assertSame($this->adminA->id, $club->bank_change_requested_by);
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($this->adminA->email));
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($this->adminB->email));

        // The billing page shows the request to the other admin, with Approve.
        $this->actingAs($this->adminB)->get(route('admin.clubs.billing.edit', $this->club))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Clubs/Billing')
                ->where('pendingBank.requestedBy', $this->adminA->name)
                ->where('pendingBank.requestedByMe', false)
                ->where('pendingBank.canApprove', true)
                ->where('pendingBank.version', $this->version())
                ->where('pendingBank.rows.2.field', 'bank_account_no')
                ->where('pendingBank.rows.2.current', '1111 2222 3333')
                ->where('pendingBank.rows.2.requested', '9999 8888 7777')
                ->where('pendingBank.rows.2.changed', true)
                ->where('urls.approve', route('admin.clubs.billing.bank-change.approve', $this->club)));

        $this->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $this->version()])->assertSessionHasNoErrors();

        $club = $this->club->fresh();
        $this->assertSame('9999 8888 7777', $club->bank_account_no);
        $this->assertSame('Bank Baru', $club->bank_name);
        $this->assertFalse($club->hasPendingBankChange());
        $this->assertDatabaseHas('audit_logs', ['action' => 'club.bank_change_approved', 'club_id' => $this->club->id, 'user_id' => $this->adminB->id]);
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->heading === 'Bank details changed' && $m->hasTo($this->adminA->email));
    }

    public function test_the_requester_cannot_approve_but_can_cancel(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form());

        $this->actingAs($this->adminA)->get(route('admin.clubs.billing.edit', $this->club))
            ->assertOk()->assertSee('You asked for this change')
            ->assertDontSee(route('admin.clubs.billing.bank-change.approve', $this->club), false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingBank.requestedByMe', true)
                ->where('pendingBank.canApprove', false)
                ->where('urls.approve', null)
                ->where('urls.cancel', route('admin.clubs.billing.bank-change.cancel', $this->club)));

        $this->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $this->version()])
            ->assertSessionHas('error');
        $this->assertSame('1111 2222 3333', $this->club->fresh()->bank_account_no);

        $this->post(route('admin.clubs.billing.bank-change.cancel', $this->club))->assertSessionHasNoErrors();
        $this->assertFalse($this->club->fresh()->hasPendingBankChange());
        $this->assertSame('1111 2222 3333', $this->club->fresh()->bank_account_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'club.bank_change_cancelled']);
    }

    public function test_super_admin_can_approve_a_club_admins_request(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form());
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $this->version()])->assertSessionHasNoErrors();

        $this->assertSame('9999 8888 7777', $this->club->fresh()->bank_account_no);
    }

    public function test_a_replaced_request_is_not_approved_blind(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form());
        $seen = $this->version();
        $this->travel(2)->seconds();
        $this->put(route('admin.clubs.billing.update', $this->club), $this->form(['bank_account_no' => '5555 5555 5555']));

        $this->actingAs($this->adminB)->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $seen])
            ->assertSessionHasErrors('bank_change');
        $this->assertSame('1111 2222 3333', $this->club->fresh()->bank_account_no);
    }

    public function test_switching_transfers_off_is_immediate(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form([
            'transfer_enabled' => '0', 'bank_name' => 'Bank Lama', 'bank_account_no' => '1111 2222 3333',
        ]))->assertSessionHasNoErrors();

        $this->assertFalse($this->club->fresh()->transferReady());
        $this->assertFalse($this->club->fresh()->hasPendingBankChange());
    }

    public function test_turning_transfers_on_with_new_details_waits_for_approval(): void
    {
        $this->club->update(['transfer_enabled' => false, 'bank_name' => null, 'bank_account_name' => null, 'bank_account_no' => null]);

        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form())->assertSessionHasNoErrors();
        $this->assertFalse($this->club->fresh()->transferReady());

        $this->actingAs($this->adminB)->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $this->version()]);
        $this->assertTrue($this->club->fresh()->transferReady());
    }

    public function test_members_see_the_old_details_while_a_change_is_pending(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $fee = Payment::factory()->forClub($this->club)->forMember($member)->pending()->create();
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form());

        $this->actingAs($member)->get(route('member.payments.review', $fee))
            ->assertInertia(fn (Assert $page) => $page->where('transfer.accountNo', '1111 2222 3333')->where('transfer.bankName', 'Bank Lama'));
    }

    public function test_an_admin_of_another_club_cannot_approve(): void
    {
        $this->actingAs($this->adminA)->put(route('admin.clubs.billing.update', $this->club), $this->form());
        $this->actingAsClubAdmin(Club::factory()->create());

        $this->post(route('admin.clubs.billing.bank-change.approve', $this->club), ['version' => $this->version()])->assertForbidden();
        $this->assertSame('1111 2222 3333', $this->club->fresh()->bank_account_no);
        $this->assertSame(0, AuditLog::where('action', 'club.bank_change_approved')->count());
    }
}
