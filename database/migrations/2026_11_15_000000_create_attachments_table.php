<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private, polymorphic file attachments (invoices, receipts, bank slips).
     * Files live on the private `local` disk, encrypted at rest; see
     * App\Services\AttachmentService.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30)->default('other');   // invoice | receipt | bank_slip | acknowledgement | other
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');             // bytes of the original (plaintext) file
            $table->char('sha256', 64);                     // hash of the original (plaintext) file
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('frozen_at')->nullable();     // set on submit; no add/delete afterwards
            $table->timestamps();

            $table->index('club_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
