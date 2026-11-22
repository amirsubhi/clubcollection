<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LogoFixtures;
use Tests\TestCase;

/**
 * Settings phase 1: a receipt keeps the club details it was issued with (payments.issuer_snapshot), whichever
 * path marked the fee paid; invoices (not paid yet) show the club as it is now.
 */
class ReceiptSnapshotTest extends TestCase
{
    use LogoFixtures;

    private Club $club;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        config([
            'toyyibpay.base_url' => 'https://dev.toyyibpay.com',
            'toyyibpay.webhook_secret' => self::TOYYIB_SECRET,
        ]);

        $this->club = Club::factory()->create([
            'name' => 'Kelab Lama',
            'email' => 'lama@example.test',
            'registration_no' => 'PPM-OLD-1',
            'address' => "Alamat Lama\nKuala Lumpur",
            'logo' => Storage::disk('public')->putFileAs('club-logos', $this->upload('a.png', $this->pngBytes()), 'old-logo.png') ?: null,
            'payment_gateway' => 'toyyibpay',
            'toyyibpay_secret_key' => 'club-secret',
            'toyyibpay_category_code' => 'cat-123',
            'transfer_enabled' => true,
            'bank_name' => 'Bank Contoh',
            'bank_account_name' => 'Kelab Lama',
            'bank_account_no' => '1234 5678 9012',
        ]);
        $this->member = $this->join(User::factory()->create(['role' => 'member', 'name' => 'Ahli Contoh']));
    }

    private function join(User $user, string $role = 'member'): User
    {
        $this->club->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user;
    }

    private function fee(array $attrs = []): Payment
    {
        return Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create($attrs + ['amount' => 50.00]);
    }

    private function renameClub(): void
    {
        $this->club->forceFill([
            'name' => 'Kelab Baharu',
            'registration_no' => 'PPM-NEW-2',
            'address' => 'Alamat Baharu',
            'email' => 'baharu@example.test',
            'logo' => null,
        ])->save();
    }

    private function assertOldSnapshot(Payment $fee): void
    {
        $snap = $fee->fresh()->issuer_snapshot;
        $this->assertIsArray($snap, "Fee #{$fee->id} has no issuer snapshot.");
        // Compared per key: MySQL 8 stores JSON keys sorted.
        $this->assertSame('Kelab Lama', $snap['name']);
        $this->assertSame('PPM-OLD-1', $snap['registration_no']);
        $this->assertSame("Alamat Lama\nKuala Lumpur", $snap['address']);
        $this->assertSame('lama@example.test', $snap['email']);
        $this->assertSame('club-logos/old-logo.png', $snap['logo']);
    }

    // ── Every path that marks a fee paid writes it once ─────────────────

    public function test_an_unpaid_fee_has_no_snapshot(): void
    {
        $this->assertNull($this->fee()->issuer_snapshot);
    }

    public function test_a_gateway_callback_writes_it(): void
    {
        $this->fakeToyyibConfirm();
        $fee = $this->fee(['bill_code' => 'BILL-1', 'reference' => 'REF-1']);

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken('REF-1'), [
            'billcode' => 'BILL-1', 'order_id' => 'REF-1', 'status_id' => 1, 'transaction_id' => 'TXN-1',
        ])->assertOk();

        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertOldSnapshot($fee);
    }

    public function test_a_group_bill_writes_it_on_every_fee(): void
    {
        $fees = [
            $this->fee(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-08-01', 'amount' => 10.00]),
            $this->fee(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-01', 'amount' => 20.00]),
        ];
        Http::fake(['dev.toyyibpay.com/index.php/api/createBill' => Http::response([['BillCode' => 'GRP1']])]);
        $this->actingAs($this->member)->post(route('member.payments.pay-all', $this->club), [
            'payment_ids' => [$fees[0]->id, $fees[1]->id],
            'total_sen' => 3000,
        ])->assertRedirect('https://dev.toyyibpay.com/GRP1');
        $bill = PaymentBill::where('bill_code', 'GRP1')->sole();
        $this->fakeToyyibConfirm();
        auth()->logout();

        $this->post(route('webhook.toyyibpay').'?webhook_token='.$this->toyyibToken($bill->reference), [
            'billcode' => 'GRP1', 'order_id' => $bill->reference, 'status_id' => 1, 'transaction_id' => 'TXN-G', 'amount' => '30.00',
        ])->assertOk();

        foreach ($fees as $fee) {
            $this->assertSame('paid', $fee->fresh()->status);
            $this->assertOldSnapshot($fee);
        }

        // The group receipt names the club as it was, after the profile changed.
        $this->renameClub();
        $this->actingAs($this->member)->get(route('member.payments.group-result', $bill))
            ->assertInertia(fn (Assert $page) => $page->component('Member/PayResult')
                ->where('outcome', 'paid')
                ->where('receipt.issuer.name', 'Kelab Lama')
                ->where('receipt.issuer.registrationNo', 'PPM-OLD-1')
                ->where('receipt.issuer.logoUrl', asset('storage/club-logos/old-logo.png')));
    }

    public function test_a_transfer_approval_writes_it(): void
    {
        $fee = $this->fee();
        $admin = $this->join(User::factory()->create(['role' => 'admin']), 'admin');

        $this->actingAs($this->member)->post(route('member.payments.transfer', $fee), [
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => now()->toDateString(),
            'transfer_ref' => 'MBB 1',
        ])->assertSessionHasNoErrors();
        $this->assertNull($fee->fresh()->issuer_snapshot);

        $this->actingAs($admin)->post(route('admin.verifications.approve', $fee), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertOldSnapshot($fee);
    }

    public function test_a_group_transfer_slip_approval_writes_it_on_every_fee(): void
    {
        $fees = [
            $this->fee(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-08-01', 'amount' => 10.00]),
            $this->fee(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-01', 'amount' => 20.00]),
        ];
        $admin = $this->join(User::factory()->create(['role' => 'admin']), 'admin');

        $this->actingAs($this->member)->post(route('member.payments.transfer-all', $this->club), [
            'payment_ids' => [$fees[0]->id, $fees[1]->id],
            'total_sen' => 3000,
            'files' => [UploadedFile::fake()->image('slip.png', 600, 800)],
            'transfer_date' => now()->subDay()->toDateString(),
            'transfer_ref' => 'MBB 2',
        ])->assertSessionHasNoErrors();
        $group = PaymentBill::where('method', 'transfer')->sole();

        $this->actingAs($admin)->post(route('admin.verifications.approve', $fees[1]), ['paid_date' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        foreach ($fees as $fee) {
            $this->assertSame('paid', $fee->fresh()->status);
            $this->assertOldSnapshot($fee);
        }

        $this->renameClub();
        $this->actingAs($this->member)->get(route('member.payments.group-result', $group))
            ->assertInertia(fn (Assert $page) => $page->where('outcome', 'paid')
                ->where('receipt.method', 'transfer')
                ->where('receipt.issuer.name', 'Kelab Lama')
                ->where('receipt.issuer.address', "Alamat Lama\nKuala Lumpur"));
    }

    public function test_an_admin_recording_it_writes_it(): void
    {
        $fee = $this->fee();
        $this->actingAsClubAdmin($this->club);

        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment())->assertSessionHasNoErrors();

        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertOldSnapshot($fee);
    }

    public function test_a_fee_taken_back_from_paid_gets_a_fresh_snapshot_when_paid_again(): void
    {
        $fee = $this->fee();
        $fee->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->assertSame('Kelab Lama', $fee->fresh()->issuer_snapshot['name']);

        $fee->fresh()->update(['status' => 'pending', 'paid_date' => null]);
        $this->assertNull($fee->fresh()->issuer_snapshot, 'no longer a receipt');

        $this->club->forceFill(['name' => 'Kelab Baharu', 'registration_no' => 'PPM-NEW-2'])->save();
        $fee->fresh()->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->assertSame('Kelab Baharu', $fee->fresh()->issuer_snapshot['name']);
        $this->assertSame('PPM-NEW-2', $fee->fresh()->issuer_snapshot['registration_no']);
    }

    public function test_a_fee_created_as_paid_gets_it(): void
    {
        $fee = Payment::factory()->forClub($this->club)->forMember($this->member)->create();

        $this->assertOldSnapshot($fee);
    }

    public function test_it_is_never_overwritten(): void
    {
        $fee = $this->fee();
        $fee->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->renameClub();

        // Later saves while it stays paid keep the first snapshot (a revert starts over: see the test below).
        $fee->update(['notes' => 'checked']);
        $fee->fresh()->update(['paid_date' => now()->subDay()->toDateString()]);

        $this->assertOldSnapshot($fee);
    }

    public function test_it_cannot_be_mass_assigned(): void
    {
        $this->assertNotContains('issuer_snapshot', (new Payment)->getFillable());
    }

    // ── What members see ─────────────────────────────────────────────────

    public function test_the_receipt_shows_the_snapshot_and_an_open_invoice_the_live_club(): void
    {
        $paid = $this->fee();
        $paid->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $open = $this->fee(['period_start' => now()->addMonth()->startOfMonth()->toDateString()]);
        $this->renameClub();

        $this->actingAs($this->member)->get(route('member.payments.invoice', $paid))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Invoice')
                ->where('receipt.issuer.name', 'Kelab Lama')
                ->where('receipt.issuer.registrationNo', 'PPM-OLD-1')
                ->where('receipt.issuer.address', "Alamat Lama\nKuala Lumpur")
                ->where('receipt.issuer.email', 'lama@example.test')
                // The old logo file was kept, so the receipt still shows it.
                ->where('receipt.issuer.logoUrl', asset('storage/club-logos/old-logo.png')));

        $this->actingAs($this->member)->get(route('member.payments.invoice', $open))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Invoice')
                ->where('receipt', null)
                ->where('payClub.name', 'Kelab Baharu')
                ->where('payClub.registrationNo', 'PPM-NEW-2')
                ->where('payClub.address', 'Alamat Baharu')
                ->where('payClub.logoUrl', null));
    }

    public function test_an_older_receipt_without_a_snapshot_shows_the_club_as_it_is_now(): void
    {
        $paid = $this->fee();
        $paid->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        // As a fee paid before this release: no snapshot.
        DB::table('payments')->where('id', $paid->id)->update(['issuer_snapshot' => null]);
        $this->renameClub();

        $this->actingAs($this->member)->get(route('member.payments.invoice', $paid))
            ->assertInertia(fn (Assert $page) => $page
                ->where('receipt.issuer.name', 'Kelab Baharu')
                ->where('receipt.issuer.registrationNo', 'PPM-NEW-2')
                ->where('receipt.issuer.logoUrl', null));
    }
}
