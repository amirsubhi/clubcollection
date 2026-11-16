<?php

namespace Tests\Feature;

use App\Mail\OverdueReminder;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use App\Support\Export\CsvSafe;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * S1 regression tests for the admin-side fixes: stale payment edits, no
 * self-settling, the reminder race, ledger money in sen, CSV export hygiene,
 * the seeder guard, super-admin user management and the fee-rate/discount
 * route fixes.
 */
class SecurityHardeningTest extends TestCase
{
    private function memberOf(Club $club, string $role = 'member'): User
    {
        $u = User::factory()->create(['role' => $role === 'admin' ? 'admin' : 'member']);
        $club->members()->attach($u, ['role' => $role, 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $u;
    }

    private function editPayload(Payment $p, array $overrides = []): array
    {
        return $overrides + [
            'due_date' => $p->due_date->toDateString(),
            'amount' => (string) $p->amount,
            'status' => $p->status,
            'original_status' => $p->status,
        ];
    }

    // ── Stale admin edits ────────────────────────────────────────────────

    public function test_a_stale_edit_is_refused(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $p = Payment::factory()->forClub($club)->forMember($this->memberOf($club))->pending()->create();

        // The form was loaded while pending; a webhook settled it since.
        $p->forceFill(['status' => 'paid', 'paid_date' => now()->toDateString(), 'paid_via' => 'toyyibpay'])->save();

        $this->put(route('admin.payments.update', $p), $this->editPayload($p, ['status' => 'overdue', 'original_status' => 'pending']))
            ->assertSessionHasErrors('status');

        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame('toyyibpay', $p->fresh()->paid_via);
    }

    public function test_leaving_paid_clears_how_it_was_settled(): void
    {
        $club = Club::factory()->create();
        $admin = $this->actingAsClubAdmin($club);
        $p = Payment::factory()->forClub($club)->forMember($this->memberOf($club))->create(['status' => 'paid', 'gateway' => 'toyyibpay', 'transaction_id' => 'TXN-KEEP']);
        $p->forceFill(['paid_via' => 'transfer', 'verified_by' => $admin->id, 'verified_at' => now()])->save();

        $this->put(route('admin.payments.update', $p), $this->editPayload($p, ['status' => 'pending', 'paid_date' => '']))
            ->assertSessionHasNoErrors();

        $fresh = $p->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_via);
        $this->assertNull($fresh->gateway);
        $this->assertNull($fresh->verified_by);
        $this->assertNull($fresh->verified_at);
        // Kept, so a gateway retry of that transaction can never settle it again.
        $this->assertSame('TXN-KEEP', $fresh->transaction_id);
    }

    public function test_amount_is_capped_and_limited_to_two_decimals(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $p = Payment::factory()->forClub($club)->forMember($this->memberOf($club))->pending()->create();

        $this->put(route('admin.payments.update', $p), $this->editPayload($p, ['amount' => '100000.01']))->assertSessionHasErrors('amount');
        $this->put(route('admin.payments.update', $p), $this->editPayload($p, ['amount' => '10.555']))->assertSessionHasErrors('amount');
        $this->put(route('admin.payments.update', $p), $this->editPayload($p, ['amount' => '99999.99']))->assertSessionHasNoErrors();
    }

    // ── No self-settling ─────────────────────────────────────────────────

    public function test_an_admin_cannot_mark_their_own_fee_paid(): void
    {
        $club = Club::factory()->create();
        $admin = $this->actingAsClubAdmin($club);
        $own = Payment::factory()->forClub($club)->forMember($admin)->pending()->create();

        $this->patch(route('admin.payments.mark-paid', $own), $this->recordPayment())->assertSessionHas('error');
        $this->assertSame('pending', $own->fresh()->status);

        $this->put(route('admin.payments.update', $own), $this->editPayload($own, ['status' => 'paid']))
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $own->fresh()->status);

        // Other edits to their own fee (not settling it) are still allowed.
        $this->put(route('admin.payments.update', $own), $this->editPayload($own, ['notes' => 'Moved due date']))->assertSessionHasNoErrors();
    }

    public function test_an_admin_cannot_change_the_amount_or_discount_of_their_own_fee_or_delete_it(): void
    {
        $club = Club::factory()->create();
        $admin = $this->actingAsClubAdmin($club);
        $own = Payment::factory()->forClub($club)->forMember($admin)->pending()->create(['amount' => 50.00]);
        $discount = \App\Models\Discount::factory()->create(['club_id' => $club->id]);

        $this->put(route('admin.payments.update', $own), $this->editPayload($own, ['amount' => '1.00']))->assertSessionHasErrors('amount');
        $this->put(route('admin.payments.update', $own), $this->editPayload($own, ['discount_id' => $discount->id]))->assertSessionHasErrors('amount');
        $this->assertSame('50.00', (string) $own->fresh()->amount);
        $this->assertNull($own->fresh()->discount_id);

        $this->delete(route('admin.payments.destroy', $own))->assertSessionHas('error');
        $this->assertNotNull($own->fresh());

        // The super admin may.
        $this->actingAsSuperAdmin();
        $this->put(route('admin.payments.update', $own), $this->editPayload($own, ['amount' => '40.00']))->assertSessionHasNoErrors();
        $this->assertSame('40.00', (string) $own->fresh()->amount);
    }

