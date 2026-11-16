<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Support\Contact\WhatsAppNumber;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Optional WhatsApp help per club (R2b): the club admin sets a number on Billing settings (normalised to E.164,
 * audited); member payment pages then get its wa.me digits, else the club email as before.
 */
class WhatsAppHelpTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'mobile, local' => ['012-345 6789', '+60123456789'],
            'mobile 11 digits' => ['011-2345 6789', '+601123456789'],
            'with country code' => ['60 12 345 6789', '+60123456789'],
            'E.164 with spaces' => ['+60 12-345 6789', '+60123456789'],
            'international prefix' => ['0060123456789', '+60123456789'],
            'brackets and dots' => ['(012) 345.6789', '+60123456789'],
            'KL fixed line' => ['03-2123 4567', '+60321234567'],
            'Singapore' => ['+65 9123 4567', '+6591234567'],
            'too short' => ['012-345', null],
            'letters' => ['012-CALL-ME', null],
            'no trunk or country code' => ['123456789', null],
            'Malaysian number too long' => ['+60 12 3456 78901', null],
            'trunk zero after 60 dropped' => ['+60 012 345 6789', '+60123456789'],
            'trunk zero after 60, no plus' => ['60 012-345 6789', '+60123456789'],
            'mobile 013-00x is not toll-free' => ['013-007 1234', '+60130071234'],
            'toll-free 1-800' => ['1-800-88-1234', null],
            'toll-free 1-800 with 60' => ['+60 1800 88 1234', null],
            'toll-free 1-300' => ['1300-88-1234', null],
            'toll-free 1-300 local trunk' => ['01300-88-1234', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_normalised_to_e164(string $input, ?string $want): void
    {
        $this->assertSame($want, WhatsAppNumber::normalise($input));
    }

    public function test_wa_digits_and_display(): void
    {
        $this->assertSame('60123456789', WhatsAppNumber::waDigits('+60123456789'));
        $this->assertNull(WhatsAppNumber::waDigits(null));
        $this->assertNull(WhatsAppNumber::waDigits('0123456789'));
        $this->assertSame('+60 12-345 6789', WhatsAppNumber::display('+60123456789'));
        $this->assertSame('+6591234567', WhatsAppNumber::display('+6591234567'));
        $this->assertSame('', WhatsAppNumber::display(null));
    }

    private function billing(array $overrides = []): array
    {
        return array_merge([
            'allow_monthly' => '1', 'allow_quarterly' => '1', 'allow_yearly' => '1',
            'default_plan' => 'yearly', 'gateway_fee_payer' => 'club', 'gateway_fee' => '1.00',
        ], $overrides);
    }

    public function test_club_admin_sets_the_number_on_billing_settings_and_it_is_audited(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz']);
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.billing.edit', $club))
            ->assertInertia(fn (Assert $p) => $p->where('settings.whatsapp_number', ''));

        $this->put(route('admin.clubs.billing.update', $club), $this->billing(['whatsapp_number' => '012-345 6789']))
            ->assertSessionHasNoErrors();
        $this->assertSame('+60123456789', $club->fresh()->whatsapp_number);

        $log = AuditLog::where('action', 'club.billing_updated')->latest('id')->firstOrFail();
        $this->assertNull($log->old_values['whatsapp_number']);
        $this->assertSame('+60123456789', $log->new_values['whatsapp_number']);
        $this->assertStringContainsString('WhatsApp help on', $log->description);

        // Shown back the way people write it.
        $this->get(route('admin.clubs.billing.edit', $club))
            ->assertInertia(fn (Assert $p) => $p->where('settings.whatsapp_number', '+60 12-345 6789'));

        // Left out by an older client: kept. Cleared: back to email.
        $this->put(route('admin.clubs.billing.update', $club), $this->billing())->assertSessionHasNoErrors();
        $this->assertSame('+60123456789', $club->fresh()->whatsapp_number);
        $this->put(route('admin.clubs.billing.update', $club), $this->billing(['whatsapp_number' => '']))->assertSessionHasNoErrors();
        $this->assertNull($club->fresh()->whatsapp_number);
    }

    public function test_an_invalid_number_is_refused_with_a_plain_message(): void
    {
        $club = Club::factory()->create(['payment_gateway' => 'billplz', 'whatsapp_number' => '+60123456789']);
        $this->actingAsClubAdmin($club);

        $this->from(route('admin.clubs.billing.edit', $club))
            ->put(route('admin.clubs.billing.update', $club), $this->billing(['whatsapp_number' => '12345']))
            ->assertSessionHasErrors(['whatsapp_number' => 'Enter a phone number WhatsApp can reach, e.g. 012-345 6789 or +60 12-345 6789.']);
        $this->assertSame('+60123456789', $club->fresh()->whatsapp_number);
    }

    public function test_member_payment_pages_get_the_wa_me_digits_when_set_else_email(): void
    {
        $this->withoutVite();
        $club = Club::factory()->create(['email' => 'bendahari@example.test', 'whatsapp_number' => '+60123456789']);
        $member = $this->actingAsMember($club);
        $fee = Payment::factory()->forClub($club)->forMember($member)->pending()->create(['amount' => 50.00]);
        // A second open fee, so "Bayar semua" shows its own page.
        Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'amount' => 50.00, 'period_start' => $fee->period_start->copy()->addMonth(), 'period_end' => $fee->period_end->copy()->addMonth(),
        ]);

        foreach ([route('member.payments.invoice', $fee), route('member.payments.review', $fee), route('member.payments.review-all', $club)] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('payClub.whatsapp', '60123456789')
                ->where('payClub.email', 'bendahari@example.test'));
        }

        $club->update(['whatsapp_number' => null]);
        $this->get(route('member.payments.invoice', $fee))
            ->assertInertia(fn (Assert $p) => $p->where('payClub.whatsapp', null)->where('payClub.email', 'bendahari@example.test'));
    }
}
