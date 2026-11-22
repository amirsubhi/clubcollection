<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings phase 2: the club's optional receipt footer (Club profile). Plain text, escaped, line breaks kept;
 * copied into the issuer snapshot so an issued receipt keeps the footer it was issued with.
 */
class ReceiptFooterTest extends TestCase
{
    private Club $club;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->club = Club::factory()->create(['name' => 'Kelab Rekreasi', 'receipt_footer' => "Terima kasih!\nBendahari: 012-345 6789"]);
        $this->member = User::factory()->create(['role' => 'member', 'name' => 'Ahli Contoh']);
        $this->club->members()->attach($this->member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Kelab Rekreasi',
            'financial_year_start_month' => 1,
        ];
    }

    private function paidFee(): Payment
    {
        $fee = Payment::factory()->forClub($this->club)->forMember($this->member)->pending()->create(['amount' => 50.00]);
        $fee->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);

        return $fee->fresh();
    }

    // ── Saving it on the club profile ────────────────────────────────────

    public function test_a_member_and_another_clubs_admin_cannot_set_it(): void
    {
        $this->actingAsMember($this->club);
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => 'Hijacked']))->assertForbidden();

        $this->actingAsClubAdmin(Club::factory()->create());
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => 'Hijacked']))->assertForbidden();

        $this->assertSame("Terima kasih!\nBendahari: 012-345 6789", $this->club->fresh()->receipt_footer);
    }

    public function test_the_clubs_admin_saves_it_with_line_breaks_and_it_is_audited(): void
    {
        $this->actingAsClubAdmin($this->club);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => "  Baris satu  \r\nBaris dua\r\n\r\n"]))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.clubs.profile.edit', $this->club));

        $this->assertSame("Baris satu\nBaris dua", $this->club->fresh()->receipt_footer);
        $log = AuditLog::where('action', 'club.profile_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame("Terima kasih!\nBendahari: 012-345 6789", $log->old_values['receipt_footer']);
        $this->assertSame("Baris satu\nBaris dua", $log->new_values['receipt_footer']);

        // Emptied: removed.
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => '   ']))->assertSessionHasNoErrors();
        $this->assertNull($this->club->fresh()->receipt_footer);
    }

    public function test_control_characters_and_long_text_are_refused(): void
    {
        $this->actingAsClubAdmin($this->club);

        foreach (["Tab\there", "Bell\x07", "Esc\x1b[31m", "Nu\x00ll", "Del\x7f"] as $bad) {
            $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => $bad]))
                ->assertSessionHasErrors('receipt_footer');
        }
        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => str_repeat('a', 301)]))
            ->assertSessionHasErrors('receipt_footer');

        $this->assertSame("Terima kasih!\nBendahari: 012-345 6789", $this->club->fresh()->receipt_footer);
    }

    public function test_the_longest_footer_fits_its_column_and_a_windows_line_break_counts_once(): void
    {
        $this->actingAsClubAdmin($this->club);
        // 300 characters of multibyte text (MariaDB counts characters, not bytes).
        $long = str_repeat('é', 149)."\r\n".str_repeat('ß', 150);

        $this->put(route('admin.clubs.profile.update', $this->club), $this->payload(['receipt_footer' => $long]))->assertSessionHasNoErrors();

        $this->assertSame(300, mb_strlen($this->club->fresh()->receipt_footer));
    }

    public function test_the_profile_page_escapes_it(): void
    {
        $this->club->forceFill(['receipt_footer' => '<script>alert(1)</script>'])->save();
        $this->actingAsClubAdmin($this->club);

        $this->get(route('admin.clubs.profile.edit', $this->club))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ── On receipts ─────────────────────────────────────────────────────

    public function test_an_issued_receipt_keeps_the_footer_it_was_issued_with(): void
    {
        $fee = $this->paidFee();
        $this->assertSame("Terima kasih!\nBendahari: 012-345 6789", $fee->issuer_snapshot['footer']);

        $this->club->forceFill(['receipt_footer' => 'Footer baharu'])->save();

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Invoice')
                ->where('receipt.issuer.footer', "Terima kasih!\nBendahari: 012-345 6789"));

        // The next receipt carries the new footer.
        $next = Payment::factory()->forClub($this->club)->forMember($this->member)->pending()
            ->create(['amount' => 20.00, 'period_start' => now()->addMonths(2)->startOfMonth()->toDateString()]);
        $next->update(['status' => 'paid', 'paid_date' => now()->toDateString()]);
        $this->assertSame('Footer baharu', $next->fresh()->issuer_snapshot['footer']);
    }

    public function test_a_snapshot_from_before_footers_shows_none(): void
    {
        $fee = $this->paidFee();
        $snap = $fee->issuer_snapshot;
        unset($snap['footer']);
        DB::table('payments')->where('id', $fee->id)->update(['issuer_snapshot' => json_encode($snap)]);

        $this->actingAs($this->member)->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $page) => $page->where('receipt.issuer.name', 'Kelab Rekreasi')
                ->where('receipt.issuer.footer', null));
    }

    public function test_the_receipt_email_shows_it_escaped_with_line_breaks(): void
    {
        $this->club->forceFill(['receipt_footer' => "<b>Bold?</b>\nLine two & more"])->save();
        $fee = $this->paidFee()->load(['user', 'club']);

        $html = (new PaymentConfirmation($fee))->render();

        $this->assertStringContainsString('&lt;b&gt;Bold?&lt;/b&gt;<br />', $html);
        $this->assertStringContainsString('Line two &amp; more', $html);
        $this->assertStringNotContainsString('<b>Bold?</b>', $html);
    }

    public function test_the_receipt_email_has_no_footer_paragraph_without_one(): void
    {
        $this->club->forceFill(['receipt_footer' => null])->save();
        $fee = $this->paidFee()->load(['user', 'club']);

        $this->assertStringNotContainsString('data-testid="receipt-footer"', (new PaymentConfirmation($fee))->render());
    }
}
