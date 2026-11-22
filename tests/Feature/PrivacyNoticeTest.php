<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\PortalSetting;
use App\Support\Portal\PrivacyNotice;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings phase 2: the portal's privacy notice (BM + English) and data-protection contact. Edited by the super
 * admin on its own page; shown to anyone on /privacy (plain text, escaped), with a neutral placeholder until set.
 */
class PrivacyNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'privacy_notice_ms' => "Kami menyimpan nama dan e-mel anda.\n\nAnda boleh meminta salinan data anda.",
            'privacy_notice_en' => "We keep your name and email.\n\nYou may ask for a copy of your data.",
            'privacy_contact_name' => 'Setiausaha Kelab',
            'privacy_contact_email' => 'privasi@example.test',
        ];
    }

    // ── Who may edit it ──────────────────────────────────────────────────

    public function test_only_the_super_admin_may_open_or_save_it(): void
    {
        $club = Club::factory()->create();

        $this->get(route('admin.privacy-notice.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.privacy-notice.update'), $this->payload())->assertRedirect(route('login'));

        $this->actingAsMember($club);
        $this->get(route('admin.privacy-notice.edit'))->assertForbidden();
        $this->put(route('admin.privacy-notice.update'), $this->payload())->assertForbidden();

        $this->actingAsClubAdmin($club);
        $this->get(route('admin.privacy-notice.edit'))->assertForbidden();
        $this->put(route('admin.privacy-notice.update'), $this->payload())->assertForbidden();

        $this->assertSame(0, PortalSetting::count());

        $this->actingAsSuperAdmin();
        $this->get(route('admin.privacy-notice.edit'))->assertOk()->assertSee('Privacy notice');
    }

    public function test_the_super_admin_saves_it_and_every_change_is_audited(): void
    {
        $this->actingAsSuperAdmin();

        $this->put(route('admin.privacy-notice.update'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.privacy-notice.edit'));

        $all = PrivacyNotice::all();
        $this->assertSame("We keep your name and email.\n\nYou may ask for a copy of your data.", $all['privacy_notice_en']);
        $this->assertSame('privasi@example.test', $all['privacy_contact_email']);
        $log = AuditLog::where('action', 'portal.privacy_updated')->sole();
        $this->assertNull($log->old_values['privacy_notice_ms']);
        $this->assertSame('Setiausaha Kelab', $log->new_values['privacy_contact_name']);
        $this->assertNull($log->club_id);

        // Only what changed is logged; clearing a field removes its row.
        $this->put(route('admin.privacy-notice.update'), $this->payload(['privacy_contact_name' => '', 'privacy_notice_en' => "We keep your name and email.\r\n\r\nYou may ask for a copy of your data."]))
            ->assertSessionHasNoErrors();
        $second = AuditLog::where('action', 'portal.privacy_updated')->latest('id')->first();
        $this->assertSame(['privacy_contact_name'], array_keys($second->new_values));
        $this->assertFalse(PortalSetting::where('key', 'privacy_contact_name')->exists());

        // Nothing changed: no audit row.
        $this->put(route('admin.privacy-notice.update'), $this->payload(['privacy_contact_name' => '']))->assertSessionHasNoErrors();
        $this->assertSame(2, AuditLog::where('action', 'portal.privacy_updated')->count());
    }

    public function test_control_characters_bad_emails_and_long_text_are_refused(): void
    {
        $this->actingAsSuperAdmin();

        foreach ([
            ['privacy_notice_ms' => "Tab\tdi sini"],
            ['privacy_notice_en' => "Esc\x1b[31m"],
            ['privacy_notice_en' => str_repeat('a', PrivacyNotice::MAX_TEXT + 1)],
            ['privacy_contact_name' => "Line\nbreak"],
            ['privacy_contact_name' => str_repeat('n', 101)],
            ['privacy_contact_email' => 'not-an-email'],
            ['privacy_contact_email' => "a@example.test\r\nBcc: x@example.test"],
            ['privacy_contact_email' => '"x?bcc=a@evil.test"@club.example'],
            ['privacy_contact_email' => 'dpo?cc=a@club.example'],
            ['privacy_contact_name' => "Nama \u{202E}terbalik"],
        ] as $bad) {
            $this->put(route('admin.privacy-notice.update'), $this->payload($bad))->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertSame(0, PortalSetting::count());
    }

    public function test_the_longest_notice_is_accepted(): void
    {
        $this->actingAsSuperAdmin();
        // Multibyte: MAX_TEXT characters must fit the TEXT column (65,535 bytes) with room to spare.
        $long = str_repeat('é', PrivacyNotice::MAX_TEXT);

        $this->put(route('admin.privacy-notice.update'), $this->payload(['privacy_notice_ms' => $long]))->assertSessionHasNoErrors();

        $this->assertSame(PrivacyNotice::MAX_TEXT, mb_strlen(PrivacyNotice::all()['privacy_notice_ms']));
    }

    public function test_the_super_admin_nav_links_to_it(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.portal-settings.edit'))->assertOk()->assertSee(route('admin.privacy-notice.edit'), false);
    }

    // ── The public page ──────────────────────────────────────────────────

    public function test_with_nothing_set_the_page_shows_a_neutral_placeholder_in_bm_then_en(): void
    {
        $this->get(route('privacy'))->assertOk()
            ->assertSee('Notis privasi')
            ->assertSee('Kelab belum menerbitkan notis privasinya. Tanya pentadbir kelab anda.')
            ->assertDontSee('data-testid="privacy-contact"', false);

        $this->get(route('privacy', ['lang' => 'en']))->assertOk()
            ->assertSee('The club has not published its privacy notice yet. Ask your club administrator.');
    }

    public function test_the_page_shows_the_notice_in_the_chosen_language_with_the_contact(): void
    {
        PrivacyNotice::put($this->payload());

        $this->get(route('privacy'))->assertOk()
            ->assertSee('<p>Kami menyimpan nama dan e-mel anda.</p>', false)
            ->assertSee('<p>Anda boleh meminta salinan data anda.</p>', false)
            ->assertDontSee('We keep your name')
            ->assertSee('Setiausaha Kelab')
            ->assertSee('href="mailto:privasi@example.test"', false);

        $this->get(route('privacy', ['lang' => 'en']))->assertOk()
            ->assertSee('<p>We keep your name and email.</p>', false)
            ->assertDontSee('Kami menyimpan');
    }

    public function test_only_one_language_set_shows_it_and_says_so(): void
    {
        PrivacyNotice::put(['privacy_notice_ms' => 'Hanya BM.']);

        $this->get(route('privacy', ['lang' => 'en']))->assertOk()
            ->assertSee('Hanya BM.')
            ->assertSee('This notice is available in Bahasa Melayu only.');
    }

    public function test_the_notice_is_plain_text_escaped_with_line_breaks_kept(): void
    {
        PrivacyNotice::put([
            'privacy_notice_ms' => "<script>alert(1)</script>\nBaris dua **tebal** [pautan](javascript:alert(1))",
            'privacy_contact_name' => '<b>Nama</b>',
        ]);

        $this->get(route('privacy'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;<br />', false)
            ->assertSee('**tebal** [pautan](javascript:alert(1))', false)
            ->assertDontSee('<b>Nama</b>', false)
            ->assertSee('&lt;b&gt;Nama&lt;/b&gt;', false);
    }

    public function test_a_signed_in_member_can_open_it_too(): void
    {
        $this->actingAsMember(Club::factory()->create());

        $this->get(route('privacy'))->assertOk()->assertSee('Notis privasi');
    }

    public function test_the_sign_in_page_and_the_member_app_link_to_it(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('data-testid="auth-privacy-link"', false)
            ->assertSee(route('privacy'), false);

        $this->actingAsMember(Club::factory()->create());
        $this->get(route('member.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('Member/Home')->where('account.privacyUrl', route('privacy')));
    }
}
