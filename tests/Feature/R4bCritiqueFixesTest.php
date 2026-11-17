<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProfileController;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R4b critique fixes: admin-styled 403/404 for admins on /admin URLs (members and guests keep the branded BM page),
 * admin Payments row actions and summary scope, the member fund page, friendly audit descriptions, recovery codes.
 */
class R4bCritiqueFixesTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create(['name' => 'Kelab Contoh']);
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    // ── Admin error pages ───────────────────────────────────────────────

    /** An admin who has opened an admin page (auth.session stores the password hash, as after a real login). */
    private function signedInAdmin(): User
    {
        $admin = $this->actingAsClubAdmin($this->club);
        $this->get(route('admin.clubs.audit-logs', $this->club))->assertOk();

        return $admin;
    }

    public function test_admin_on_an_unknown_admin_url_gets_the_admin_styled_404(): void
    {
        $this->signedInAdmin();

        $res = $this->get('/admin/no-such-page')->assertNotFound();
        $res->assertSee('data-variant="admin"', false)
            ->assertSee('Page not found')
            ->assertSee('Go to the admin dashboard')
            ->assertSee('id="adminSidebar"', false)
            ->assertDontSee('Halaman tidak dijumpai');

        // A record that no longer exists (route-model binding) gets the same page.
        $this->get('/admin/payments/999999')->assertNotFound()->assertSee('data-variant="admin"', false);
    }

    public function test_admin_of_another_club_gets_the_admin_styled_403(): void
    {
        $other = Club::factory()->create();
        $this->signedInAdmin();

        $this->get(route('admin.clubs.audit-logs', $other))->assertForbidden()
            ->assertSee('data-variant="admin"', false)
            ->assertSee('No access to this page')
            ->assertDontSee('Tiada akses');
    }

    public function test_members_and_guests_keep_the_branded_page(): void
    {
        $this->get('/admin/no-such-page')->assertNotFound()
            ->assertDontSee('data-variant="admin"', false)
            ->assertSee('Halaman tidak dijumpai');

        $this->actingAsMember($this->club);
        $this->get('/admin/no-such-page')->assertNotFound()
            ->assertDontSee('data-variant="admin"', false)
            ->assertSee('Halaman tidak dijumpai');
        // A member who opens an admin page is refused with the branded page.
        $this->get(route('admin.clubs.audit-logs', $this->club))->assertForbidden()->assertDontSee('data-variant="admin"', false);

        // An admin outside /admin (e.g. a member URL) also gets the branded page.
        $this->actingAsClubAdmin($this->club);
        $this->get('/my/no-such-page')->assertNotFound()->assertDontSee('data-variant="admin"', false);
    }

    public function test_admin_page_needs_two_factor_and_a_current_password_hash(): void
    {
        // 2FA enabled but not completed in this session: branded page.
        $admin = $this->signedInAdmin();
        $admin->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $this->get('/admin/no-such-page')->assertNotFound()->assertDontSee('data-variant="admin"', false)->assertSee('Halaman tidak dijumpai');
        $this->withSession(['two_factor_verified' => true])->get('/admin/no-such-page')->assertSee('data-variant="admin"', false);

        // The password changed elsewhere (stale session hash), or no hash at all: branded page.
        $this->withSession(['password_hash_web' => 'stale'])->get('/admin/no-such-page')->assertDontSee('data-variant="admin"', false);
        $fresh = User::factory()->create(['role' => 'admin']);
        $this->club->members()->attach($fresh, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $this->flushSession();
        $this->actingAs($fresh)->get('/admin/no-such-page')->assertNotFound()->assertDontSee('data-variant="admin"', false);
    }

    public function test_admin_page_falls_back_to_the_branded_page_when_the_layout_fails(): void
    {
        $this->signedInAdmin();
        \Illuminate\Support\Facades\View::composer('errors._admin', fn () => throw new \RuntimeException('database down'));

        $this->get('/admin/no-such-page')->assertNotFound()
            ->assertDontSee('data-variant="admin"', false)
            ->assertSee('Halaman tidak dijumpai');
    }

    public function test_unknown_urls_are_404_for_every_method_and_start_no_session_for_guests(): void
    {
        $this->post('/no-such')->assertNotFound();
        $this->delete('/admin/zzz')->assertNotFound();
        $this->put('/my/zzz')->assertNotFound();
        // GET on a POST-only path is still a 404 outside local (bootstrap/app.php).
        $fee = Payment::factory()->forClub($this->club)->create();
        $this->get('/admin/payments/'.$fee->id.'/mark-paid')->assertNotFound();

        // A guest's mistyped URL starts no session (no session cookie, no session row).
        $res = $this->get('/no-such-page-at-all')->assertNotFound()->assertSee('Halaman tidak dijumpai');
        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie')));

        $this->signedInAdmin();
        $this->delete('/admin/zzz')->assertNotFound()->assertSee('data-variant="admin"', false);
    }

    // ── Admin Payments ──────────────────────────────────────────────────

    public function test_payments_rows_have_one_context_action_and_view_under_more(): void
    {
        $this->actingAsClubAdmin($this->club);
        $nov = ['period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'paid_date' => null];
        $due = Payment::factory()->forClub($this->club)->create($nov + ['status' => 'pending', 'due_date' => '2026-11-20']);
        $slip = Payment::factory()->forClub($this->club)->create($nov + ['status' => 'pending_verification', 'due_date' => '2026-11-20']);
        $paid = Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'paid_date' => '2026-11-02', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_date' => '2026-11-20']);

        $x = $this->dom($this->get(route('admin.clubs.payments.index', $this->club))->assertOk()->getContent());
        $actions = function (Payment $p) use ($x) {
            $cell = $x->query("//a[contains(@href, '/admin/payments/{$p->id}/edit')]/ancestor::td")->item(0);
            $this->assertNotNull($cell, "row for payment {$p->id}");
            // Visible buttons outside the More menu.
            $outside = [];
            foreach ($x->query('.//a[contains(@class,"btn")]|.//button[contains(@class,"btn")]', $cell) as $b) {
                if ($x->query('ancestor::ul[contains(@class,"dropdown-menu")]', $b)->length === 0 && ! str_contains($b->getAttribute('class'), 'dropdown-toggle')) {
                    $outside[] = trim(preg_replace('/\s+/', ' ', $x->query('text()', $b)->item($x->query('text()', $b)->length - 1)?->textContent ?? ''));
                }
            }
            $menu = preg_replace('/\s+/', ' ', $x->query('.//ul[contains(@class,"dropdown-menu")]', $cell)->item(0)->textContent);

            return [$outside, $menu];
        };

        [$o, $menu] = $actions($due);
        $this->assertSame(['Record payment…'], $o);
        $this->assertStringContainsString('View', $menu);
        [$o] = $actions($slip);
        $this->assertSame(['Check slip'], $o);
        [$o, $menu] = $actions($paid);
        $this->assertSame([], $o);
        $this->assertStringContainsString('View', $menu);
    }

    public function test_payments_summary_follows_the_month_filter(): void
    {
        $this->actingAsClubAdmin($this->club);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 10, 'paid_date' => '2026-10-05', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-20']);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 25, 'paid_date' => '2026-11-05', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_date' => '2026-11-20']);
        Payment::factory()->forClub($this->club)->create(['status' => 'pending', 'amount' => 25, 'paid_date' => null, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-20']);

        $summary = fn (string $q) => preg_replace('/\s+/', ' ', $this->dom($this->get(route('admin.clubs.payments.index', $this->club).$q)->getContent())
            ->query('//section[@data-testid="payments-summary"]')->item(0)->textContent);

        $nov = $summary('?month=2026-11');
        $this->assertStringContainsString('Collected · Nov 2026 RM 25.00', $nov);
        $this->assertStringContainsString('Overdue 0', $nov);

        $oct = $summary('?month=2026-10');
        $this->assertStringContainsString('Collected · Oct 2026 RM 10.00', $oct);
        $this->assertStringContainsString('Overdue 1', $oct);

        $all = $summary('?month=not-a-month'); // no month filter: every month
        $this->assertStringContainsString('Collected · All months RM 35.00', $all);
    }

    public function test_payments_month_filter_is_right_on_the_31st(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 31, 10));
        $this->actingAsClubAdmin($this->club);
        $fee = Payment::factory()->forClub($this->club)->create(['status' => 'pending', 'paid_date' => null, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_date' => '2026-11-20']);

        $res = $this->get(route('admin.clubs.payments.index', $this->club).'?month=2026-11')->assertOk();
        $res->assertViewHas('payments', fn ($p) => $p->pluck('id')->all() === [$fee->id]);
        $res->assertSee('Collected · Nov 2026');
    }

    // ── Member fund page ────────────────────────────────────────────────

    public function test_member_fund_page_shows_the_club_aggregates_only(): void
    {
        $cat = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Events', 'name_ms' => 'Majlis']);
        Payment::factory()->forClub($this->club)->create(['status' => 'paid', 'amount' => 100, 'paid_date' => '2026-03-01', 'period_start' => '2026-03-01']);
        Expense::factory()->approved()->create(['club_id' => $this->club->id, 'expense_category_id' => $cat->id, 'amount' => 300, 'expense_date' => '2026-04-01', 'description' => 'SECRET-DESCRIPTION', 'payee' => 'SECRET-PAYEE']);
        $member = $this->actingAsMember($this->club);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('Member/Home')
            ->where('clubs.0.fundUrl', route('member.clubs.fund', $this->club))
            ->missing('clubs.0.fund'));

        $this->get(route('member.clubs.fund', $this->club))->assertOk()
            ->assertDontSee('SECRET-DESCRIPTION')
            ->assertDontSee('SECRET-PAYEE')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Fund')
                ->where('club.id', $this->club->id)
                ->where('club.name', 'Kelab Contoh')
                ->where('fund.collected', 10000)
                ->where('fund.spent', 30000)
                ->where('fund.expenses', 30000)
                ->where('fund.fromReserves', 20000)
                ->where('fund.categories', [['name' => 'Events', 'nameMs' => 'Majlis', 'amount' => 30000]])
                ->where('dashboardUrl', route('member.dashboard'))
                ->missing('fund.items'));
        $this->assertNotNull($member);
    }

    public function test_member_fund_page_is_for_members_of_that_club_only(): void
    {
        $other = Club::factory()->create();
        $this->actingAsMember($this->club);
        $this->get(route('member.clubs.fund', $other))->assertForbidden();

        auth()->logout();
        $this->get(route('member.clubs.fund', $this->club))->assertRedirect(route('login'));
    }

    // ── Audit log ───────────────────────────────────────────────────────

    public function test_audit_descriptions_use_friendly_references_and_dates_without_changing_the_rows(): void
    {
        $fee = Payment::factory()->forClub($this->club)->create();
        AuditService::log('payment.marked_paid', "Payment #{$fee->id} recorded as paid by cash on 2026-11-15.", $fee, $this->club->id, ['status' => 'pending'], ['status' => 'paid']);
        AuditService::log('payment.deleted', 'Payment #999999 deleted (due 2026-02-30).', null, $this->club->id);
        AuditService::log('club.updated', 'Name set to <b>X</b> on 2026-01-05.', null, $this->club->id);
        $this->actingAsClubAdmin($this->club);

        $res = $this->get(route('admin.clubs.audit-logs', $this->club))->assertOk();
        $x = $this->dom($res->getContent());
        $texts = [];
        foreach ($x->query('//td[@data-testid="audit-desc"]') as $td) {
            $texts[] = trim($td->textContent);
        }
        $this->assertContains("YURAN-{$fee->id} recorded as paid by cash on 15 Nov 2026.", $texts);
        // A fee that no longer exists keeps its id; an impossible date is left alone.
        $this->assertContains('Payment #999999 deleted (due 2026-02-30).', $texts);
        // Stored text is still escaped.
        $this->assertContains('Name set to <b>X</b> on 5 Jan 2026.', $texts);
        $res->assertDontSee('<b>X</b>', false);
        // No truncation; the changes button has a visible label.
        $this->assertSame(0, $x->query('//td[@data-testid="audit-desc"][contains(@class,"text-truncate")]|//td[@data-testid="audit-desc"]//*[contains(@class,"text-truncate")]')->length);
        $btn = $x->query('//button[@data-bs-target="#diffModal"]')->item(0);
        $this->assertStringContainsString('View changes', $btn->textContent);
        $this->assertNull($btn->getAttribute('aria-label') ?: null);

        $this->assertSame("Payment #{$fee->id} recorded as paid by cash on 2026-11-15.", AuditLog::where('action', 'payment.marked_paid')->value('description'));

        $this->actingAsSuperAdmin();
        $this->get(route('admin.audit-logs.index'))->assertOk()->assertSeeText("YURAN-{$fee->id} recorded as paid by cash on 15 Nov 2026.")->assertSee("<span class=\"text-nowrap\">YURAN-{$fee->id}</span>", false);
    }

    public function test_audit_descriptions_use_the_group_reference_for_a_group_slip(): void
    {
        $member = User::factory()->create();
        $fees = collect(['2026-09-01', '2026-10-01'])->map(fn ($d) => Payment::factory()->forClub($this->club)->forMember($member)
            ->create(['status' => 'paid', 'period_start' => $d, 'period_end' => Carbon::parse($d)->endOfMonth()->toDateString()]));
        $bill = \App\Models\PaymentBill::create([
            'club_id' => $this->club->id, 'user_id' => $member->id, 'method' => 'transfer',
            'reference' => 'YURAN-G'.$fees[0]->id, 'amount_sen' => 2000,
        ]);
        foreach ($fees as $f) {
            $f->forceFill(['transfer_group_id' => $bill->id])->save();
        }
        $this->assertSame('YURAN-G'.$fees[0]->id, $fees[1]->fresh()->transferReference());
        AuditService::log('payment.transfer_approved', "Payment #{$fees[1]->id}: transfer slip approved.", $fees[1], $this->club->id);
        $this->signedInAdmin();

        $this->get(route('admin.clubs.audit-logs', $this->club))->assertSeeText("YURAN-G{$fees[0]->id}: transfer slip approved.");
    }

    public function test_recovery_codes_are_matched_without_case_spaces_or_dashes(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['ABCD-EFGH', 'JKMN-PQRS'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), ['recovery_code' => ' jkmn pqrs '])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['ABCD-EFGH'], $user->fresh()->getTwoFactorRecoveryCodes());
    }

    // ── Recovery codes ──────────────────────────────────────────────────

    public function test_recovery_codes_avoid_ambiguous_characters(): void
    {
        $this->assertDoesNotMatchRegularExpression('/[0O1IL]/', ProfileController::RECOVERY_ALPHABET);

        $method = new \ReflectionMethod(ProfileController::class, 'generateRecoveryCodes');
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes = array_merge($codes, $method->invoke(app(ProfileController::class)));
        }
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}$/', $code);
        }
    }

    public function test_admin_pages_have_no_bm_leaks_in_the_fund_usage_sources(): void
    {
        $sources = implode("\n", array_map('file_get_contents', [
            resource_path('js/components/app/ApprovalRail.tsx'),
            resource_path('js/Pages/Admin/FundUsage/Create.tsx'),
            resource_path('js/Pages/Admin/Ledger/Index.tsx'),
            resource_path('js/components/app/FundDialogs.tsx'),
        ]));
        foreach (['Disediakan', 'Diluluskan · Checker', 'Rujukan minit mesyuarat', 'diubah secara manual', 'Saya mengisytiharkan'] as $bm) {
            $this->assertStringNotContainsString($bm, $sources);
        }
    }
}
