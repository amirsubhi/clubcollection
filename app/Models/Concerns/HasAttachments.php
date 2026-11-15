<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model private, polymorphic attachments.
 *
 * Models may override attachmentsLocked() to say when files can no longer be
 * added or removed (e.g. once an expense is submitted).
 */
trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        // Files are removed by AttachmentService; refuse to orphan rows here.
        static::deleting(function ($model) {
            if ($model->attachments()->whereNotNull('frozen_at')->exists()) {
                throw new \LogicException('Cannot delete a record with frozen attachments.');
            }
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    public function attachmentsLocked(): bool
    {
        return false;
    }
}
