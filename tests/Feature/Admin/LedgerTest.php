<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_date_range_over_24_months_is_refused(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // A validation error shown on the page (redirect back with errors), 422 for JSON.
        $this->from(route('admin.clubs.ledger', $club))
            ->get(route('admin.clubs.ledger', $club).'?from=2020-01-01&to=2099-12-31')
            ->assertRedirect(route('admin.clubs.ledger', $club))
            ->assertSessionHasErrors('to');
        $this->getJson(route('admin.clubs.ledger', $club).'?from=2020-01-01&to=2099-12-31')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        // Exports refuse it the same way.
        $this->getJson(route('admin.clubs.ledger.export', $club).'?from=2020-01-01&to=2099-12-31')->assertUnprocessable();
        $this->getJson(route('admin.clubs.ledger.export-pdf', $club).'?from=2020-01-01&to=2099-12-31')->assertUnprocessable();
    }

    public function test_exactly_24_months_is_allowed_and_one_day_more_is_not(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $url = route('admin.clubs.ledger', $club);

        $this->get($url.'?from=2025-01-01&to=2027-01-01')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.to', '2027-01-01')->has('monthly', 25));
        $this->getJson($url.'?from=2025-01-01&to=2027-01-02')->assertUnprocessable()->assertJsonValidationErrors('to');

        // Month ends do not overflow: 31 Dec 2024 + 24 months = 31 Dec 2026; 29 Feb 2024 + 24 = 28 Feb 2026.
        $this->get($url.'?from=2024-12-31&to=2026-12-31')->assertOk();
        $this->get($url.'?from=2024-02-29&to=2026-02-28')->assertOk();
        $this->getJson($url.'?from=2024-02-29&to=2026-03-01')->assertUnprocessable();
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

        $expense = Expense::factory()->approved()->create([
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

        Expense::factory()->approved()->create([
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
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ledger/Index')
                ->where('opening.amount', 10000)
                ->where('opening.source', 'manual')
                ->where('summary.in', 5000)
                ->where('summary.out', 3000)
                ->where('summary.closing', 12000)); // closing balance
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
