<?php

namespace Tests\Feature;

use App\Mail\AccountSecurityNotice;
use App\Mail\ClubAdminNotice;
use App\Mail\MemberWelcome;
use App\Mail\OverdueReminder;
use App\Mail\PaymentCancelled;
use App\Mail\PaymentConfirmation;
use App\Mail\TransferRejected;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentCancellation;
use App\Models\User;
use App\Services\Payments\PaymentCancellationService;
use App\Support\Portal\PortalSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Member emails go out in each member's language (User::preferredLocale(): saved, else the portal default), one
 * language per email. Sent for real through the array mailer, so the subject is the one built at send time.
 */
class MemberEmailLanguageTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 8, 20, 9));
        $this->club = Club::factory()->create(['name' => 'Kelab Contoh']);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage());
    }

    private function sentTo(User $user): Email
    {
        $mail = $this->sent()->first(fn (Email $m) => $m->getTo()[0]->getAddress() === $user->email);
        $this->assertNotNull($mail, "nothing sent to {$user->email}");

        return $mail;
    }

    private function member(?string $locale): User
    {
        $user = User::factory()->create(['role' => 'member', 'name' => 'Aminah Test']);
        $user->forceFill(['locale' => $locale])->save();
        $this->club->members()->attach($user, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user->fresh();
    }

    private function fee(User $user, array $attrs = []): Payment
    {
        return Payment::factory()->forClub($this->club)->forMember($user)->create($attrs + [
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-08-01', 'paid_date' => '2026-08-05',
        ])->load(['user', 'club']);
    }

    /**
     * Each member email in both languages: [mailable factory, Malay subject, Malay line, English subject, English line].
     *
     * @return iterable<string, array{\Closure(self, User): Mailable, string, string, string, string}>
     */
    public static function emails(): iterable
    {
        yield 'welcome' => [
            fn (self $t, User $u) => new MemberWelcome($u, $t->club, 'Temp-Pass-1234'),
            'Selamat datang ke Kelab Contoh — Butiran akaun anda', 'Ini kata laluan sementara.',
            'Welcome to Kelab Contoh — Your Account Details', 'This is a temporary password.',
        ];
        yield 'payment confirmation' => [
            fn (self $t, User $u) => new PaymentConfirmation($t->fee($u)),
            'Bayaran disahkan – Kelab Contoh', 'Bayaran anda telah diterima dan disahkan. Terima kasih!',
            'Payment Confirmed – Kelab Contoh', 'Your payment has been received and confirmed. Thank you!',
        ];
        yield 'overdue reminder' => [
            fn (self $t, User $u) => new OverdueReminder($t->fee($u, ['status' => 'overdue', 'paid_date' => null])),
            'Tindakan diperlukan: yuran kelab tertunggak — Kelab Contoh', 'Lewat 19 hari',
            'Action Required: Overdue Club Fee — Kelab Contoh', '19 days overdue',
        ];
        yield 'payment cancelled' => [
            function (self $t, User $u) {
                $fee = $t->fee($u, ['status' => 'pending', 'paid_date' => null]);
                $row = new PaymentCancellation;
                $row->forceFill(['club_id' => $t->club->id, 'payment_id' => $fee->id, 'event_key' => (string) Str::uuid(),
                    'receipt_no' => 'RCP-2026-0007', 'amount_sen' => 5000, 'reason_code' => 'refunded', 'created_at' => now()]);

                return new PaymentCancelled($fee, collect([$fee]), $row);
            },
            'Bayaran dibatalkan – Kelab Contoh', 'Bayaran ini telah dikembalikan kepada anda.',
            'Payment cancelled – Kelab Contoh', 'This payment was refunded to you.',
        ];
        yield 'transfer rejected' => [
            fn (self $t, User $u) => new TransferRejected(tap($t->fee($u, ['status' => 'pending', 'paid_date' => null]), fn ($f) => $f->forceFill(['rejection_reason' => 'Slip kabur'])->save())),
            'Slip pindahan perlu dihantar semula – Kelab Contoh', 'Sila semak sebab di atas',
            'Transfer slip needs another look – Kelab Contoh', 'Please read the reason above',
        ];
        yield 'sign-in paused' => [
            fn (self $t, User $u) => new AccountSecurityNotice($u, AccountSecurityNotice::KIND_SIGN_IN_PAUSED, 15),
            'Cubaan log masuk ke akaun anda – {portal}', 'log masuk dihentikan seketika selama 15 minit',
            'Sign-in attempts on your account – {portal}', 'signing in is paused for 15 minutes',
        ];
        yield 'password changed' => [
            fn (self $t, User $u) => new AccountSecurityNotice($u, AccountSecurityNotice::KIND_PASSWORD_CHANGED),
            'Kata laluan anda telah ditukar – {portal}', 'Jika itu anda, tiada apa yang perlu dibuat.',
            'Your password was changed – {portal}', 'If it was you, there is nothing to do.',
        ];
    }

    /** @param \Closure(self, User): Mailable $make */
    #[DataProvider('emails')]
    public function test_each_member_email_is_in_the_members_language_only(\Closure $make, string $msSubject, string $msLine, string $enSubject, string $enLine): void
    {
        $msSubject = str_replace('{portal}', (string) config('app.name'), $msSubject);
        $enSubject = str_replace('{portal}', (string) config('app.name'), $enSubject);
        $localeBefore = app()->getLocale();
        $malay = $this->member('ms');
        $english = $this->member('en');

        Mail::to($malay)->send($make($this, $malay));
        Mail::to($english)->send($make($this, $english));

        $ms = $this->sentTo($malay);
        $this->assertSame($msSubject, $ms->getSubject());
        $this->assertStringContainsString($msLine, $ms->getHtmlBody());
        $this->assertStringContainsString('<html lang="ms">', $ms->getHtmlBody());
        $this->assertStringNotContainsString($enLine, $ms->getHtmlBody());

        $en = $this->sentTo($english);
        $this->assertSame($enSubject, $en->getSubject());
        $this->assertStringContainsString($enLine, $en->getHtmlBody());
        $this->assertStringContainsString('<html lang="en">', $en->getHtmlBody());
        $this->assertStringNotContainsString($msLine, $en->getHtmlBody());

        // Sending never changes the locale of the request or command that sent it.
        $this->assertSame($localeBefore, app()->getLocale());
    }

    public function test_dates_use_the_member_app_month_names(): void
    {
        $malay = $this->member('ms');
        $english = $this->member('en');
        Mail::to($malay)->send(new PaymentConfirmation($this->fee($malay)));
        Mail::to($english)->send(new PaymentConfirmation($this->fee($english)));

        $this->assertStringContainsString('1 Ogo 2026 – 31 Ogo 2026', $this->sentTo($malay)->getHtmlBody());
        $this->assertStringContainsString('Bulanan', $this->sentTo($malay)->getHtmlBody());
        $this->assertStringContainsString('1 Aug 2026 – 31 Aug 2026', $this->sentTo($english)->getHtmlBody());
        $this->assertStringContainsString('Monthly', $this->sentTo($english)->getHtmlBody());
    }

    public function test_no_saved_language_follows_the_portal_default(): void
    {
        $member = $this->member(null);
        Mail::to($member)->send(new PaymentConfirmation($this->fee($member)));
        $this->assertSame('Bayaran disahkan – Kelab Contoh', $this->sentTo($member)->getSubject());

        PortalSettings::put(['default_locale' => 'en']);
        $other = $this->member(null);
        Mail::to($other)->send(new PaymentConfirmation($this->fee($other)));
        $this->assertSame('Payment Confirmed – Kelab Contoh', $this->sentTo($other)->getSubject());
    }

    /** One run of the reminders for two members of different languages, in both orders: no language leaks. */
    #[DataProvider('orders')]
    public function test_one_overdue_run_mails_each_member_in_their_own_language(string $first, string $second): void
    {
        $members = [];
        foreach ([$first, $second] as $locale) {
            $members[$locale] = $this->member($locale);
            $this->fee($members[$locale], ['status' => 'pending', 'paid_date' => null]);
        }

        Artisan::call('payments:send-overdue-reminders');

        $this->assertCount(2, $this->sent());
        $this->assertSame('Tindakan diperlukan: yuran kelab tertunggak — Kelab Contoh', $this->sentTo($members['ms'])->getSubject());
        $this->assertStringContainsString('Lewat 19 hari', $this->sentTo($members['ms'])->getHtmlBody());
        $this->assertSame('Action Required: Overdue Club Fee — Kelab Contoh', $this->sentTo($members['en'])->getSubject());
        $this->assertStringContainsString('19 days overdue', $this->sentTo($members['en'])->getHtmlBody());
        $this->assertStringNotContainsString('Lewat', $this->sentTo($members['en'])->getHtmlBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function orders(): iterable
    {
        yield 'Malay first' => ['ms', 'en'];
        yield 'English first' => ['en', 'ms'];
    }

    /**
     * Laravel's reset-link email (subject "Reset your password" in laravel/framework 13's ResetPassword, translated
     * through lang/ms.json): the account's saved language, else the portal default, never the language of the
     * browser that asked for it (on a shared browser that may be someone else's).
     */
    public function test_the_password_reset_email_is_in_the_members_language(): void
    {
        $malay = $this->member('ms');
        $english = $this->member('en');
        $unsaved = $this->member(null);

        $this->post('/password/email?lang=en', ['email' => $malay->email])->assertRedirect();
        $this->post('/password/email?lang=bm', ['email' => $english->email])->assertRedirect();
        $this->withUnencryptedCookie('cp_lang', 'en')->post('/password/email?lang=en', ['email' => $unsaved->email])->assertRedirect();

        $ms = $this->sentTo($malay);
        $this->assertSame('Tetapkan semula kata laluan anda', $ms->getSubject());
        $this->assertStringContainsString('Anda menerima e-mel ini kerana terdapat permintaan untuk menetapkan semula kata laluan akaun anda.', $ms->getHtmlBody());
        $this->assertStringContainsString('Jika butang "Tetapkan semula kata laluan" tidak berfungsi', html_entity_decode($ms->getHtmlBody()));
        $this->assertStringContainsString('Salam hormat,', $ms->getHtmlBody());

        $en = $this->sentTo($english);
        $this->assertSame('Reset your password', $en->getSubject());
        $this->assertStringContainsString('You are receiving this email because we received a password reset request', $en->getHtmlBody());

        // Nothing saved: the portal default, not the page's (or cookie's) English.
        $this->assertSame('Tetapkan semula kata laluan anda', $this->sentTo($unsaved)->getSubject());
        $this->assertNull($unsaved->fresh()->locale);
    }

    public function test_the_password_reset_email_without_any_choice_is_in_the_portal_default(): void
    {
        $member = $this->member(null);
        $this->post('/password/email', ['email' => $member->email])->assertRedirect();
        $this->assertSame('Tetapkan semula kata laluan anda', $this->sentTo($member)->getSubject());

        PortalSettings::put(['default_locale' => 'en']);
        $other = $this->member(null);
        $this->post('/password/email?lang=bm', ['email' => $other->email])->assertRedirect();
        $this->assertSame('Reset your password', $this->sentTo($other)->getSubject());
    }

    /** The admin notice is addressed by email (no locale preference): pinned to English, even inside a Malay request. */
    public function test_an_admin_notice_sent_during_a_malay_member_request_is_english(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->club->members()->attach($admin, ['role' => 'admin', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);
        $member = $this->member('ms');

        $this->actingAs($member);
        app()->setLocale('ms'); // as a Malay member's sign-in-style page sets it (AuthPageLocale)
        $this->club->notifyAdmins('Bank details changed', ['A line.'], null, null, $member);

        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($admin->email) && $m->locale === 'en');
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($member->email) && $m->locale === 'en');
        $this->assertSame('ms', app()->getLocale());
    }

    /** The welcome email to a new admin account stays English (admin emails), whatever the default. */
    public function test_the_admin_account_welcome_stays_english(): void
    {
        $this->actingAsSuperAdmin();
        $this->post(route('admin.admins.store'), ['name' => 'Ali Admin', 'email' => 'ali.admin@example.test', 'role' => 'admin'])->assertRedirect();

        $mail = $this->sent()->first(fn (Email $m) => $m->getTo()[0]->getAddress() === 'ali.admin@example.test');
        $this->assertNotNull($mail);
        $this->assertStringStartsWith('Welcome to ', (string) $mail->getSubject());
        $this->assertStringContainsString('<html lang="en">', $mail->getHtmlBody());
    }

    /** Every sentence of the member emails has its Malay in lang/ms.json, so no Malay email silently shows English. */
    public function test_every_member_email_sentence_has_a_malay_translation(): void
    {
        $root = dirname(__DIR__, 2);
        $ms = json_decode((string) file_get_contents($root.'/lang/ms.json'), true, 512, JSON_THROW_ON_ERROR);
        $files = array_merge(glob($root.'/resources/views/emails/*.blade.php') ?: [], glob($root.'/app/Mail/*.php') ?: []);
        $keys = [];
        foreach ($files as $file) {
            if (str_contains($file, 'club-admin-notice') || str_contains($file, 'ClubAdminNotice')) {
                continue; // admin email: English only
            }
            preg_match_all('/(?:__|trans_choice|MailText::bold)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', (string) file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $keys[] = stripslashes($match[2] ?? '' ?: $match[1]);
            }
        }
        $keys = array_merge($keys, array_values(PaymentCancellationService::MEMBER_WORDING), [
            // Laravel's ResetPassword notification and mail layout.
            'Reset your password', 'Reset Password', 'Hello!', 'Regards,', 'All rights reserved.',
            'You are receiving this email because we received a password reset request for your account.',
            'This password reset link will expire in :count minutes.',
            'If you did not request a password reset, no further action is required.',
            "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:",
        ]);
        $this->assertGreaterThan(60, count(array_unique($keys)));
        foreach (array_unique($keys) as $key) {
            $this->assertArrayHasKey($key, $ms, "lang/ms.json has no Malay for: {$key}");
            $this->assertNotSame('', trim($ms[$key]), $key);
            preg_match_all('/:(\w+)/', $key, $a);
            preg_match_all('/:(\w+)/', $ms[$key], $b);
            // A trans_choice key ("one|other") names :count in each form; the translation may have fewer forms.
            $this->assertEqualsCanonicalizing(array_values(array_unique($a[1])), array_values(array_unique($b[1])), "placeholders differ: {$key}");
            $this->assertSame(substr_count($key, '**'), substr_count($ms[$key], '**'), "bold markers differ: {$key}");
        }
    }

    /**
     * The per-member language only applies when Mail::to() gets the User (HasLocalePreference); with a plain email
     * address the mail would go out in whatever language the sender's request had. Every send site passes the
     * model; only the admin notice (English, ClubAdminNotice) is addressed by email.
     */
    public function test_every_member_email_is_addressed_to_the_user_model(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $found = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all('/Mail::to\(([^)]*)\)/', $code, $m);
            foreach ($m[1] as $arg) {
                $arg = trim($arg);
                if (in_array($arg, ['$user', '$payment->user', '$lead->user'], true)) {
                    $found++;

                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $this->assertTrue($relative === 'Models/Club.php' && $arg === '$email',
                    "Mail::to({$arg}) in app/{$relative}: pass the User so the mail uses the member's language.");
            }
        }
        $this->assertGreaterThanOrEqual(14, $found);
    }
}
