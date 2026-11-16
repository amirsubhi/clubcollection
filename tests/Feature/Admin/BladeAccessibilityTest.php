<?php

namespace Tests\Feature\Admin;

use App\Models\Club;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Accessibility smoke test for the classic Blade admin pages (R1d): one h1,
 * a lang attribute, every label tied to a real control, every
 * aria-describedby pointing at an element on the page, and the same sidebar
 * as the Inertia pages (AdminNav), Verifications included.
 */
class BladeAccessibilityTest extends TestCase
{
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    /** The checks every Blade admin page must pass. */
    private function assertAccessible(string $url, string $label): DOMXPath
    {
        $res = $this->get($url);
        $this->assertSame(200, $res->status(), "{$label}: status");
        $x = $this->dom($res->getContent());

        $this->assertSame('en', $x->query('/html/@lang')->item(0)?->nodeValue, "{$label}: <html lang>");
        $this->assertSame(1, $x->query('//h1')->length, "{$label}: exactly one <h1>");
        $this->assertNotSame('', trim($x->query('//h1')->item(0)->textContent), "{$label}: the h1 has text");
        $this->assertStringNotContainsString('Confidentiality Notice', $res->getContent(), "{$label}: no confidentiality footer");

        foreach ($x->query('//label') as $l) {
            $for = $l->getAttribute('for');
            $text = trim(preg_replace('/\s+/', ' ', $l->textContent));
            if ($for === '') {
                $this->assertGreaterThan(0, $x->query('.//input|.//select|.//textarea', $l)->length, "{$label}: label \"{$text}\" has no for and wraps no control");

                continue;
            }
            $target = $x->query("//*[@id='{$for}']");
            $this->assertSame(1, $target->length, "{$label}: label \"{$text}\" for=\"{$for}\" matches exactly one id");
            $this->assertContains($target->item(0)->nodeName, ['input', 'select', 'textarea'], "{$label}: label \"{$text}\" points at a form control");
        }

        // Visible form controls carry a name a screen reader can read.
        foreach ($x->query('//input[not(@type="hidden") and not(@type="submit")]|//select|//textarea') as $c) {
            $id = $c->getAttribute('id');
            $named = ($id !== '' && $x->query("//label[@for='{$id}']")->length > 0)
                || $c->getAttribute('aria-label') !== '' || $c->getAttribute('aria-labelledby') !== ''
                || $x->query('ancestor::label', $c)->length > 0;
            $this->assertTrue($named, "{$label}: control name=\"{$c->getAttribute('name')}\" has an accessible name");
        }

        foreach ($x->query('//*[@aria-describedby]') as $el) {
            foreach (preg_split('/\s+/', trim($el->getAttribute('aria-describedby'))) as $ref) {
                $this->assertSame(1, $x->query("//*[@id='{$ref}']")->length, "{$label}: aria-describedby \"{$ref}\" exists");
            }
        }

        // Buttons and links with only an icon still have a name.
        foreach ($x->query('//button|//a') as $b) {
            $name = trim($b->textContent).$b->getAttribute('aria-label').$b->getAttribute('title');
            $this->assertNotSame('', trim($name), "{$label}: a button or link has no accessible name (".$b->getAttribute('class').')');
        }

        return $x;
    }