    public function test_another_admin_or_the_super_admin_can_mark_it_paid(): void
    {
        $club = Club::factory()->create();
        $adminA = $this->memberOf($club, 'admin');
        $own = Payment::factory()->forClub($club)->forMember($adminA)->pending()->create();
        $this->actingAsClubAdmin($club);

        $this->patch(route('admin.payments.mark-paid', $own), $this->recordPayment())->assertSessionHas('success');
        $this->assertSame('paid', $own->fresh()->status);

        $super = $this->actingAsSuperAdmin();
        $superFee = Payment::factory()->forClub($club)->forMember($super)->pending()->create(['period_start' => now()->addMonths(2)->startOfMonth()->toDateString()]);
        $this->patch(route('admin.payments.mark-paid', $superFee), $this->recordPayment())->assertSessionHas('success');
    }

    // ── Reminder race ────────────────────────────────────────────────────

    public function test_the_reminder_does_not_overwrite_a_fee_paid_meanwhile(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $p = Payment::factory()->forClub($club)->forMember($this->memberOf($club))->pending()->create(['due_date' => now()->subDays(3)->toDateString()]);

        // A webhook settles the fee right after the command reads its chunk.
        Payment::retrieved(function (Payment $read) use ($p) {
            if ($read->id === $p->id) {
                DB::table('payments')->where('id', $p->id)->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
            }
        });

        Artisan::call('payments:send-overdue-reminders');

        $this->assertSame('paid', $p->fresh()->status);
        Mail::assertNotSent(OverdueReminder::class);
        $this->assertSame(0, AuditLog::where('action', 'payment.marked_overdue')->count());
    }

    // ── Ledger money and CSV exports ─────────────────────────────────────

    public function test_ledger_totals_are_exact_in_sen(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = $this->memberOf($club);
        // 0.1 + 0.2 style amounts that drift as floats.
        foreach (range(1, 30) as $i) {
            Payment::factory()->forClub($club)->forMember($member)->create([
                'amount' => 0.10, 'paid_date' => now()->startOfYear()->addDays($i)->toDateString(),
                'period_start' => now()->startOfYear()->addMonthsNoOverflow($i)->toDateString(),
            ]);
        }

        $response = $this->get(route('admin.clubs.ledger', $club).'?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())->assertOk();

        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('summary.in', 300)
            ->where('summary.closing', 300)
            ->where('transactions.29.balance', 300));
    }

    public function test_csv_cells_that_look_like_formulas_get_an_apostrophe(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", CsvSafe::cell('=HYPERLINK("http://x")'));
        foreach (['+A1', '-A1+B1', '@SUM(A1)', "\tx", "\rx"] as $v) {
            $this->assertSame("'".$v, CsvSafe::cell($v));
        }
        $this->assertSame('Ali', CsvSafe::cell('Ali'));
        // Plain numbers stay numbers.
        $this->assertSame('-12.50', CsvSafe::cell('-12.50'));
        $this->assertSame('+60123456789', CsvSafe::cell('+60123456789'));
        $this->assertSame("'-1+1", CsvSafe::cell('-1+1'));
        $this->assertSame(12.5, CsvSafe::cell(12.5));
        $this->assertSame('', CsvSafe::cell(''));
    }

    public function test_ledger_csv_neutralises_a_formula_and_slugs_the_filename(): void
    {
        $club = Club::factory()->create(['name' => 'Kelab "Sukan"; x=1']);
        $this->actingAsClubAdmin($club);
        $member = User::factory()->create(['name' => '=HYPERLINK("http://evil.example","x")']);
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        Payment::factory()->forClub($club)->forMember($member)->create(['paid_date' => now()->toDateString()]);

        $response = $this->get(route('admin.clubs.ledger.export', $club))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/^attachment; filename=kelab-sukan-x1-ledger-[0-9-]+-to-[0-9-]+\.csv$/', $disposition);
    }

    // ── Seeder guard ─────────────────────────────────────────────────────

