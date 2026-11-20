<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Support\Images\Thumbnailer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves private attachments to admins of the owning club only. Members
 * never get a route to these files. show() is audited (attachment.viewed
 * for an opened preview, attachment.downloaded otherwise); thumb() serves
 * images only, as a downscaled re-encoded copy (gallery tiles, and the large
 * inline slip preview on Verifications with ?size=large; EXIF dropped, 404
 * when it can't be decoded), authorized but not audited, so merely rendering a page
 * does not flood the audit log. Approve / reject log the slip ids and
 * SHA-256 instead. Like show(), it is never cached.
 */
class AttachmentController extends Controller
{
    use AuthorizesClubResource;

    /** Types a browser may render inline; anything else is always a download. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function thumb(Request $request, Attachment $attachment, AttachmentService $service): Response
    {
        $this->authorizeClubAdmin($attachment->club);
        abort_unless(in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        // A downscaled, re-encoded copy (EXIF dropped), never the original:
        // 480 px for gallery tiles, ?size=large (1600 px) for the slip preview.
        $maxSide = $request->query('size') === 'large' ? Thumbnailer::LARGE : Thumbnailer::TILE;
        $thumb = Thumbnailer::make($service->contents($attachment), $attachment->mime, $maxSide);
        abort_if($thumb === null, 404);
        [$contents, $mime] = $thumb;

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function show(Request $request, Attachment $attachment, AttachmentService $service): Response
    {
        $this->authorizeClubAdmin($attachment->club);

        $inline = $request->boolean('inline') && in_array($attachment->mime, self::INLINE, true);
        $contents = $service->contents($attachment);

        AuditService::log(
            $inline ? 'attachment.viewed' : 'attachment.downloaded',
            ($inline ? 'Viewed' : 'Downloaded')." document \"{$attachment->original_name}\".",
            $attachment,
            $attachment->club_id,
            [],
            ['name' => $attachment->original_name, 'attachable_id' => $attachment->attachable_id, 'sha256' => $attachment->sha256]
        );

        $disposition = HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->original_name,
            'document'.(pathinfo($attachment->original_name, PATHINFO_EXTENSION) ? '.'.preg_replace('/[^A-Za-z0-9]/', '', pathinfo($attachment->original_name, PATHINFO_EXTENSION)) : '')
        );

        return response($contents, 200, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // A PDF opened inline must not run scripts or be framed elsewhere.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
