<?php

namespace Tests\Feature\Admin;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    private Club $club;

    private User $admin;

    private Attachment $attachment;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->club = Club::factory()->create();
        $this->admin = $this->actingAsClubAdmin($this->club);
        $category = ExpenseCategory::factory()->for($this->club)->create();

        $file = UploadedFile::fake()->image('receipt.jpg', 40, 40);
        $this->original = file_get_contents($file->getRealPath());

        $this->post(route('admin.clubs.fund-usage.store', $this->club), [
            'intent' => 'draft', 'type' => 'expense', 'expense_category_id' => $category->id,
            'description' => 'Stationery', 'payee' => 'Popular Bookstore', 'amount' => '12.30',
            'expense_date' => now()->toDateString(),
            'files' => [$file], 'kinds' => ['receipt'],
        ])->assertSessionHasNoErrors();

        $this->attachment = Attachment::firstOrFail();
    }

    public function test_file_is_stored_privately_encrypted_with_sha256(): void
    {
        $a = $this->attachment;
        $expense = Expense::firstOrFail();

        $this->assertSame(Expense::class, $a->attachable_type);
        $this->assertSame($expense->id, (int) $a->attachable_id);
        $this->assertSame($this->club->id, (int) $a->club_id);
        $this->assertSame('receipt', $a->kind);
        $this->assertSame('receipt.jpg', $a->original_name);
        $this->assertSame('image/jpeg', $a->mime);
        $this->assertSame(strlen($this->original), $a->size);
        $this->assertSame(hash('sha256', $this->original), $a->sha256);
        $this->assertSame($this->admin->id, (int) $a->uploaded_by);
        $this->assertMatchesRegularExpression('#^clubs/'.$this->club->id.'/attachments/'.now()->format('Y').'/[A-Za-z0-9]{40}\.bin$#', $a->path);

        Storage::disk('local')->assertExists($a->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $stored = Storage::disk('local')->get($a->path);
        $this->assertNotSame($this->original, $stored, 'encrypted at rest');
        $this->assertSame($this->original, Crypt::decryptString($stored));

        $log = AuditLog::where('action', 'attachment.added')->firstOrFail();
        $this->assertSame($a->sha256, $log->new_values['sha256']);
    }

    public function test_club_admin_downloads_original_file_and_it_is_audited(): void
    {
        $res = $this->get(route('admin.attachments.show', $this->attachment));

        $res->assertOk();
        $this->assertSame($this->original, $res->getContent());
        $this->assertStringContainsString('attachment;', $res->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));

        $log = AuditLog::where('action', 'attachment.downloaded')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertSame($this->attachment->id, (int) $log->auditable_id);
        $this->assertSame($this->club->id, (int) $log->club_id);
    }

    public function test_inline_view_is_audited_separately(): void
    {
        $res = $this->get(route('admin.attachments.show', ['attachment' => $this->attachment, 'inline' => 1]));

        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $res->headers->get('Content-Disposition'));
        $this->assertTrue(AuditLog::where('action', 'attachment.viewed')->exists());
    }

    public function test_admin_of_another_club_gets_403(): void
    {
        $this->actingAsClubAdmin(Club::factory()->create());

        $this->get(route('admin.attachments.show', $this->attachment))->assertForbidden();
        $this->assertFalse(AuditLog::where('action', 'attachment.downloaded')->exists());
    }

    public function test_member_gets_403_even_in_the_same_club(): void
    {
        $this->actingAsMember($this->club);

        $this->get(route('admin.attachments.show', $this->attachment))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('admin.attachments.show', $this->attachment))->assertRedirect(route('login'));
    }

    public function test_super_admin_can_download(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.attachments.show', $this->attachment))->assertOk();
    }

    public function test_download_route_is_throttled(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'admin.attachments.show');
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    public function test_page_props_never_expose_storage_paths(): void
    {
        $this->withoutVite();
        $expense = Expense::firstOrFail();

        $res = $this->get(route('admin.fund-usage.show', $expense));
        $res->assertOk()->assertInertia(fn ($page) => $page
            ->where('item.attachments.0.url', route('admin.attachments.show', ['attachment' => $this->attachment, 'inline' => 1]))
            ->where('item.attachments.0.downloadUrl', route('admin.attachments.show', $this->attachment))
            ->where('item.attachments.0.sha256', $this->attachment->sha256)
            ->missing('item.attachments.0.path'));
        $this->assertStringNotContainsString($this->attachment->path, $res->getContent());
        $this->assertStringNotContainsString('/storage/', $res->getContent());
    }

    public function test_thumbnail_is_authorized_but_not_audited(): void
    {
        $res = $this->get(route('admin.attachments.thumb', $this->attachment));
        $res->assertOk();
        $this->assertSame($this->original, $res->getContent());
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertFalse(AuditLog::whereIn('action', ['attachment.viewed', 'attachment.downloaded'])->exists());

        $this->actingAsClubAdmin(Club::factory()->create());
        $this->get(route('admin.attachments.thumb', $this->attachment))->assertForbidden();
        $this->actingAsMember($this->club);
        $this->get(route('admin.attachments.thumb', $this->attachment))->assertForbidden();
    }

    public function test_thumbnail_is_images_only_and_page_uses_it_for_tiles(): void
    {
        $this->withoutVite();
        $expense = Expense::firstOrFail();
        $pdf = $expense->attachments()->create([
            'club_id' => $this->club->id, 'kind' => 'invoice', 'original_name' => 'x.pdf', 'path' => 'clubs/x.bin',
            'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $this->admin->id,
        ]);
        $this->get(route('admin.attachments.thumb', $pdf))->assertNotFound();

        $this->get(route('admin.fund-usage.show', $expense))->assertInertia(fn ($page) => $page
            ->where('item.attachments.0.thumbUrl', route('admin.attachments.thumb', $this->attachment))
            ->where('item.attachments.1.thumbUrl', null));
        $this->assertFalse(AuditLog::whereIn('action', ['attachment.viewed', 'attachment.downloaded'])->exists(), 'rendering the page audits nothing');
    }
}
