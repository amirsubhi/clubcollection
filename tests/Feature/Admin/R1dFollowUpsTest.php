<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Support\Admin\FundFigures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R1c follow-ups picked up in R1d: Statistics reads FundFigures (bank charges
 * included), reverting an online-paid fee warns and a hand re-mark keeps its
 * charge, ledger rows survive a missing member, the theme stylesheet mirrors
 * the tokens, and the classic layout copes without public/build.
 */
class R1dFollowUpsTest extends TestCase
{
    private Club $club;

    private ExpenseCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create(['name' => 'Kelab Contoh']);
        $this->cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Events']);
    }

    private function paidOnline(Club $club, float $amount, string $date, int $absorbedSen): Payment
    {
        return Payment::factory()->forClub($club)->create(['status' => 'paid', 'amount' => $amount, 'paid_date' => $date, 'due_date' => $date, 'period_start' => $date,
            'paid_via' => 'toyyibpay', 'gateway' => 'toyyibpay', 'fee_absorbed_sen' => $absorbedSen]);
    }

    private function spend(Club $club, ExpenseCategory $cat, float $amount, string $date, string $type = 'expense'): void
    {
        Expense::factory()->approved()->create(['club_id' => $club->id, 'expense_category_id' => $cat->id, 'amount' => $amount, 'expense_date' => $date, 'type' => $type]);
    }

    // ── Statistics = FundFigures ───────────────────────────────────────────

    public function test_statistics_figures_match_fund_figures_including_bank_charges(): void
    {
        $other = Club::factory()->create(['name' => 'Kelab Kedua']);
        $otherCat = ExpenseCategory::factory()->for($other)->create();

        $this->paidOnline($this->club, 50.00, '2026-03-10', 150);   // RM1.50 absorbed
        $this->paidOnline($this->club, 50.00, '2026-10-02', 100);   // RM1.00 absorbed
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 200.00, 'paid_date' => '2025-12-01', 'period_start' => '2025-12-01']);
        $this->spend($this->club, $this->cat, 40.00, '2026-04-01');
        $this->spend($this->club, $this->cat, 25.00, '2026-05-01', 'welfare');
        Payment::factory()->forClub($other)->create(['status' => 'paid', 'amount' => 80.00, 'paid_date' => '2026-09-09', 'period_start' => '2026-09-01']);
        $this->spend($other, $otherCat, 10.00, '2026-09-10');
        // Never counted: awaiting approval.
        Expense::factory()->submitted()->create(['club_id' => $this->club->id, 'expense_category_id' => $this->cat->id, 'amount' => 999.00, 'expense_date' => '2026-06-01']);

        $this->actingAsSuperAdmin();
        $res = $this->get(route('admin.statistics'))->assertOk();

        $want = ['collected' => 0, 'spent' => 0, 'expenses' => 0, 'welfare' => 0, 'charges' => 0, 'balance' => 0];
        foreach ([$this->club, $other] as $club) {
            $f = FundFigures::for($club);
            $split = $f->spentSplitBetween(Carbon::create(2000, 1, 1), Carbon::create(2026, 11, 16));
            $want['collected'] += $f->collectedBetween(Carbon::create(2000, 1, 1), Carbon::create(2026, 11, 16));
            foreach (['spent', 'expenses', 'welfare', 'charges'] as $k) {
                $want[$k] += $split[$k];
            }
            $want['balance'] += $f->balance();

            $row = $res->viewData('clubStats')->firstWhere('id', $club->id);
            $this->assertSame($f->balance(), $row->balance_sen, "{$club->name}: balance matches the club's own figures");
            $this->assertSame($split['spent'], $row->spent_sen);
        }

        $totals = $res->viewData('totals');
        $this->assertSame($want, $totals);
        // Spot-check the fixture: charges RM2.50 are in spent.
        $this->assertSame(250, $totals['charges']);
        $this->assertSame(4000 + 2500 + 250 + 1000, $totals['spent']);
        $this->assertSame(38000 - 7750, $totals['balance']);
        // The monthly chart adds the clubs' FundFigures::monthly() rows.
        $monthly = collect(FundFigures::for($this->club)->monthly(12))->zip(FundFigures::for($other)->monthly(12))
            ->map(fn ($p) => $p[0]['spent'] + $p[1]['spent'])->all();
        $this->assertSame($monthly, array_column($res->viewData('monthly'), 'spent'));

        $res->assertSee('Bank / gateway charges RM 2.50')
            ->assertSee('RM 77.50', false);
        // The trend chart has a text alternative (aria-label and a table); the rest are labelled
        // horizontal bars with their figures as text (no donuts: RESEARCH.md §3).
        $html = $res->getContent();
        $this->assertSame(1, preg_match_all('/<canvas[^>]*role="img"[^>]*aria-label="[^"]+"/', $html));
        $this->assertStringContainsString('data-testid="trend-table"', $html);
        $this->assertStringNotContainsString('doughnut', $html);
        foreach (['status-bars', 'club-bars', 'level-bars'] as $bars) {
            $this->assertStringContainsString('data-testid="'.$bars.'"', $html);
        }
    }

    // ── Reverting an online-paid fee ──────────────────────────────────────

    public function test_reverting_an_online_paid_fee_warns_and_a_hand_re_mark_keeps_the_charge(): void
    {
        $fee = $this->paidOnline($this->club, 50.00, '2026-11-02', 120);
        $this->actingAsClubAdmin($this->club);
        $f = FundFigures::for($this->club);
        $ytd = FundFigures::yearToDate();
        $this->assertSame(120, $f->forWindow($ytd)['charges']);

        // The edit form says what reverting does to the figures, and asks first.
        $this->get(route('admin.payments.edit', $fee))->assertOk()
            ->assertSee('data-testid="revert-charge-warning"', false)
            ->assertSee('RM 1.20')
            ->assertSee('data-revert-warning=', false);

        // Revert to pending: the charge leaves the figures with the fee.
        $this->put(route('admin.payments.update', $fee), [
            'amount' => '50.00', 'due_date' => '2026-11-02', 'status' => 'pending', 'original_status' => 'paid',
        ])->assertRedirect(route('admin.payments.show', $fee));
        $fee->refresh();
        $this->assertNull($fee->paid_via);
        $this->assertSame(120, (int) $fee->fee_absorbed_sen, 'the absorbed charge is kept on the record');
        $this->assertSame(0, $f->forWindow($ytd)['charges']);
        $this->get(route('admin.payments.edit', $fee))->assertDontSee('data-testid="revert-charge-warning"', false);

        // Marked paid again by hand: the gateway still took its charge, so it counts again.
        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment())->assertRedirect();
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(120, $f->forWindow($ytd)['charges']);
        $this->assertSame(5000 - 120, $f->balance());

        // The ledger agrees with FundFigures.
        $this->get(route('admin.clubs.ledger', $this->club).'?from=2026-01-01&to=2026-11-16')
            ->assertInertia(fn (Assert $page) => $page->where('summary.outCharges', 120)->where('summary.closing', $f->balance()));
    }

    public function test_a_fee_paid_by_transfer_shows_no_revert_warning(): void
    {
        $fee = Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'paid_via' => 'transfer', 'fee_absorbed_sen' => 0]);
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.payments.edit', $fee))->assertOk()
            ->assertDontSee('data-testid="revert-charge-warning"', false)
            ->assertDontSee('data-revert-warning=', false);
    }

    // ── Former members ────────────────────────────────────────────────────

    public function test_ledger_rows_name_a_missing_member_former_member(): void
    {
        $gone = User::factory()->create(['name' => 'Ahli Lama']);
        $paid = Payment::factory()->forClub($this->club)->forMember($gone)->create(['status' => 'paid', 'amount' => 30.00, 'paid_date' => '2026-06-01', 'period_start' => '2026-06-01']);
        Payment::factory()->forClub($this->club)->forMember($gone)->create(['status' => 'overdue', 'amount' => 30.00, 'paid_date' => null, 'period_start' => '2026-07-01', 'due_date' => '2026-07-31']);
        // The fee rows point at a user that no longer exists (as if removed
        // outside the app). SQLite checks deferred keys only at commit, and the
        // test transaction is rolled back; MySQL / MariaDB skip the check while
        // FOREIGN_KEY_CHECKS is off.
        $orphan = fn () => DB::table('payments')->where('user_id', $gone->id)->update(['user_id' => 999999]);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $orphan();
        } else {
            Schema::withoutForeignKeyConstraints($orphan);
        }

        $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.clubs.ledger', $this->club).'?from=2026-01-01&to=2026-11-16')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.0.party', 'Former member')
                ->where('outstanding.rows.0.member', 'Former member'));

        $csv = $this->get(route('admin.clubs.ledger.export', $this->club).'?from=2026-01-01&to=2026-11-16')->streamedContent();
        $this->assertStringContainsString('Former member', $csv);
        $this->get(route('admin.clubs.ledger.export-pdf', $this->club).'?from=2026-01-01&to=2026-11-16')->assertOk();
        $this->get(route('admin.payments.show', $paid))->assertOk()->assertSee('Former member');
        $this->get(route('admin.payments.edit', $paid))->assertOk()->assertSee('Former member');
    }

    /** Removing a member only detaches them: their paid fees stay in the ledger (AGM history). */
    public function test_removing_a_member_keeps_their_paid_fees_in_the_ledger(): void
    {
        $this->actingAsClubAdmin($this->club);
        $member = User::factory()->create(['name' => 'Ahli Berhenti']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);
        Payment::factory()->forClub($this->club)->forMember($member)->create(['status' => 'paid', 'amount' => 30.00, 'paid_date' => '2026-06-01', 'period_start' => '2026-06-01']);

        $this->delete(route('admin.members.destroy', ['member' => $member, 'club' => $this->club->id]))
            ->assertRedirect(route('admin.clubs.members.index', $this->club));
        $this->assertFalse($this->club->members()->where('users.id', $member->id)->exists());
        $this->assertNotNull(User::find($member->id));

        $this->get(route('admin.clubs.ledger', $this->club).'?from=2026-01-01&to=2026-11-16')
            ->assertInertia(fn (Assert $page) => $page->where('summary.in', 3000)->where('transactions.0.party', 'Ahli Berhenti'));
    }

    /** The members list's edit link opens that member in that club (shallow route + ?club=). */
    public function test_member_links_open_the_right_member(): void
    {
        $this->actingAsClubAdmin($this->club);
        $member = User::factory()->create(['name' => 'Siti Contoh']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);

        $url = route('admin.members.edit', ['member' => $member, 'club' => $this->club->id]);
        $this->get(route('admin.clubs.members.index', $this->club))->assertSee(e($url), false);
        $this->get($url)->assertOk()->assertSee('Siti Contoh');
    }

    /** ?club= is honoured only for a club the admin manages, so it can't reveal another club's members. */
    public function test_club_query_does_not_reveal_membership_of_other_clubs(): void
    {
        $other = Club::factory()->create(['name' => 'Kelab Lain']);
        $this->actingAsClubAdmin($this->club);
        $inBoth = User::factory()->create();
        $onlyHere = User::factory()->create();
        foreach ([$inBoth, $onlyHere] as $u) {
            $this->club->members()->attach($u, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);
        }
        $other->members()->attach($inBoth, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-01', 'is_active' => true]);

        $a = $this->get(route('admin.members.edit', ['member' => $inBoth, 'club' => $other->id]));
        $b = $this->get(route('admin.members.edit', ['member' => $onlyHere, 'club' => $other->id]));
        $this->assertSame($a->status(), $b->status());
        $a->assertOk()->assertSee('Kelab Contoh')->assertDontSee('Kelab Lain');
        $b->assertOk()->assertSee('Kelab Contoh')->assertDontSee('Kelab Lain');
    }

    /** Revert → paid by hand → revert again: the warning follows fee_absorbed_sen, as the figures do. */
    public function test_revert_warning_also_shows_after_a_hand_re_mark(): void
    {
        $fee = $this->paidOnline($this->club, 50.00, '2026-11-02', 120);
        $this->actingAsClubAdmin($this->club);
        $f = FundFigures::for($this->club);
        $ytd = FundFigures::yearToDate();
        $revert = fn () => $this->put(route('admin.payments.update', $fee), [
            'amount' => '50.00', 'due_date' => '2026-11-02', 'status' => 'pending', 'original_status' => 'paid',
        ])->assertRedirect(route('admin.payments.show', $fee));

        $revert();
        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment())->assertRedirect();
        $this->assertSame('cash', $fee->fresh()->paid_via);
        $this->assertSame(120, $f->forWindow($ytd)['charges']);
        // Paid by hand now, but the charge is in the figures: the warning still shows.
        $this->get(route('admin.payments.edit', $fee))->assertSee('data-testid="revert-charge-warning"', false)->assertSee('RM 1.20');

        $revert();
        $this->assertSame(0, $f->forWindow($ytd)['charges']);
        $this->assertSame(120, (int) $fee->fresh()->fee_absorbed_sen);
    }

    /** Super-admin pages about every club show no club group in the sidebar. */
    public function test_cross_club_pages_have_no_club_group(): void
    {
        $this->actingAsSuperAdmin();
        foreach (['admin.statistics', 'admin.clubs.index', 'admin.admins.index', 'admin.audit-logs.index'] as $name) {
            $html = $this->get(route($name))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-nav="verifications"', $html, $name);
            $this->assertStringContainsString('data-nav="statistics"', $html, $name);
        }
        // A club's own page still lists that club's items.
        $this->assertStringContainsString('data-nav="verifications"', $this->get(route('admin.clubs.members.index', $this->club))->getContent());
    }

    // ── Member card: BM label for bank charges ───────────────────────────

    public function test_member_card_gets_the_bm_name_of_the_charges_line(): void
    {
        $this->paidOnline($this->club, 50.00, '2026-03-10', 150);
        $this->actingAsMember($this->club);

        $this->get(route('member.clubs.fund', $this->club))->assertInertia(fn (Assert $page) => $page
            ->where('fund.categories', fn ($c) => collect($c)->contains(fn ($r) => $r['name'] === FundFigures::CHARGES_LABEL && $r['nameMs'] === 'Caj bank / gateway')));
    }

    // ── Theme ─────────────────────────────────────────────────────────────

    /** Every comma triplet in bootstrap-theme.css equals the token it names, in light and dark. */
    public function test_bootstrap_theme_triplets_match_the_tokens(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));
        $theme = file_get_contents(resource_path('css/bootstrap-theme.css'));

        $blocks = fn (string $css) => array_values(array_filter(preg_split('/\}\s*/', $css), fn ($b) => str_contains($b, '--')));
        $parseTokens = function (string $block) {
            preg_match_all('/--([a-z0-9-]+):\s*(\d+) (\d+) (\d+);/', $block, $m, PREG_SET_ORDER);

            return collect($m)->mapWithKeys(fn ($r) => [$r[1] => "{$r[2]}, {$r[3]}, {$r[4]}"])->all();
        };
        [$light, $darkMedia, $dark] = array_map($parseTokens, array_slice($blocks($tokens), 0, 3));
        $this->assertSame($darkMedia, $dark, 'tokens.css: both dark blocks agree');

        preg_match_all('/--bs-[a-z-]+-rgb:\s*([\d, ]+);\s*\/\* = --([a-z-]+) \*\//', $theme, $m, PREG_SET_ORDER);
        $this->assertCount(16 * 3, $m, 'three blocks of 16 triplets');
        foreach ($m as $i => [$line, $triplet, $token]) {
            $want = $i < 16 ? $light : $dark;
            $this->assertArrayHasKey($token, $want);
            $this->assertSame($want[$token], trim($triplet), "bootstrap-theme.css: {$line}");
        }
    }

    public function test_layout_sets_the_saved_theme_before_paint_with_the_nonce(): void
    {
        $this->actingAsClubAdmin($this->club);
        $html = $this->get(route('admin.clubs.members.index', $this->club))->getContent();

        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*\(function \(\) \{\s*var d = document\.documentElement[\s\S]*?cp-theme[\s\S]*?data-bs-theme/', $html);
        // The theme script runs before any stylesheet.
        // (Bootstrap now comes from the build, absent under withoutVite; the layout's own <style> is always there.)
        $this->assertLessThan(strpos($html, '<style nonce='), strpos($html, "localStorage.getItem('cp-theme')"));
        $this->assertStringNotContainsString('#0d6efd', $html);
        $this->assertStringNotContainsString('#16213e', $html);
    }

    /** No public/build (assets not uploaded): the classic pages still render, on plain Bootstrap. */
    public function test_layout_renders_without_a_vite_build(): void
    {
        $this->actingAsClubAdmin($this->club);
        $empty = sys_get_temp_dir().'/r1d-no-build-'.uniqid();
        mkdir($empty);
        $original = public_path();
        app()->usePublicPath($empty);
        try {
            $this->withVite();
            $html = $this->get(route('admin.clubs.members.index', $this->club))->assertOk()->getContent();
            $this->assertStringNotContainsString('bootstrap-theme', $html);
            $this->assertStringContainsString('<h1>Members</h1>', $html);
        } finally {
            app()->usePublicPath($original);
            rmdir($empty);
        }
    }
}
