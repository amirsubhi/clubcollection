<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\MonthlyFeeGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Generate fees for a month": preview every member's outcome, then create exactly the previewed fees.
 */
class GenerateFeesTest extends TestCase
{
    private Club $club;

    private User $admin;

    /** @var array<string, User> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();

        // Executive: RM 10 until the end of November (closed), RM 12 from December (dated ahead when it was saved).
        $this->rate('executive', 10, '2026-01-01', '2026-11-30');
        $this->rate('executive', 12, '2026-12-01');
        $this->rate('manager', 20, '2026-01-01');
        $this->rate('volunteer', 0, '2026-01-01');

        $this->m['ali'] = $this->member('Ali', 'executive');
        $this->m['badrul'] = $this->member('Badrul', 'executive', ['is_active' => false]);
        $this->m['chong'] = $this->member('Chong', 'executive', ['joined_date' => '2026-12-05']);
        $this->m['devi'] = $this->member('Devi', 'trainee');
        $this->m['ehsan'] = $this->member('Ehsan', 'volunteer');
        $this->m['farah'] = $this->member('Farah', 'executive');
        $this->m['gopal'] = $this->member('Gopal', 'executive');
        $this->m['hana'] = $this->member('Hana', 'executive');

        // Farah paid ahead for October to December (quarterly); Gopal's November fee was paid then cancelled
        // (pending again); Hana has a November fee marked overdue.
        $this->fee($this->m['farah'], '2026-10-01', '2026-12-31', 'quarterly', 'paid');
        $this->fee($this->m['gopal'], '2026-11-01', '2026-11-30', 'monthly', 'pending');
        $this->fee($this->m['hana'], '2026-11-01', '2026-11-30', 'monthly', 'overdue');

        // The club admin (job level manager, joined today) is billed like everyone.
        $this->admin = $this->actingAsClubAdmin($this->club);
    }

    private function rate(string $level, float $amount, string $from, ?string $to = null): void
    {
        FeeRate::factory()->create(['club_id' => $this->club->id, 'job_level' => $level, 'monthly_amount' => $amount, 'effective_from' => $from, 'effective_to' => $to]);
    }

    private function member(string $name, string $level, array $pivot = []): User
    {
        $u = User::factory()->create(['name' => $name, 'role' => 'member']);
        $this->club->members()->attach($u, $pivot + ['role' => 'member', 'job_level' => $level, 'joined_date' => '2026-01-05', 'is_active' => true]);

        return $u;
    }

    private function fee(User $u, string $start, string $end, string $frequency, string $status): Payment
    {
        return Payment::factory()->forClub($this->club)->forMember($u)->create([
            'period_start' => $start, 'period_end' => $end, 'frequency' => $frequency, 'status' => $status,
            'due_date' => $start, 'paid_date' => $status === 'paid' ? $start : null, 'amount' => '10.00',
        ]);
    }

    /** @return array<int, string> user id => outcome */
    private function outcomes(string $month): array
    {
        $preview = $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => $month]))->assertOk()->viewData('preview');

        return collect($preview['rows'])->mapWithKeys(fn ($r) => [$r['user']->id => $r['outcome']])->all();
    }

    /** The fingerprint the preview page would post for $month and $due, from the list as it is now. */
    private function fingerprint(string $month, string $due): string
    {
        $m = Carbon::parse($month.'-01');

        return MonthlyFeeGenerator::fingerprint(app(MonthlyFeeGenerator::class)->preview($this->club, $m), $m, Carbon::parse($due));
    }

    /** POST the confirmation; the fingerprint defaults to one of a preview taken just before. */
    private function generate(string $month, string $due, int $count, int $totalSen, ?string $fingerprint = null)
    {
        return $this->post(route('admin.clubs.payments.generate.store', $this->club), [
            'month' => $month, 'due_date' => $due, 'expected_count' => $count, 'expected_total_sen' => $totalSen,
            'fingerprint' => $fingerprint ?? $this->fingerprint($month, $due),
        ]);
    }

    public function test_the_preview_gives_every_member_an_outcome(): void
    {
        $o = $this->outcomes('2026-11');

        $this->assertSame([
            $this->m['ali']->id => MonthlyFeeGenerator::BILL,
            $this->m['badrul']->id => MonthlyFeeGenerator::SKIP_INACTIVE,
            $this->m['chong']->id => MonthlyFeeGenerator::SKIP_JOINED_LATER,
            $this->m['devi']->id => MonthlyFeeGenerator::SKIP_NO_RATE,
            $this->m['ehsan']->id => MonthlyFeeGenerator::SKIP_ZERO_RATE,
            $this->m['farah']->id => MonthlyFeeGenerator::SKIP_ALREADY_BILLED,
            $this->m['gopal']->id => MonthlyFeeGenerator::SKIP_ALREADY_BILLED,
            $this->m['hana']->id => MonthlyFeeGenerator::SKIP_ALREADY_BILLED,
            $this->admin->id => MonthlyFeeGenerator::BILL,
        ], collect($o)->sortKeys()->all(), 'every member, one outcome each');

        $res = $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11']));
        $preview = $res->viewData('preview');
        $this->assertSame(2, $preview['count']);
        $this->assertSame(3000, $preview['totalSen']);
        $this->assertEquals([
            MonthlyFeeGenerator::SKIP_INACTIVE => 1, MonthlyFeeGenerator::SKIP_JOINED_LATER => 1,
            MonthlyFeeGenerator::SKIP_NO_RATE => 1, MonthlyFeeGenerator::SKIP_ZERO_RATE => 1, MonthlyFeeGenerator::SKIP_ALREADY_BILLED => 3,
        ], $preview['skipped']);
        $res->assertSeeText('2 members will be billed')
            ->assertSee('RM 30.00')
            ->assertSeeText('Inactive membership')
            ->assertSeeText('Joined after the month')
            ->assertSeeText('No fee rate for their level')
            ->assertSeeText('Rate is RM 0')
            ->assertSeeText('Skipped, rate is RM 0: 1')
            ->assertSeeText('Already has a fee covering the month')
            ->assertSee('value="2026-11-16"', false); // 1 Nov + 14 days is past: the default due date is today
    }

    public function test_generating_bills_exactly_the_previewed_members_at_the_rate_in_force(): void
    {
        Mail::fake();

        $this->generate('2026-11', '2026-11-30', 2, 3000)
            ->assertRedirect(route('admin.clubs.payments.index', [$this->club, 'month' => '2026-11']))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Created 2 fees for Nov 2026, total RM 30.00') && str_contains($m, 'rate is RM 0 (1)'));

        $made = Payment::where('club_id', $this->club->id)->whereDate('period_start', '2026-11-01')
            ->whereIn('user_id', [$this->m['ali']->id, $this->admin->id])->get()->keyBy('user_id');
        $this->assertCount(2, $made);
        $this->assertSame('10.00', $made[$this->m['ali']->id]->amount, 'November: the closed RM 10 rate');
        $this->assertSame('20.00', $made[$this->admin->id]->amount, "the admin's own fee is included");
        foreach ($made as $p) {
            $this->assertSame('monthly', $p->frequency);
            $this->assertSame('2026-11-30', $p->period_end->toDateString());
            $this->assertSame('2026-11-30', $p->due_date->toDateString());
            $this->assertSame('pending', $p->status);
            $this->assertSame($this->admin->id, $p->recorded_by);
            $this->assertNull($p->discount_id);
        }
        // Nobody else got a November fee.
        $this->assertSame(5, Payment::where('club_id', $this->club->id)->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_next_month_uses_the_rate_starting_then_and_bills_members_who_joined_in_it(): void
    {
        $o = $this->outcomes('2026-12');
        $this->assertSame(MonthlyFeeGenerator::BILL, $o[$this->m['chong']->id]);
        $this->assertSame(MonthlyFeeGenerator::SKIP_ALREADY_BILLED, $o[$this->m['farah']->id], 'quarterly pay-ahead covers December');
        $this->assertSame(MonthlyFeeGenerator::BILL, $o[$this->m['gopal']->id]);

        // Ali, Chong, Gopal, Hana at RM 12 + the admin at RM 20.
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-12']))
            ->assertSee('value="2026-12-15"', false);
        $this->generate('2026-12', '2026-12-15', 5, 6800)->assertSessionHas('success');

        $dec = Payment::where('club_id', $this->club->id)->whereDate('period_start', '2026-12-01')->get();
        $this->assertCount(5, $dec);
        $this->assertSame(['12.00', '12.00', '12.00', '12.00', '20.00'], $dec->pluck('amount')->sort()->values()->all());
        $this->assertFalse($dec->contains('user_id', $this->m['farah']->id));
    }

    public function test_running_the_month_again_creates_nothing(): void
    {
        $fingerprint = $this->fingerprint('2026-11', '2026-11-30');
        $this->generate('2026-11', '2026-11-30', 2, 3000, $fingerprint)->assertSessionHas('success');
        $before = Payment::count();

        $preview = $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11']))
            ->assertSeeText('Nothing to create for November 2026')
            ->assertDontSee('data-testid="generate-confirm"', false)
            ->viewData('preview');
        $this->assertSame(0, $preview['count']);

        // A replay of the first confirmation is refused: the list no longer matches.
        $this->generate('2026-11', '2026-11-30', 2, 3000, $fingerprint)->assertSessionHas('error', fn ($m) => str_contains($m, 'The list changed'));
        $this->assertSame($before, Payment::count());
        $this->assertSame(1, AuditLog::where('action', 'payment.generated')->count());
    }

    public function test_the_preview_posts_the_fingerprint_of_who_is_billed_what(): void
    {
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11', 'due_date' => '2026-11-30', 'due_for' => '2026-11']))
            ->assertSee('<input type="hidden" name="fingerprint" value="'.$this->fingerprint('2026-11', '2026-11-30').'">', false);

        // It is bound to the due date and the month too.
        $this->assertNotSame($this->fingerprint('2026-11', '2026-11-30'), $this->fingerprint('2026-11', '2026-11-29'));
        $this->generate('2026-11', '2026-11-30', 2, 3000, $this->fingerprint('2026-11', '2026-11-29'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'review it again'));
        $this->generate('2026-11', '2026-11-30', 2, 3000, '')->assertSessionHasErrors('fingerprint');
        $this->assertSame(3, Payment::count());
    }

    public function test_a_member_swapped_for_another_with_the_same_amount_is_refused(): void
    {
        $previewed = $this->fingerprint('2026-11', '2026-11-30');

        // Ali (executive, RM 10) is deactivated and Badrul (executive, RM 10) reactivated: same count and total.
        $this->club->members()->updateExistingPivot($this->m['ali']->id, ['is_active' => false]);
        $this->club->members()->updateExistingPivot($this->m['badrul']->id, ['is_active' => true]);
        $now = app(MonthlyFeeGenerator::class)->preview($this->club, Carbon::parse('2026-11-01'));
        $this->assertSame([2, 3000], [$now['count'], $now['totalSen']]);

        $this->generate('2026-11', '2026-11-30', 2, 3000, $previewed)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'The list changed') && str_contains($m, 'review it again'));
        $this->assertSame(3, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'payment.generated')->count());
    }

    public function test_a_member_deactivated_during_the_run_is_not_billed(): void
    {
        // When the first fee is created, the other billed member is deactivated (as if an admin did it meanwhile).
        $billed = [$this->m['ali']->id, $this->admin->id];
        $deactivated = null;
        Payment::created(function (Payment $p) use ($billed, &$deactivated) {
            if ($deactivated === null) {
                $deactivated = collect($billed)->first(fn ($id) => $id !== $p->user_id);
                $this->club->members()->updateExistingPivot($deactivated, ['is_active' => false]);
            }
        });

        $this->generate('2026-11', '2026-11-30', 2, 3000)
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Created 1 fee for Nov 2026') && str_contains($m, 'inactive membership (2)'));

        $this->assertNotNull($deactivated);
        $this->assertSame(0, Payment::where('user_id', $deactivated)->count());
        $this->assertSame(1, Payment::whereIn('user_id', $billed)->count());
    }

    public function test_a_member_deactivated_or_a_club_deactivated_after_the_preview_is_not_billed(): void
    {
        $generator = app(MonthlyFeeGenerator::class);
        $month = Carbon::parse('2026-11-01');
        $preview = $generator->preview($this->club, $month);

        $this->club->members()->updateExistingPivot($this->m['ali']->id, ['is_active' => false]);
        $result = $generator->generate($this->club, $month, Carbon::parse('2026-11-30'), $this->admin, $preview);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['skipped'][MonthlyFeeGenerator::SKIP_INACTIVE]);
        $this->assertSame(0, Payment::where('user_id', $this->m['ali']->id)->count());

        // The club itself deactivated after the preview (the bound model still says active).
        $december = Carbon::parse('2026-12-01');
        $preview = $generator->preview($this->club, $december);
        $this->assertGreaterThan(0, $preview['count']);
        Club::whereKey($this->club->id)->update(['is_active' => false]);
        $result = $generator->generate($this->club, $december, Carbon::parse('2026-12-15'), $this->admin, $preview);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Payment::whereDate('period_start', '2026-12-01')->count());
    }

    public function test_a_post_that_does_not_match_the_list_is_refused(): void
    {
        $this->generate('2026-11', '2026-11-30', 3, 3000)
            ->assertRedirect(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11', 'due_date' => '2026-11-30', 'due_for' => '2026-11']))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'it now bills 2 members, RM 30.00') && str_contains($m, 'review it again'));
        $this->generate('2026-11', '2026-11-30', 2, 2900)->assertSessionHas('error', fn ($m) => str_contains($m, 'The list changed'));

        // The rate changed between preview and confirm.
        FeeRate::where('job_level', 'manager')->update(['monthly_amount' => 25]);
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertSessionHas('error');

        $this->assertSame(3, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'payment.generated')->count());
    }

    public function test_a_past_due_date_and_months_out_of_range_are_refused(): void
    {
        $this->generate('2026-11', '2026-11-15', 2, 3000)->assertSessionHas('error', "The due date can't be in the past.");
        $this->generate('2026-11', '2027-01-01', 2, 3000)->assertSessionHas('error', fn ($m) => str_contains($m, 'at most 31 Dec 2026'));
        $this->generate('2026-10', '2026-11-30', 2, 3000)->assertSessionHas('error', 'Fees can be generated for this month or next month only.');
        $this->generate('2027-01', '2027-01-15', 2, 3000)->assertSessionHas('error', 'Fees can be generated for this month or next month only.');
        $this->assertSame(3, Payment::count());

        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-10']))
            ->assertRedirect(route('admin.clubs.payments.generate', $this->club))
            ->assertSessionHas('error');
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11', 'due_date' => '2026-11-01', 'due_for' => '2026-11']))
            ->assertOk()->assertSeeText("The due date can't be in the past.")
            ->assertDontSee('data-testid="generate-confirm"', false);
    }

    public function test_next_months_fees_cannot_fall_due_before_that_month_starts(): void
    {
        $this->generate('2026-12', '2026-11-30', 5, 6800)
            ->assertSessionHas('error', "The due date can't be before the month it bills starts (1 Dec 2026).");
        $this->assertSame(0, Payment::whereDate('period_start', '2026-12-01')->count());

        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-12', 'due_date' => '2026-11-20', 'due_for' => '2026-12']))
            ->assertOk()->assertSeeText("The due date can't be before the month it bills starts (1 Dec 2026).")
            ->assertSee('min="2026-12-01"', false)
            ->assertDontSee('data-testid="generate-confirm"', false);
        // This month: today is the earliest.
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-11']))->assertSee('min="2026-11-16"', false);

        // The month's 1st itself is fine.
        $this->generate('2026-12', '2026-12-01', 5, 6800)->assertSessionHas('success');
        $this->assertSame(['2026-12-01'], Payment::whereDate('period_start', '2026-12-01')->pluck('due_date')->map->toDateString()->unique()->values()->all());
    }

    public function test_switching_the_month_starts_from_that_months_default_due_date(): void
    {
        // The form was showing November (due today) and the admin picks December.
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-12', 'due_date' => '2026-11-16', 'due_for' => '2026-11']))
            ->assertOk()
            ->assertSee('name="due_date" value="2026-12-15"', false)
            ->assertSee('<input type="hidden" name="due_date" value="2026-12-15">', false);

        // A date chosen for December itself is kept.
        $this->get(route('admin.clubs.payments.generate', [$this->club, 'month' => '2026-12', 'due_date' => '2026-12-20', 'due_for' => '2026-12']))
            ->assertSee('<input type="hidden" name="due_date" value="2026-12-20">', false);
    }

    public function test_only_this_clubs_admins_and_the_super_admin_may_generate(): void
    {
        $member = $this->m['ali'];
        $this->actingAs($member);
        $this->get(route('admin.clubs.payments.generate', $this->club))->assertForbidden();
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertForbidden();

        $other = Club::factory()->create();
        $this->actingAsClubAdmin($other);
        $this->get(route('admin.clubs.payments.generate', $this->club))->assertForbidden();
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertForbidden();

        // An inactive admin of this club.
        $former = User::factory()->create(['role' => 'admin']);
        $this->club->members()->attach($former, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => '2026-01-05', 'is_active' => false]);
        $this->actingAs($former);
        $this->generate('2026-11', '2026-11-30', 3, 5000)->assertForbidden();
        $this->assertSame(3, Payment::count());

        $super = $this->actingAsSuperAdmin();
        $this->get(route('admin.clubs.payments.generate', $this->club))->assertOk();
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertSessionHas('success');
        $this->assertSame(2, Payment::where('recorded_by', $super->id)->count());
    }

    public function test_an_inactive_club_is_refused(): void
    {
        $this->club->update(['is_active' => false]);

        // Its club admin is refused by the middleware; the super admin gets a message.
        $this->get(route('admin.clubs.payments.generate', $this->club))->assertForbidden();
        $this->actingAsSuperAdmin();
        $this->get(route('admin.clubs.payments.generate', $this->club))
            ->assertRedirect(route('admin.clubs.payments.index', $this->club))->assertSessionHas('error', fn ($m) => str_contains($m, 'inactive'));
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertSessionHas('error', fn ($m) => str_contains($m, 'inactive'));
        $this->assertSame(3, Payment::count());
    }

    public function test_audit_rows_name_the_run(): void
    {
        $this->generate('2026-11', '2026-11-30', 2, 3000)->assertSessionHas('success');

        $summary = AuditLog::where('action', 'payment.generated')->sole();
        $run = $summary->new_values['run'];
        $this->assertStringStartsWith('gen-202611-', $run);
        $this->assertSame($this->club->id, $summary->club_id);
        $this->assertSame($this->admin->id, $summary->user_id);
        $this->assertSame('2026-11', $summary->new_values['month']);
        $this->assertSame(2, $summary->new_values['count']);
        $this->assertSame('30.00', $summary->new_values['total']);
        $this->assertEquals(['inactive' => 1, 'joined_later' => 1, 'already_billed' => 3, 'no_rate' => 1, 'zero_rate' => 1], $summary->new_values['skipped']);
        $this->assertSame('Fees Generated', $summary->action_label);

        $created = AuditLog::where('action', 'payment.created')->get();
        $this->assertCount(2, $created);
        foreach ($created as $row) {
            $this->assertSame($run, $row->new_values['run']);
            $this->assertSame(Payment::class, $row->auditable_type);
            $this->assertStringContainsString($run, $row->description);
        }
    }

    public function test_a_fee_made_meanwhile_or_a_busy_member_is_skipped_not_doubled(): void
    {
        $generator = app(MonthlyFeeGenerator::class);
        $month = Carbon::parse('2026-11-01');
        $preview = $generator->preview($this->club, $month);
        $this->assertSame(2, $preview['count']);

        // Ali's November fee arrives after the preview (same start: the unique index or the re-check catches it);
        // the admin's own member lock is held by a pay-ahead in progress.
        $this->fee($this->m['ali'], '2026-11-01', '2026-11-30', 'monthly', 'pending');
        $lock = Cache::lock("pay-ahead:{$this->club->id}:{$this->admin->id}", 10);
        $this->assertTrue($lock->get());

        try {
            $result = $generator->generate($this->club, $month, Carbon::parse('2026-11-30'), $this->admin, $preview);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $result['created']);
        $this->assertSame(4, $result['skipped'][MonthlyFeeGenerator::SKIP_ALREADY_BILLED]);
        $this->assertSame(1, $result['skipped'][MonthlyFeeGenerator::SKIP_BUSY]);
        $this->assertSame(1, Payment::where('user_id', $this->m['ali']->id)->count());
        $this->assertSame(0, Payment::where('user_id', $this->admin->id)->count());
    }

    public function test_the_payments_page_links_to_it(): void
    {
        $this->get(route('admin.clubs.payments.index', $this->club))
            ->assertOk()
            ->assertSee(route('admin.clubs.payments.generate', $this->club), false)
            ->assertSeeText('Generate fees for a month');
    }
}
