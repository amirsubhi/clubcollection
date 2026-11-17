<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\AttachmentService;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * R5b admin fixes: audit chips coloured by meaning, readable attachment descriptions (old rows at display time,
 * new rows at the source), stacked audit cards, the Fund Usage footer total, and the branded 405 page.
 */
class R5bAdminFixesTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->travelTo(Carbon::create(2026, 11, 16, 10));
        $this->club = Club::factory()->create();
    }

    public static function chips(): array
    {
        return [
            ['member.removed', 'danger'], ['payment.deleted', 'danger'], ['fund_usage.voided', 'danger'],
            ['fund_usage.void_requested', 'danger'], ['payment.transfer_rejected', 'danger'], ['attachment.removed', 'danger'],
            ['fund_usage.approved', 'success'], ['payment.marked_paid', 'success'], ['payment.paid_via_webhook', 'success'],
            ['payment.transfer_approved', 'success'], ['club.bank_change_approved', 'success'],
            ['fund_usage.returned', 'warning'], ['payment.unmatched_webhook', 'warning'], ['payment.gateway_paid_twice', 'warning'],
            ['member.added', 'secondary'], ['payment.created', 'secondary'], ['fund_usage.created', 'secondary'],
            ['club.updated', 'secondary'], ['auth.login', 'secondary'], ['attachment.added', 'secondary'],
            ['attachment.viewed', 'secondary'], ['fund_usage.submitted', 'secondary'], ['something.new', 'secondary'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chips')]
    public function test_audit_chip_colour_follows_the_meaning(string $action, string $class): void
    {
        $this->assertSame($class, (new AuditLog(['action' => $action]))->action_badge_class);
    }

    public function test_old_attachment_rows_show_the_kind_and_record_number_without_changing_the_row(): void
    {
        $fu = Expense::factory()->forClub($this->club)->create();
        $ref = $fu->reference();
        $stored = "Document \"slip.pdf\" (proof_of_payment) attached to Expense #{$fu->id}.";
        AuditService::log('attachment.added', $stored, null, $this->club->id);
        AuditService::log('attachment.added', 'Document "x.pdf" (bank_slip) attached to Expense #999999.', null, $this->club->id);
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.audit-logs', $this->club))->assertOk()
            ->assertSeeText("Document \"slip.pdf\" (Proof of payment) attached to {$ref}.")
            ->assertSeeText('(Bank slip) attached to Expense #999999.')
            ->assertDontSeeText('(proof_of_payment)')
            // Stacked cards on small screens, in a positioned scroller.
            ->assertSee('table-stack', false)
            ->assertSee('class="table-responsive position-relative"', false);
        $this->assertSame($stored, AuditLog::where('description', $stored)->value('description'));
    }

    public function test_references_are_rewritten_only_for_the_rows_own_club_and_never_inside_quotes(): void
    {
        $other = Club::factory()->create();
        $theirFu = Expense::factory()->forClub($other)->create();
        $theirFee = Payment::factory()->forClub($other)->create();
        $ourFu = Expense::factory()->forClub($this->club)->create();
        $ourFee = Payment::factory()->forClub($this->club)->create();

        // Another club's record and fee numbers in this club's rows stay as written.
        AuditService::log('attachment.added', "Document \"a.pdf\" (invoice) attached to Expense #{$theirFu->id}.", null, $this->club->id);
        AuditService::log('payment.transfer_rejected', "Payment #{$theirFee->id}: slip rejected.", null, $this->club->id);
        // Numbers, kinds and dates inside a quoted file name stay as written, even for this club's own records.
        AuditService::log('attachment.added', "Document \"Expense #{$ourFu->id} Payment #{$ourFee->id} scan (other) 2026-01-05.pdf\" (other) attached to Expense #{$ourFu->id}.", null, $this->club->id);
        // A kind in free text (not right after the quoted name) is left alone.
        AuditService::log('club.updated', 'Note (other) kept.', null, $this->club->id);
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.audit-logs', $this->club))->assertOk()
            ->assertSeeText("attached to Expense #{$theirFu->id}.")
            ->assertDontSeeText($theirFu->reference())
            ->assertSeeText("Payment #{$theirFee->id}: slip rejected.")
            ->assertSeeText("Document \"Expense #{$ourFu->id} Payment #{$ourFee->id} scan (other) 2026-01-05.pdf\" (Other) attached to {$ourFu->reference()}.")
            ->assertSeeText('Note (other) kept.');
    }

    public function test_new_attachment_rows_are_written_readably(): void
    {
        Storage::fake('local');
        $this->actingAsClubAdmin($this->club);
        $fu = Expense::factory()->forClub($this->club)->create(['status' => 'draft']);
        app(AttachmentService::class)->store($fu, $this->club->id, UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'), 'invoice');

        $this->assertSame(
            "Document \"invoice.pdf\" (Invoice) attached to {$fu->reference()}.",
            AuditLog::where('action', 'attachment.added')->value('description'),
        );

        $fee = Payment::factory()->forClub($this->club)->create(['status' => 'pending']);
        app(AttachmentService::class)->store($fee, $this->club->id, UploadedFile::fake()->image('slip.png'), 'proof_of_payment');
        $this->assertSame(
            "Document \"slip.png\" (Proof of payment) attached to Payment #{$fee->id}.",
            AuditLog::where('action', 'attachment.added')->latest('id')->value('description'),
        );
    }

    public function test_fund_usage_footer_total_separates_the_counted_amount(): void
    {
        $this->actingAsClubAdmin($this->club);
        Expense::factory()->forClub($this->club)->approved()->create(['amount' => 100, 'expense_date' => '2026-03-01']);
        Expense::factory()->forClub($this->club)->create(['amount' => 40, 'expense_date' => '2026-03-02']);
        Expense::factory()->forClub($this->club)->voided()->create(['amount' => 7, 'expense_date' => '2026-03-03']);

        $this->get(route('admin.clubs.fund-usage.index', $this->club))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('pagination.totalAmount', 14000) // the All tab leaves void records out
                ->where('pagination.countedAmount', 10000)
                ->where('kpis.expensesYtd', 10000));
    }

    public function test_the_405_page_is_branded(): void
    {
        Route::middleware('web')->get('/__test/abort405', fn () => abort(405));

        $res = $this->get('/__test/abort405');
        $res->assertStatus(405)->assertSee('class="auth-card"', false)->assertSee('data-status="405"', false)
            ->assertSee('Tindakan itu tidak tersedia di sini');
    }

    public function test_a_post_to_a_get_only_page_gets_the_branded_405(): void
    {
        $this->post('/')->assertStatus(405)->assertSee('data-status="405"', false)->assertSee('class="auth-card"', false);
    }
}