    public function test_the_seeder_refuses_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            (new DatabaseSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Club::count());
        $this->assertFalse(DatabaseSeeder::allowedIn('staging'));
        $this->assertTrue(DatabaseSeeder::allowedIn('local'));
    }

    public function test_the_seeder_has_no_well_known_password(): void
    {
        (new DatabaseSeeder)->run();

        $super = User::where('email', 'superadmin@example.com')->sole();
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Admin@123', $super->password));
    }

    // ── Super-admin user management ──────────────────────────────────────

    public function test_admin_routes_only_bind_admin_users(): void
    {
        $this->actingAsSuperAdmin();
        $member = User::factory()->create(['role' => 'member']);

        $this->get(route('admin.admins.edit', $member))->assertNotFound();
        $this->put(route('admin.admins.update', $member), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'super_admin'])->assertNotFound();
        $this->delete(route('admin.admins.destroy', $member))->assertNotFound();
        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_admin_user_changes_are_audited(): void
    {
        Mail::fake();
        $this->actingAsSuperAdmin();

        $this->post(route('admin.admins.store'), ['name' => 'New Admin', 'email' => 'new.admin@example.test', 'role' => 'admin'])->assertRedirect();
        $admin = User::where('email', 'new.admin@example.test')->sole();
        $this->put(route('admin.admins.update', $admin), ['name' => 'Renamed', 'email' => 'new.admin@example.test', 'role' => 'admin'])->assertRedirect();
        $this->delete(route('admin.admins.destroy', $admin))->assertRedirect();

        foreach (['admin_user.created', 'admin_user.updated', 'admin_user.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_the_last_super_admin_cannot_be_removed_or_demoted(): void
    {
        $super = $this->actingAsSuperAdmin();

        $this->put(route('admin.admins.update', $super), ['name' => $super->name, 'email' => $super->email, 'role' => 'admin'])
            ->assertSessionHas('error');
        $this->delete(route('admin.admins.destroy', $super))->assertSessionHas('error');

        $this->assertSame('super_admin', $super->fresh()->role);
        $this->assertNotNull($super->fresh());
    }

    public function test_a_super_admin_cannot_demote_themselves_even_with_another_super_admin(): void
    {
        $super = $this->actingAsSuperAdmin();
        $other = User::factory()->create(['role' => 'super_admin']);

        $this->put(route('admin.admins.update', $super), ['name' => $super->name, 'email' => $super->email, 'role' => 'admin'])
            ->assertSessionHas('error');
        $this->assertSame('super_admin', $super->fresh()->role);

        // Demoting another super admin is fine while one remains.
        $this->put(route('admin.admins.update', $other), ['name' => $other->name, 'email' => $other->email, 'role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('admin', $other->fresh()->role);
    }

    public function test_an_admin_with_records_is_kept_with_a_message(): void
    {
        $club = Club::factory()->create();
        $this->actingAsSuperAdmin();
        $admin = $this->memberOf($club, 'admin');
        Payment::factory()->forClub($club)->forMember($this->memberOf($club))->create(['recorded_by' => $admin->id]);

        $this->delete(route('admin.admins.destroy', $admin))->assertSessionHas('error');
        $this->assertNotNull($admin->fresh());
    }

    // ── Fee rates and discounts (A-L7) ───────────────────────────────────

    public function test_fee_rate_delete_works_for_the_clubs_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $rate = FeeRate::factory()->create(['club_id' => $club->id]);

        $this->delete(route('admin.fee-rates.destroy', $rate))
            ->assertRedirect(route('admin.clubs.fee-rates.index', $club));
        $this->assertDatabaseMissing('fee_rates', ['id' => $rate->id]);
    }

    public function test_fee_rate_and_discount_redirects_use_existing_routes(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.fee-rates.store', $club), [
            'rates' => [['job_level' => 'executive', 'monthly_amount' => '12.00', 'effective_from' => now()->toDateString()]],
        ])->assertRedirect(route('admin.clubs.fee-rates.index', $club));

        $this->post(route('admin.clubs.discounts.store', $club), [
            'name' => 'Promo', 'type' => 'fixed', 'value' => '5', 'valid_from' => now()->toDateString(),
        ])->assertRedirect(route('admin.clubs.discounts.index', $club));
    }

    // ── Upload over post_max_size ────────────────────────────────────────

    public function test_an_oversize_post_gets_a_bilingual_413_page(): void
    {
        $club = Club::factory()->create();
        $member = $this->memberOf($club);
        $p = Payment::factory()->forClub($club)->forMember($member)->pending()->create();
        $this->actingAs($member);

        $response = $this->call('POST', route('member.payments.transfer', $p), [], [], [], ['CONTENT_LENGTH' => (string) (1024 * 1024 * 1024)]);

        $response->assertStatus(413);
        $response->assertSee('Fail terlalu besar')->assertSee('Upload too large');
        $this->assertSame('pending', $p->fresh()->status);
    }
}