    public function test_club_admin_pages_pass_the_smoke_test(): void
    {
        $admin = $this->actingAsClubAdmin($this->club);
        $member = User::factory()->create(['name' => 'Siti Contoh']);
        $this->club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => '2026-01-05', 'is_active' => true]);
        $payment = Payment::factory()->forClub($this->club)->forMember($member)->pending()->create();
        $discount = Discount::factory()->for($this->club)->create();
        ExpenseCategory::factory()->for($this->club)->create(['name' => 'Events']);

        $pages = [
            'members' => route('admin.clubs.members.index', $this->club),
            'member create' => route('admin.clubs.members.create', $this->club),
            'member edit' => route('admin.members.edit', ['member' => $member, 'club' => $this->club->id]),
            'member import' => route('admin.members.import', $this->club),
            'payments' => route('admin.clubs.payments.index', $this->club),
            'payment create' => route('admin.clubs.payments.create', $this->club),
            'payment show' => route('admin.payments.show', $payment),
            'payment edit' => route('admin.payments.edit', $payment),
            'fee rates' => route('admin.clubs.fee-rates.index', $this->club),
            'fee rates create' => route('admin.clubs.fee-rates.create', $this->club),
            'discounts' => route('admin.clubs.discounts.index', $this->club),
            'discount create' => route('admin.clubs.discounts.create', $this->club),
            'discount edit' => route('admin.discounts.edit', $discount),
            'expense categories' => route('admin.clubs.expense-categories.index', $this->club),
            'club audit log' => route('admin.clubs.audit-logs', $this->club),
            'security' => route('profile.security'),
        ];
        foreach ($pages as $label => $url) {
            $this->assertAccessible($url, $label);
        }
    }

    public function test_super_admin_pages_pass_the_smoke_test(): void
    {
        $this->actingAsSuperAdmin();
        $other = User::factory()->create(['role' => 'admin']);

        $pages = [
            'clubs' => route('admin.clubs.index'),
            'club create' => route('admin.clubs.create'),
            'club edit' => route('admin.clubs.edit', $this->club),
            'club show' => route('admin.clubs.show', $this->club),
            'admins' => route('admin.admins.index'),
            'admin create' => route('admin.admins.create'),
            'admin edit' => route('admin.admins.edit', $other),
            'statistics' => route('admin.statistics'),
            'audit log (all clubs)' => route('admin.audit-logs.index'),
            'portal home' => route('home'),
        ];
        foreach ($pages as $label => $url) {
            $this->assertAccessible($url, $label);
        }
    }

    /** The Blade sidebar is AdminNav: same items, same order as the Inertia pages, Verifications with its count. */
    public function test_blade_sidebar_matches_the_inertia_nav(): void
    {
        $admin = $this->actingAsClubAdmin($this->club);
        Payment::factory()->forClub($this->club)->create(['status' => 'pending_verification', 'paid_date' => null]);

        $x = $this->assertAccessible(route('admin.clubs.members.index', $this->club), 'members');
        $keys = array_map(fn ($a) => $a->getAttribute('data-nav'), iterator_to_array($x->query('//nav[@aria-label="Admin"]//a')));

        $expected = collect(\App\Support\Admin\AdminNav::props($this->club, $admin)['groups'])->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
        $this->assertSame($expected, $keys);
        $this->assertContains('verifications', $keys);
        $this->assertSame(['dashboard'], array_values(array_filter($keys, fn ($k) => $k === 'dashboard')), 'one Dashboard link');

        $verify = $x->query('//nav[@aria-label="Admin"]//a[@data-nav="verifications"]')->item(0);
        $this->assertSame(route('admin.clubs.verifications.index', $this->club), $verify->getAttribute('href'));
        $this->assertStringContainsString('1', $verify->textContent, 'waiting slips are counted');

        $current = $x->query('//nav[@aria-label="Admin"]//a[@aria-current="page"]');
        $this->assertSame(1, $current->length);
        $this->assertSame('members', $current->item(0)->getAttribute('data-nav'));
        $this->assertStringContainsString('Club Portal', $x->query('//aside')->item(0)->textContent);
    }

    /** A page without a club in the URL (Security) lists the club the admin last worked in. */
    public function test_pages_without_a_club_keep_the_club_nav(): void
    {
        $this->actingAsClubAdmin($this->club);
        $x = $this->assertAccessible(route('profile.security'), 'security');

        $this->assertSame(1, $x->query('//nav[@aria-label="Admin"]//a[@data-nav="verifications"]')->length);
        $this->assertSame('security', $x->query('//a[@aria-current="page"]')->item(0)->getAttribute('data-nav'));
    }

    /** Validation errors are tied to their field and announced. */
    public function test_errors_are_linked_to_their_fields(): void
    {
        $this->actingAsClubAdmin($this->club);
        $this->from(route('admin.clubs.members.create', $this->club))
            ->post(route('admin.clubs.members.store', $this->club), ['name' => '', 'email' => 'not-an-email', 'job_level' => '', 'joined_date' => ''])
            ->assertRedirect();

        $x = $this->assertAccessible(route('admin.clubs.members.create', $this->club), 'member create with errors');
        $email = $x->query('//input[@name="email"]')->item(0);
        $this->assertSame('true', $email->getAttribute('aria-invalid'));
        $this->assertContains('f-email-error', preg_split('/\s+/', $email->getAttribute('aria-describedby')));
        $this->assertSame('alert', $x->query('//*[@id="f-email-error"]')->item(0)->getAttribute('role'));
    }

    /** Flash messages: errors are alerts, successes are status messages. */
    public function test_flash_messages_have_roles(): void
    {
        $this->actingAsClubAdmin($this->club);
        $x = $this->dom($this->withSession(['error' => 'Something failed.', 'success' => 'Saved.'])
            ->get(route('admin.clubs.members.index', $this->club))->getContent());

        $this->assertStringContainsString('Something failed.', $x->query('//*[@role="alert"]')->item(0)->textContent);
        $this->assertStringContainsString('Saved.', $x->query('//*[@role="status"]')->item(0)->textContent);
    }
}
