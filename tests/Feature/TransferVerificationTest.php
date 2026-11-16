<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Mail\TransferRejected;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P2: manual bank / DuitNow transfer with a slip. The member uploads, a club
 * admin approves (paid) or rejects (back to pending). Never self-approved.
 */
class TransferVerificationTest extends TestCase
{
    private Club $club;

    private User $member;

    private User $adminA;

    private User $adminB;

    private Payment $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();

        $this->club = Club::factory()->create([
            'name' => 'Kelab Contoh',
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab Contoh',
            'bank_account_no' => '1234 5678 9012',
        ]);
        $this->member = $this->attach(User::factory()->create(['role' => 'member', 'name' => 'Ahli Contoh']), 'member');
        $this->adminA = $this->attach(User::factory()->create(['role' => 'admin', 'name' => 'Admin A']), 'admin');
        $this->adminB = $this->attach(User::factory()->create(['role' => 'admin', 'name' => 'Admin B']), 'admin');
        $this->fee = Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['amount' => 50.00]);
    }

    private function attach(User $user, string $role, ?Club $club = null, bool $active = true): User
    {
        ($club ?? $this->club)->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => $active]);

        return $user;
    }

    private function slip(string $name = 'slip.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 800);
    }

    private function submit(array $over = [], ?Payment $fee = null)
    {
        return $this->post(route('member.payments.transfer', $fee ?? $this->fee), array_merge([
            'files' => [$this->slip()],
            'transfer_date' => now()->toDateString(),
            'transfer_ref' => 'MBB 778812',
        ], $over));
    }

    /** The fee with a slip already submitted by the member. */
    private function verifying(): Payment
    {
        $this->actingAs($this->member);
        $this->submit()->assertSessionHasNoErrors();
        auth()->logout();

        return $this->fee->fresh();
    }

    // ── Submit ──────────────────────────────────────────────────────────

    public function test_member_submits_a_slip_and_the_fee_waits_for_verification(): void
    {
        $this->actingAs($this->member);
        $png = $this->slip('resit.png');
        $pdf = UploadedFile::fake()->create('penyata.pdf', 200, 'application/pdf');
        $original = $png->get();

        $this->submit(['files' => [$png, $pdf]])
            ->assertRedirect(route('member.payments.invoice', $this->fee))
            ->assertSessionHas('success', 'member.slip_sent');

        $fee = $this->fee->fresh();
        $this->assertSame('pending_verification', $fee->status);
        $this->assertSame(now()->toDateString(), $fee->transfer_date->toDateString());
        $this->assertSame('MBB 778812', $fee->transfer_ref);
        $this->assertNotNull($fee->transfer_submitted_at);
        $this->assertTrue($fee->attachmentsLocked());

        $slips = $fee->attachments;
        $this->assertCount(2, $slips);
        $this->assertTrue($slips->every(fn (Attachment $a) => $a->kind === 'proof_of_payment' && $a->isFrozen() && (int) $a->uploaded_by === $this->member->id));
        // Encrypted at rest, readable with the app key.
        $raw = Storage::disk('local')->get($slips[0]->path);
        $this->assertNotSame($original, $raw);
        $this->assertSame($original, Crypt::decryptString($raw));
        $this->assertSame(hash('sha256', $original), $slips[0]->sha256);

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.transfer_submitted', 'auditable_id' => $fee->id, 'club_id' => $this->club->id]);
        // Never marked paid by the member.
        $this->assertNull($fee->paid_date);
    }

    public function test_another_member_cannot_submit_for_someone_elses_fee(): void
    {
        $this->actingAs($this->attach(User::factory()->create(['role' => 'member']), 'member'));

        $this->submit()->assertForbidden();
        $this->assertSame('pending', $this->fee->fresh()->status);
        $this->assertSame(0, Attachment::count());
    }

    public function test_inactive_member_cannot_submit(): void
    {
        $this->club->members()->updateExistingPivot($this->member->id, ['is_active' => false]);
        $this->actingAs($this->member);

        $this->submit()->assertForbidden();
        $this->assertSame('pending', $this->fee->fresh()->status);
    }

    public function test_upload_validation(): void
    {
        $this->actingAs($this->member);

        $this->submit(['files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])->assertSessionHasErrors('files.0');
        $this->submit(['files' => [UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')]])->assertSessionHasErrors('files.0');
        $this->submit(['files' => []])->assertSessionHasErrors('files');
        $this->submit(['files' => [$this->slip('a.png'), $this->slip('b.png'), $this->slip('c.png'), $this->slip('d.png')]])->assertSessionHasErrors('files');
        $this->submit(['transfer_date' => now()->addDay()->toDateString()])->assertSessionHasErrors(['transfer_date' => 'member.transfer_date_invalid']);
        // An empty date gets its own message ("Masukkan tarikh pindahan").
        $this->submit(['transfer_date' => ''])->assertSessionHasErrors(['transfer_date' => 'member.transfer_date_required']);
        $this->submit(['transfer_date' => now()->subYear()->subDays(2)->toDateString()])->assertSessionHasErrors('transfer_date');
        $this->submit(['transfer_ref' => str_repeat('x', 61)])->assertSessionHasErrors('transfer_ref');

        $this->assertSame('pending', $this->fee->fresh()->status);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_paid_fee_is_refused(): void
    {
        $this->fee->forceFill(['status' => 'paid', 'paid_date' => now()->toDateString()])->save();
        $this->actingAs($this->member);

        $this->submit()->assertRedirect(route('member.payments.invoice', $this->fee))->assertSessionHas('error', 'member.already_paid');
        $this->assertSame(0, Attachment::count());
    }

    public function test_a_second_slip_while_verifying_is_refused(): void
    {
        $this->verifying();
        $this->actingAs($this->member);

        $this->submit()->assertSessionHasErrors(['status' => 'member.verifying']);
        $this->assertSame(1, Attachment::count());
    }

    public function test_club_not_accepting_transfers_is_refused(): void
    {
        $this->club->update(['transfer_enabled' => false]);
        $this->actingAs($this->member);

        $this->submit()->assertSessionHasErrors(['method' => 'member.transfer_unavailable']);
        $this->assertSame('pending', $this->fee->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->club->update(['transfer_enabled' => true, 'bank_account_no' => null, 'duitnow_id' => null]);
        $this->submit()->assertSessionHasErrors('method');
    }

    public function test_pay_online_is_refused_while_verifying(): void
    {
        $fee = $this->verifying();
        $fee->forceFill(['bill_code' => 'OLD-BILL', 'gateway' => 'toyyibpay', 'bill_amount' => 50])->save();
        $this->actingAs($this->member);

        $this->post(route('member.payments.pay', $fee))
            ->assertRedirect(route('member.payments.review', $fee))
            ->assertSessionHas('error', 'member.verifying');
        $this->assertSame('OLD-BILL', $fee->fresh()->bill_code);
        // The gateway return page shows the review page's state instead.
        $this->get(route('member.payments.thankyou', $fee))->assertRedirect(route('member.payments.review', $fee));
    }

    // ── Approve ─────────────────────────────────────────────────────────

    public function test_admin_approves_a_slip(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminB);

        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString(), 'reference' => 'STMT-0091'])
            ->assertRedirect(route('admin.clubs.verifications.index', $this->club))
            ->assertSessionHasNoErrors();

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame('transfer', $fee->paid_via);
        $this->assertNull($fee->gateway);
        $this->assertSame(now()->toDateString(), $fee->paid_date->toDateString());
        $this->assertSame('STMT-0091', $fee->transfer_bank_ref);
        $this->assertSame($this->adminB->id, $fee->verified_by);
        $this->assertNotNull($fee->verified_at);
        $this->assertSame(0, $fee->fee_absorbed_sen);
        $this->assertTrue($fee->attachmentsLocked());
        Mail::assertSent(PaymentConfirmation::class, fn ($m) => $m->payment->id === $fee->id && $m->hasTo($this->member->email));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.transfer_approved', 'auditable_id' => $fee->id, 'user_id' => $this->adminB->id]);

        // Slips can no longer be added.
        $this->expectException(ValidationException::class);
        app(AttachmentService::class)->store($fee, $fee->club_id, $this->slip(), 'proof_of_payment');
    }

    public function test_approve_never_overwrites_the_gateway_reference(): void
    {
        $fee = $this->verifying();
        // An earlier FPX attempt: the webhook finds the fee by this reference.
        $fee->forceFill(['reference' => 'PAY-'.$fee->id.'-1700000000'])->save();
        $this->actingAs($this->adminA);

        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString()])->assertSessionHasNoErrors();

        $fee->refresh();
        $this->assertSame('PAY-'.$fee->id.'-1700000000', $fee->reference);
        $this->assertNull($fee->transfer_bank_ref);
        // The receipt shows the member's bank ref instead of the internal bill reference.
        $this->actingAs($this->member);
        $this->get(route('member.payments.invoice', $fee))->assertInertia(fn (Assert $p) => $p
            ->where('receipt.method', 'transfer')
            ->where('receipt.reference', 'MBB 778812'));
    }

    public function test_paid_date_cannot_be_before_the_transfer_date(): void
    {
        $this->actingAs($this->member);
        $this->submit(['transfer_date' => now()->subDays(3)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->adminA);

        $this->post(route('admin.verifications.approve', $this->fee), ['paid_date' => now()->subDays(4)->toDateString()])->assertSessionHasErrors('paid_date');
        $this->assertSame('pending_verification', $this->fee->fresh()->status);
        $this->post(route('admin.verifications.approve', $this->fee), ['paid_date' => now()->subDays(3)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('paid', $this->fee->fresh()->status);
    }

    public function test_approve_validates_the_paid_date(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);

        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_date');
        $this->post(route('admin.verifications.approve', $fee), [])->assertSessionHasErrors('paid_date');
        $this->assertSame('pending_verification', $fee->fresh()->status);
    }

    public function test_a_member_cannot_approve_or_reject(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->member);

        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString()])->assertForbidden();
        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'Not received yet'])->assertForbidden();
        $this->assertSame('pending_verification', $fee->fresh()->status);
    }

    public function test_another_clubs_admin_cannot_approve_or_view_the_queue(): void
    {
        $fee = $this->verifying();
        $other = Club::factory()->create();
        $this->actingAs($this->attach(User::factory()->create(['role' => 'admin']), 'admin', $other));

        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString()])->assertForbidden();
        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'Not received yet'])->assertForbidden();
        $this->get(route('admin.clubs.verifications.index', $this->club))->assertForbidden();
        $slip = $fee->attachments()->first();
        $this->get(route('admin.attachments.show', $slip))->assertForbidden();
        $this->assertSame('pending_verification', $fee->fresh()->status);
    }

    public function test_an_admin_cannot_approve_their_own_fee_but_a_super_admin_can(): void
    {
        $own = Payment::factory()->forClub($this->club)->forMember($this->adminA)->pending()->create(['period_start' => now()->addMonth()->startOfMonth()->toDateString()]);
        $this->actingAs($this->adminA);
        $this->submit([], $own)->assertSessionHasNoErrors();
        $this->assertSame('pending_verification', $own->fresh()->status);

        $this->post(route('admin.verifications.approve', $own), ['paid_date' => now()->toDateString()])->assertForbidden();
        $this->post(route('admin.verifications.reject', $own), ['reason' => 'Changed my mind'])->assertForbidden();
        $this->assertSame('pending_verification', $own->fresh()->status);

        $this->actingAsSuperAdmin();
        $this->post(route('admin.verifications.approve', $own), ['paid_date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('paid', $own->fresh()->status);
    }

    public function test_double_approve_is_refused(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);
        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString(), 'reference' => 'FIRST'])->assertSessionHasNoErrors();

        $this->actingAs($this->adminB);
        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString(), 'reference' => 'SECOND'])->assertSessionHasErrors('status');
        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'Too late now'])->assertSessionHasErrors('status');

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame('FIRST', $fee->transfer_bank_ref);
        $this->assertSame(1, AuditLog::where('action', 'payment.transfer_approved')->count());
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_manual_mark_paid_and_status_edit_refuse_a_verifying_fee(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);

        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment())->assertRedirect(route('admin.clubs.verifications.index', $this->club));
        $this->assertSame('pending_verification', $fee->fresh()->status);

        $edit = ['due_date' => $fee->due_date->toDateString(), 'amount' => '50.00', 'status' => 'paid', 'original_status' => 'pending_verification'];
        $this->put(route('admin.payments.update', $fee), $edit)->assertSessionHasErrors('status');
        $this->assertSame('pending_verification', $fee->fresh()->status);
        // Only the notes may change while it stays in the queue.
        $this->put(route('admin.payments.update', $fee), ['status' => 'pending_verification', 'notes' => 'Checked bank'] + $edit)->assertSessionHasNoErrors();
        $this->assertSame('Checked bank', $fee->fresh()->notes);
        $this->put(route('admin.payments.update', $fee), ['status' => 'pending_verification', 'amount' => '45.00'] + $edit)->assertSessionHasErrors('status');
        $this->put(route('admin.payments.update', $fee), ['status' => 'pending_verification', 'due_date' => now()->addMonth()->toDateString()] + $edit)->assertSessionHasErrors('status');
        $this->assertSame('50.00', (string) $fee->fresh()->amount);

        // Nobody can put a fee into the queue by hand.
        $other = Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['period_start' => now()->addMonths(2)->startOfMonth()->toDateString()]);
        $this->put(route('admin.payments.update', $other), ['status' => 'pending_verification', 'original_status' => 'pending'] + $edit)->assertSessionHasErrors('status');
        $this->assertSame('pending', $other->fresh()->status);

        // Slips are evidence: the fee can't be deleted.
        $this->delete(route('admin.payments.destroy', $fee))->assertSessionHas('error');
        $this->assertNotNull($fee->fresh());
    }

    // ── Reject ──────────────────────────────────────────────────────────

    public function test_reject_needs_a_reason(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);

        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->post(route('admin.verifications.reject', $fee), [])->assertSessionHasErrors('reason');
        $this->assertSame('pending_verification', $fee->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_reject_sends_the_fee_back_to_pending_and_the_member_can_resubmit(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);

        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'No RM 50.00 transfer reached the club account.'])->assertSessionHasNoErrors();

        $fee->refresh();
        $this->assertSame('pending', $fee->status);
        $this->assertSame('No RM 50.00 transfer reached the club account.', $fee->rejection_reason);
        $this->assertSame($this->adminA->id, $fee->verified_by);
        $this->assertFalse($fee->attachmentsLocked());
        $this->assertTrue($fee->attachments()->first()->isFrozen(), 'the rejected slip stays as history');
        Mail::assertSent(TransferRejected::class, fn ($m) => $m->payment->id === $fee->id && $m->hasTo($this->member->email));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.transfer_rejected', 'auditable_id' => $fee->id]);

        // The member sees the reason, then sends a new slip.
        $this->actingAs($this->member);
        $this->get(route('member.payments.review', $fee))->assertInertia(fn (Assert $p) => $p
            ->where('rejection.reason', 'No RM 50.00 transfer reached the club account.')
            ->where('verification', null)
            ->where('transfer.reference', 'YURAN-'.$fee->id));

        $this->travel(2)->seconds();
        $this->submit(['files' => [$this->slip('slip-baru.png')]])->assertSessionHasNoErrors();
        $fee->refresh();
        $this->assertSame('pending_verification', $fee->status);
        $this->assertNull($fee->rejection_reason);
        $this->assertSame(2, $fee->attachments()->count());
        $this->assertSame(['slip-baru.png'], $fee->currentSlips()->pluck('original_name')->all());
    }

    // ── Member pages and the slip route ─────────────────────────────────

    public function test_review_page_offers_the_transfer_and_then_shows_the_verifying_state(): void
    {
        $this->actingAs($this->member);
        $this->get(route('member.payments.review', $this->fee))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Member/ReviewPay')
            ->where('transfer.bankName', 'Bank Contoh')
            ->where('transfer.accountNo', '1234 5678 9012')
            ->where('transfer.reference', 'YURAN-'.$this->fee->id)
            ->where('transfer.maxFiles', 3)
            ->where('verification', null)
            ->where('payment.status', 'due'));

        $this->submit();
        $this->get(route('member.payments.review', $this->fee))->assertInertia(fn (Assert $p) => $p
            ->where('payment.status', 'verifying')
            ->where('verification.transferRef', 'MBB 778812')
            ->has('verification.slips', 1));
        $this->get(route('member.payments.invoice', $this->fee))->assertInertia(fn (Assert $p) => $p->component('Member/Invoice')->where('payment.status', 'verifying'));
    }

    public function test_review_page_hides_the_transfer_when_the_club_does_not_take_it(): void
    {
        $this->club->update(['transfer_enabled' => false]);
        $this->actingAs($this->member);

        $this->get(route('member.payments.review', $this->fee))->assertInertia(fn (Assert $p) => $p->where('transfer', null));
    }

    public function test_member_home_leaves_verifying_fees_out_of_the_amount_due(): void
    {
        $this->verifying();
        Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['amount' => 30.00, 'period_start' => now()->addMonth()->startOfMonth()->toDateString()]);
        $this->actingAs($this->member);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $p) => $p
            ->component('Member/Home')
            ->where('outstanding.total', 3000)
            ->where('outstanding.count', 1)
            ->where('outstanding.verifyingCount', 1)
            ->where('upcoming.0.status', 'due')
            ->where('upcoming.1.status', 'verifying'));
    }

    public function test_slip_route_is_owner_only_and_audited(): void
    {
        $fee = $this->verifying();
        $slip = $fee->attachments()->first();

        $this->actingAs($this->member);
        $res = $this->get(route('member.payments.slip', $slip))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.viewed', 'auditable_type' => Attachment::class, 'auditable_id' => $slip->id, 'user_id' => $this->member->id]);

        $this->actingAs($this->attach(User::factory()->create(['role' => 'member']), 'member'));
        $this->get(route('member.payments.slip', $slip))->assertForbidden();
        // Even a club admin uses the admin route, not the member one.
        $this->actingAs($this->adminA);
        $this->get(route('member.payments.slip', $slip))->assertForbidden();
        $this->get(route('admin.attachments.show', $slip))->assertOk();
    }

    // ── Admin pages ─────────────────────────────────────────────────────

    public function test_verifications_page_dashboard_panel_and_nav_badge(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);

        $this->get(route('admin.clubs.verifications.index', $this->club))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Verifications/Index')
            ->has('pending', 1)
            ->where('pending.0.id', $fee->id)
            ->where('pending.0.member.name', 'Ahli Contoh')
            ->where('pending.0.amount', 5000)
            ->where('pending.0.expectedRef', 'YURAN-'.$fee->id)
            ->where('pending.0.transferRef', 'MBB 778812')
            ->where('pending.0.blocker', null)
            ->has('pending.0.slips', 1)
            ->where('pending.0.slips.0.kind', 'proof_of_payment')
            ->has('handled', 0)
            ->where('nav.groups.0.items.2.key', 'verifications')
            ->where('nav.groups.0.items.2.badge', 1));

        $this->get(route('admin.clubs.dashboard', $this->club))->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Dashboard')
            ->where('slips.count', 1)
            ->where('slips.items.0.id', $fee->id));

        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'Wrong account number used.']);
        $this->get(route('admin.clubs.verifications.index', $this->club))->assertInertia(fn (Assert $p) => $p
            ->has('pending', 0)
            ->has('handled', 1)
            ->where('handled.0.outcome', 'rejected')
            ->where('handled.0.reason', 'Wrong account number used.')
            ->where('handled.0.by', 'Admin A'));
    }

    // ── Checker follow-ups ──────────────────────────────────────────────

    private function oldSlips(Payment $fee, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $fee->attachments()->create([
                'club_id' => $fee->club_id, 'kind' => 'proof_of_payment', 'original_name' => "old-{$i}.png",
                'path' => "clubs/{$fee->club_id}/attachments/old-{$i}.bin", 'mime' => 'image/png', 'size' => 10,
                'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->member->id, 'frozen_at' => now()->subDay(),
            ]);
        }
    }

    public function test_rejected_slips_do_not_count_against_the_general_document_cap(): void
    {
        // More than Attachment::MAX_FILES (10) earlier, rejected slips.
        $this->oldSlips($this->fee, 12);
        $this->actingAs($this->member);

        $this->submit(['files' => [$this->slip('a.png'), $this->slip('b.png'), $this->slip('c.png')]])->assertSessionHasNoErrors();
        $this->assertSame('pending_verification', $this->fee->fresh()->status);
        $this->assertSame(15, $this->fee->attachments()->count());
    }

    public function test_a_fee_has_a_hard_ceiling_of_slips_with_a_member_message(): void
    {
        $this->oldSlips($this->fee, 28);
        $this->actingAs($this->member);

        $this->submit(['files' => [$this->slip('a.png'), $this->slip('b.png'), $this->slip('c.png')]])->assertSessionHasErrors(['files' => 'member.slip_limit']);
        $this->assertSame('pending', $this->fee->fresh()->status);
        $this->submit(['files' => [$this->slip('a.png'), $this->slip('b.png')]])->assertSessionHasNoErrors();
    }

    public function test_verifying_has_its_own_bucket_in_every_summary(): void
    {
        $this->verifying();
        Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['amount' => 30.00, 'period_start' => now()->addMonth()->startOfMonth()->toDateString(), 'due_date' => now()->addDays(3)->toDateString()]);
        Payment::factory()->forClub($this->club)->forMember($this->member)->create(['amount' => 20.00, 'period_start' => now()->subMonth()->startOfMonth()->toDateString(), 'due_date' => now()->addDays(2)->toDateString()]);

        $this->actingAs($this->member);
        $this->get(route('member.payments.index', [$this->club, 'year' => now()->year]))->assertInertia(fn (Assert $p) => $p
            ->where('summary.paid', 2000)
            ->where('summary.due', 3000)
            ->where('summary.overdue', 0)
            ->where('summary.verifying', 5000)
            ->where('summary.total', 10000));
        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('clubs.0.summary.paid', 1)
            ->where('clubs.0.summary.due', 1)
            ->where('clubs.0.summary.verifying', 1));

        $this->actingAs($this->adminA);
        $this->get(route('admin.clubs.dashboard.classic', $this->club))->assertOk()
            ->assertViewHas('verifyingCount', 1)
            ->assertViewHas('pendingCount', 1)
            ->assertViewHas('paidCount', 1);
    }

    public function test_manual_mark_paid_rechecks_the_status(): void
    {
        $this->actingAs($this->adminA);
        $paid = Payment::factory()->forClub($this->club)->forMember($this->member)->create(['period_start' => now()->subMonths(2)->startOfMonth()->toDateString(), 'reference' => 'ORIGINAL']);

        $this->patch(route('admin.payments.mark-paid', $paid), $this->recordPayment(['reference' => 'AGAIN']))->assertSessionHas('error');
        $this->assertSame('ORIGINAL', $paid->fresh()->reference);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment.marked_paid', 'auditable_id' => $paid->id]);

        $this->patch(route('admin.payments.mark-paid', $this->fee), $this->recordPayment(['reference' => 'CASH-1']))->assertSessionHas('success');
        $this->assertSame('paid', $this->fee->fresh()->status);
        $log = AuditLog::where('action', 'payment.marked_paid')->where('auditable_id', $this->fee->id)->firstOrFail();
        $this->assertSame('pending', $log->old_values['status']);
    }

    public function test_admin_payment_page_lists_every_slip_with_its_outcome(): void
    {
        $fee = $this->verifying();
        $this->actingAs($this->adminA);
        $this->post(route('admin.verifications.reject', $fee), ['reason' => 'Wrong account number used.']);
        $this->actingAs($this->member);
        $this->travel(2)->seconds();
        $this->submit(['files' => [$this->slip('second.png')]]);
        $this->actingAs($this->adminB);
        $this->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString(), 'reference' => 'STMT-7']);

        $first = $fee->attachments()->where('original_name', 'slip.png')->firstOrFail();
        $this->get(route('admin.payments.show', $fee))->assertOk()
            ->assertSee('Transfer slips (2)')
            ->assertSee('second.png')
            ->assertSee('Earlier, rejected')
            ->assertSee('Approved')
            ->assertSee('STMT-7')
            ->assertSee(route('admin.attachments.show', ['attachment' => $first, 'inline' => 1]), false);
    }
}
