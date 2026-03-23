<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    // ── Authorization ──────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected(): void
    {
        $club = Club::factory()->create();

        $this->get(route('admin.clubs.ledger', $club))
            ->assertRedirect(route('login'));
    }

    public function test_member_cannot_access_ledger(): void
    {
        $club = Club::factory()->create();
        $this->actingAsMember($club);

        $this->get(route('admin.clubs.ledger', $club))
            ->assertForbidden();
    }

    public function test_admin_of_different_club_is_forbidden(): void
    {
        $clubA = Club::factory()->create();
        $clubB = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);

        $this->get(route('admin.clubs.ledger', $clubB))
            ->assertForbidden();
    }

    public function test_club_admin_can_view_ledger(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.ledger', $club))
            ->assertOk();
    }

    public function test_super_admin_can_view_any_club_ledger(): void
    {
        $club = Club::factory()->create();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.clubs.ledger', $club))
            ->assertOk();
    }

    // ── Input validation ───────────────────────────────────────────────────

    public function test_invalid_date_format_returns_422(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // Use getJson() so validation returns 422 JSON instead of a redirect
        $this->getJson(route('admin.clubs.ledger', $club) . '?from=not-a-date')
            ->assertUnprocessable();
    }

    public function test_negative_opening_balance_returns_422(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->getJson(route('admin.clubs.ledger', $club) . '?opening_balance=-100')
            ->assertUnprocessable();
    }

    public function test_opening_balance_above_max_returns_422(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->getJson(route('admin.clubs.ledger', $club) . '?opening_balance=9999999999')
            ->assertUnprocessable();
    }

    // ── Data filtering ─────────────────────────────────────────────────────

    public function test_paid_payments_within_range_appear_in_ledger(): void
    {
        $club   = Club::factory()->create();
        $member = User::factory()->create(['role' => 'member']);
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        $admin = $this->actingAsClubAdmin($club);

        Payment::factory()->forClub($club)->forMember($member)->create([
            'status'    => 'paid',
            'paid_date' => now()->toDateString(),
        ]);

        $this->get(route('admin.clubs.ledger', $club) . '?from=' . now()->startOfYear()->format('Y-m-d') . '&to=' . now()->format('Y-m-d'))
            ->assertOk()
            ->assertSee($member->name);
    }

    public function test_payments_outside_date_range_are_excluded(): void
    {
        $club   = Club::factory()->create();
        $member = User::factory()->create(['role' => 'member']);
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        $this->actingAsClubAdmin($club);

        Payment::factory()->forClub($club)->forMember($member)->create([
            'status'    => 'paid',
            'paid_date' => now()->subYear()->toDateString(),
            'due_date'  => now()->subYear()->toDateString(),
        ]);

        $from = now()->startOfYear()->format('Y-m-d');
        $to   = now()->format('Y-m-d');

        $this->get(route('admin.clubs.ledger', $club) . "?from={$from}&to={$to}")
            ->assertOk()
            ->assertDontSee($member->name);
    }

    public function test_pending_payments_not_shown_in_transactions(): void
    {
        $club   = Club::factory()->create();
        $member = User::factory()->create(['role' => 'member']);
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        $this->actingAsClubAdmin($club);

        Payment::factory()->forClub($club)->forMember($member)->pending()->create();

        $from = now()->startOfYear()->format('Y-m-d');
        $to   = now()->format('Y-m-d');

        // Pending appears in the Outstanding panel, not in the transaction table.
        // The page loads OK; the member name will appear in Outstanding but the
        // "Income" type badge should NOT appear for this member.
        $response = $this->get(route('admin.clubs.ledger', $club) . "?from={$from}&to={$to}");
        $response->assertOk();
        $content = $response->getContent();
        // No "Income" row for the pending payment
        $this->assertStringNotContainsString('Fee payment (monthly)', $content);
    }

    public function test_expenses_within_range_appear_in_ledger(): void
    {
        $club     = Club::factory()->create();
        $category = ExpenseCategory::factory()->for($club)->create();
        $recorder = User::factory()->create(['role' => 'admin']);

        $this->actingAsClubAdmin($club);

        $expense = Expense::factory()->create([
            'club_id'             => $club->id,
            'expense_category_id' => $category->id,
            'recorded_by'         => $recorder->id,
            'description'         => 'Office supplies purchase',
            'expense_date'        => now()->toDateString(),
        ]);

        $from = now()->startOfYear()->format('Y-m-d');
        $to   = now()->format('Y-m-d');

        $this->get(route('admin.clubs.ledger', $club) . "?from={$from}&to={$to}")
            ->assertOk()
            ->assertSee('Office supplies purchase');
    }

    public function test_closing_balance_equals_opening_plus_income_minus_expenses(): void
    {
        $club     = Club::factory()->create();
        $member   = User::factory()->create(['role' => 'member']);
        $category = ExpenseCategory::factory()->for($club)->create();
        $recorder = User::factory()->create(['role' => 'admin']);
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        $this->actingAsClubAdmin($club);

        // Opening: 100, Income: 50, Expenses: 30 → Closing: 120
        Payment::factory()->forClub($club)->forMember($member)->create([
            'status'    => 'paid',
            'paid_date' => now()->toDateString(),
            'amount'    => 50.00,
        ]);

        Expense::factory()->create([
            'club_id'             => $club->id,
            'expense_category_id' => $category->id,
            'recorded_by'         => $recorder->id,
            'amount'              => 30.00,
            'expense_date'        => now()->toDateString(),
        ]);

        $from = now()->startOfYear()->format('Y-m-d');
        $to   = now()->format('Y-m-d');

        $this->get(route('admin.clubs.ledger', $club) . "?from={$from}&to={$to}&opening_balance=100")
            ->assertOk()
            ->assertSee('120.00'); // closing balance
    }

    // ── CSV export ─────────────────────────────────────────────────────────

    public function test_csv_export_unauthenticated_redirects(): void
    {
        $club = Club::factory()->create();

        $this->get(route('admin.clubs.ledger.export', $club))
            ->assertRedirect(route('login'));
    }

    public function test_csv_export_response_headers(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $response = $this->get(route('admin.clubs.ledger.export', $club));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_csv_export_has_utf8_bom(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $response = $this->get(route('admin.clubs.ledger.export', $club));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public function test_csv_export_contains_transaction_rows(): void
    {
        $club   = Club::factory()->create();
        $member = User::factory()->create(['name' => 'UniqueTestMember99', 'role' => 'member']);
        $club->members()->attach($member, [
            'role' => 'member', 'job_level' => 'executive',
            'joined_date' => now()->toDateString(), 'is_active' => true,
        ]);

        $this->actingAsClubAdmin($club);

        Payment::factory()->forClub($club)->forMember($member)->create([
            'status'    => 'paid',
            'paid_date' => now()->toDateString(),
        ]);

        $response = $this->get(route('admin.clubs.ledger.export', $club));

        $this->assertStringContainsString('UniqueTestMember99', $response->streamedContent());
    }

    // ── PDF export ─────────────────────────────────────────────────────────

    public function test_pdf_export_returns_pdf_content_type(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $response = $this->get(route('admin.clubs.ledger.export-pdf', $club));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_export_returns_attachment_disposition(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $response = $this->get(route('admin.clubs.ledger.export-pdf', $club));

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }
}
