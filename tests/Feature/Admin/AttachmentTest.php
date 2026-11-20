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
        // G2: a re-encoded copy, never the original bytes.
        $this->assertNotSame($this->original, $res->getContent());
        $this->assertSame([40, 40], array_slice(getimagesizefromstring($res->getContent()), 0, 2));
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        // Unaudited, so never cached either (same as the audited view).
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
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

    // ── G2: real thumbnails ─────────────────────────────────────────────────

    /** A JPEG with an EXIF block (camera make + a marker) spliced in after SOI. */
    private static function jpegWithExif(int $w, int $h, string $marker = 'SECRETCAM', ?int $orientation = null): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 50) {
            imagefilledrectangle($img, $x, 0, $x + 49, $h, imagecolorallocate($img, ($x * 7) % 256, ($x * 3) % 256, 120));
        }
        ob_start();
        imagejpeg($img, null, 92);
        $jpeg = (string) ob_get_clean();

        // A little-endian TIFF block: Make (ASCII, stored after the IFD) and optionally Orientation (SHORT, inline).
        $value = $marker."\0";
        $count = $orientation === null ? 1 : 2;
        $dataOffset = 8 + 2 + 12 * $count + 4;
        $tiff = "II*\0".pack('V', 8).pack('v', $count)
            .pack('vvVV', 0x010F, 2, strlen($value), $dataOffset)
            .($orientation === null ? '' : pack('vvVvv', 0x0112, 3, 1, $orientation, 0))
            .pack('V', 0).$value;
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function storeImage(string $bytes, string $name = 'slip.jpg'): Attachment
    {
        $expense = Expense::firstOrFail();
        $file = UploadedFile::fake()->createWithContent($name, $bytes);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), [
            'intent' => 'draft', 'type' => 'expense', 'expense_category_id' => $expense->expense_category_id,
            'description' => 'Stationery', 'payee' => 'Popular Bookstore', 'amount' => '12.30',
            'expense_date' => now()->toDateString(), 'files' => [$file], 'kinds' => ['receipt'],
        ])->assertSessionHasNoErrors();

        return Attachment::where('original_name', $name)->firstOrFail();
    }

    public function test_thumbnail_is_downscaled_and_has_no_exif(): void
    {
        $original = self::jpegWithExif(2000, 1500);
        $this->assertSame('SECRETCAM', @exif_read_data('data://image/jpeg;base64,'.base64_encode($original))['Make'] ?? null, 'fixture carries EXIF');
        $attachment = $this->storeImage($original);

        $res = $this->get(route('admin.attachments.thumb', $attachment))->assertOk();
        $thumb = $res->getContent();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertSame([480, 360], array_slice(getimagesizefromstring($thumb), 0, 2));
        $this->assertLessThan(strlen($original), strlen($thumb));
        $this->assertStringNotContainsString('SECRETCAM', $thumb);
        $this->assertStringNotContainsString("Exif\0\0", $thumb);
        $this->assertSame((string) strlen($thumb), $res->headers->get('Content-Length'));

        // The slip preview's larger copy: still re-encoded, capped at 1600 px.
        $large = $this->get(route('admin.attachments.thumb', $attachment).'?size=large')->assertOk()->getContent();
        $this->assertSame([1600, 1200], array_slice(getimagesizefromstring($large), 0, 2));
        $this->assertStringNotContainsString('SECRETCAM', $large);
        $this->assertFalse(AuditLog::whereIn('action', ['attachment.viewed', 'attachment.downloaded'])->exists());
    }

    public function test_thumbnail_applies_exif_orientation_to_the_copy(): void
    {
        // Stored landscape, tagged "rotate 90° clockwise" (6), as a phone shoots a portrait photo.
        $original = self::jpegWithExif(600, 300, 'SECRETCAM', 6);
        $this->assertSame(6, @exif_read_data('data://image/jpeg;base64,'.base64_encode($original))['Orientation'] ?? null, 'fixture is tagged');
        $attachment = $this->storeImage($original, 'portrait.jpg');

        $thumb = $this->get(route('admin.attachments.thumb', $attachment))->assertOk()->getContent();
        $this->assertSame([240, 480], array_slice(getimagesizefromstring($thumb), 0, 2), 'portrait after applying the tag');
        $this->assertStringNotContainsString('SECRETCAM', $thumb);

        // Small enough already: the large copy keeps the size but is still re-encoded and upright.
        $large = $this->get(route('admin.attachments.thumb', $attachment).'?size=large')->assertOk()->getContent();
        $this->assertSame([300, 600], array_slice(getimagesizefromstring($large), 0, 2));
        $this->assertNotSame($original, $large);
        $this->assertStringNotContainsString('SECRETCAM', $large);
    }

    public function test_thumbnail_keeps_png_as_png(): void
    {
        $img = imagecreatetruecolor(900, 300);
        ob_start();
        imagepng($img);
        $attachment = $this->storeImage((string) ob_get_clean(), 'slip.png');

        $res = $this->get(route('admin.attachments.thumb', $attachment))->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame([480, 160], array_slice(getimagesizefromstring($res->getContent()), 0, 2));
    }

    public function test_thumbnail_that_cannot_be_decoded_is_404_never_the_original(): void
    {
        $expense = Expense::firstOrFail();
        Storage::disk('local')->put('clubs/broken.bin', Crypt::encryptString('not really a jpeg'));
        $broken = $expense->attachments()->create([
            'club_id' => $this->club->id, 'kind' => 'receipt', 'original_name' => 'broken.jpg', 'path' => 'clubs/broken.bin',
            'mime' => 'image/jpeg', 'size' => 17, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $this->admin->id,
        ]);

        $res = $this->get(route('admin.attachments.thumb', $broken))->assertNotFound();
        $this->assertStringNotContainsString('not really a jpeg', $res->getContent());
    }

    public function test_thumbnail_refuses_images_over_25_megapixels(): void
    {
        // Only the header is read for the size check: a 6000 x 5000 PNG header on a tiny file.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
        $png .= pack('N', crc32(substr($png, 12, 17)));
        $this->assertSame([6000, 5000], array_slice(getimagesizefromstring($png), 0, 2));
        $this->assertNull(\App\Support\Images\Thumbnailer::make($png, 'image/png'));
    }
}
