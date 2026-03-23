<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\User;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    // ── Index ──────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_club_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.payments.index', $club))->assertOk();
    }

    public function test_index_filters_by_status(): void
    {
        $club   = Club::factory()->create();
        $admin  = $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        Payment::factory()->forClub($club)->forMember($member)->overdue()->create();
        Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        $response = $this->get(route('admin.clubs.payments.index', $club) . '?status=overdue');
        $response->assertOk();
        $response->assertViewHas('payments', fn($p) => $p->every(fn($pay) => $pay->status === 'overdue'));
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function test_store_creates_pending_payment(): void
    {
        $club   = Club::factory()->create();
        $admin  = $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'monthly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-01-31',
            'amount'       => 100.00,
        ])->assertRedirect(route('admin.clubs.payments.index', $club));

        $this->assertDatabaseHas('payments', [
            'club_id' => $club->id,
            'user_id' => $member->id,
            'status'  => 'pending',
            'amount'  => 100.00,
        ]);
    }

    public function test_store_rejects_member_not_in_club(): void
    {
        $club      = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $outsider  = User::factory()->create();

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $outsider->id,
            'frequency'    => 'monthly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-01-31',
            'amount'       => 100.00,
        ])->assertSessionHasErrors('user_id');
    }

    public function test_store_rejects_discount_from_another_club(): void
    {
        $club       = Club::factory()->create();
        $otherClub  = Club::factory()->create();
        $admin      = $this->actingAsClubAdmin($club);
        $member     = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $discount   = Discount::factory()->for($otherClub)->create();

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'monthly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-01-31',
            'amount'       => 100.00,
            'discount_id'  => $discount->id,
        ])->assertSessionHasErrors('discount_id');
    }

    public function test_store_validates_frequency_enum(): void
    {
        $club   = Club::factory()->create();
        $admin  = $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'weekly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-01-31',
            'amount'       => 100.00,
        ])->assertSessionHasErrors('frequency');
    }

    // ── Period end computation ─────────────────────────────────────────────

    public function test_period_end_computed_correctly_for_monthly(): void
    {
        $club   = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'monthly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-01-31',
            'amount'       => 50.00,
        ]);

        $payment = \App\Models\Payment::where('club_id', $club->id)->latest('id')->first();
        $this->assertEquals('2025-01-31', $payment->period_end->toDateString());
    }

    public function test_period_end_computed_correctly_for_quarterly(): void
    {
        $club   = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'quarterly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-03-31',
            'amount'       => 150.00,
        ]);

        $payment = \App\Models\Payment::where('club_id', $club->id)->latest('id')->first();
        $this->assertEquals('2025-03-31', $payment->period_end->toDateString());
    }

    public function test_period_end_computed_correctly_for_yearly(): void
    {
        $club   = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        $this->post(route('admin.clubs.payments.store', $club), [
            'user_id'      => $member->id,
            'frequency'    => 'yearly',
            'period_start' => '2025-01-01',
            'due_date'     => '2025-12-31',
            'amount'       => 600.00,
        ]);

        $payment = \App\Models\Payment::where('club_id', $club->id)->latest('id')->first();
        $this->assertEquals('2025-12-31', $payment->period_end->toDateString());
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function test_update_changes_status_to_paid_and_auto_fills_paid_date(): void
    {
        $club    = Club::factory()->create();
        $admin   = $this->actingAsClubAdmin($club);
        $member  = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        $this->patch(route('admin.payments.update', $payment), [
            'due_date' => $payment->due_date,
            'amount'   => $payment->amount,
            'status'   => 'paid',
        ])->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'id'       => $payment->id,
            'status'   => 'paid',
        ]);
        $this->assertNotNull($payment->fresh()->paid_date);
    }

    // ── Mark Paid ──────────────────────────────────────────────────────────

    public function test_mark_paid_sets_status_and_paid_date(): void
    {
        $club    = Club::factory()->create();
        $admin   = $this->actingAsClubAdmin($club);
        $member  = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        $this->patch(route('admin.payments.mark-paid', $payment))
            ->assertRedirect();

        $fresh = $payment->fresh();
        $this->assertEquals('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_date);
    }

    public function test_mark_paid_stores_reference(): void
    {
        $club    = Club::factory()->create();
        $admin   = $this->actingAsClubAdmin($club);
        $member  = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        $this->patch(route('admin.payments.mark-paid', $payment), ['reference' => 'TXN-12345'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'reference' => 'TXN-12345']);
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function test_destroy_deletes_payment(): void
    {
        $club    = Club::factory()->create();
        $admin   = $this->actingAsClubAdmin($club);
        $member  = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($club)->forMember($member)->create();

        $this->delete(route('admin.payments.destroy', $payment))
            ->assertRedirect();

        $this->assertModelMissing($payment);
    }

    // ── Cross-club authorization ───────────────────────────────────────────

    public function test_show_returns_403_for_wrong_club_admin(): void
    {
        $clubA   = Club::factory()->create();
        $clubB   = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);

        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($clubB)->forMember($memberB)->create();

        $this->get(route('admin.payments.show', $payment))->assertForbidden();
    }

    public function test_destroy_returns_403_for_wrong_club_admin(): void
    {
        $clubA   = Club::factory()->create();
        $clubB   = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);

        $memberB = User::factory()->create();
        $clubB->members()->attach($memberB, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $payment = Payment::factory()->forClub($clubB)->forMember($memberB)->create();

        $this->delete(route('admin.payments.destroy', $payment))->assertForbidden();
    }
}
