<?php

namespace Tests\Feature\Admin;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\WelfareCase;
use App\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Maker-checker rules of FundUsageService, exercised through the HTTP routes.
 */
class FundUsageWorkflowTest extends TestCase
{
    private Club $club;

    private User $maker;

    private User $checker;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->club = Club::factory()->create();
        $this->maker = $this->admin($this->club, 'Maker Mazlan');
        $this->checker = $this->admin($this->club, 'Checker Chong');
        $this->category = ExpenseCategory::factory()->for($this->club)->create(['name' => 'Meetings']);
    }

    private function admin(Club $club, string $name = 'Admin'): User
    {
        $u = User::factory()->create(['role' => 'admin', 'name' => $name]);
        $club->members()->attach($u, ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $u;
    }

    private function member(Club $club): User
    {
        $u = User::factory()->create(['role' => 'member']);
        $club->members()->attach($u, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $u;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'intent' => 'draft',
            'type' => 'expense',
            'expense_category_id' => $this->category->id,
            'description' => 'Refreshments for committee meeting',
            'payee' => 'Restoran Pelita',
            'invoice_no' => 'NKP-1',
            'amount' => '186.50',
            'expense_date' => now()->toDateString(),
            'paid_date' => now()->toDateString(),
            'minute_ref' => 'MJK 10/2026',
        ], $over);
    }

    private function pdf(string $name = 'invoice.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** A draft recorded by $this->maker, optionally with one document. */
    private function draft(bool $withDoc = true, array $over = []): Expense
    {
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload($over) + ($withDoc ? ['files' => [$this->pdf()], 'kinds' => ['invoice']] : []))
            ->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    private function submitted(): Expense
    {
        $e = $this->draft();
        $this->actingAs($this->maker)->post(route('admin.fund-usage.submit', $e))->assertSessionHasNoErrors();

        return $e->fresh();
    }

    // ── Create / submit ─────────────────────────────────────────────────

    public function test_store_creates_a_draft_recorded_by_the_admin(): void
    {
        $e = $this->draft();

        $this->assertSame('draft', $e->status);
        $this->assertSame('expense', $e->type);
        $this->assertSame($this->maker->id, (int) $e->recorded_by);
        $this->assertSame('186.50', $e->amount);
        $this->assertCount(1, $e->attachments);
        $this->assertTrue(AuditLog::where('action', 'fund_usage.created')->where('auditable_id', $e->id)->exists());
    }

    public function test_store_with_submit_intent_submits_and_freezes(): void
    {
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['intent' => 'submit', 'files' => [$this->pdf()], 'kinds' => ['receipt']]))
            ->assertSessionHasNoErrors();

        $e = Expense::latest('id')->first();
        $this->assertSame('submitted', $e->status);
        $this->assertNotNull($e->submitted_at);
        $this->assertNotNull($e->attachments->first()->frozen_at);
    }

    public function test_submit_without_attachment_is_rejected(): void
    {
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['intent' => 'submit']))
            ->assertSessionHasErrors('files');
        $this->assertSame(0, Expense::count());

        $e = $this->draft(withDoc: false);
        $this->post(route('admin.fund-usage.submit', $e))->assertSessionHasErrors('files');
        $this->assertSame('draft', $e->fresh()->status);
    }

    public function test_store_validates_amount_payee_and_category(): void
    {
        $other = ExpenseCategory::factory()->for(Club::factory()->create())->create();
        $this->actingAs($this->maker);

        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['amount' => '0', 'payee' => '', 'expense_category_id' => $other->id, 'description' => '']))
            ->assertSessionHasErrors(['amount', 'payee', 'expense_category_id', 'description']);
        $this->assertSame(0, Expense::count());
    }

    public function test_store_rejects_disallowed_file_types_and_too_many_files(): void
    {
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['files' => [UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')]]))
            ->assertSessionHasErrors('files.0');
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['files' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]]))
            ->assertSessionHasErrors('files.0');
        $files = array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, 11));
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['files' => $files]))
            ->assertSessionHasErrors('files');
        $this->assertSame(0, Expense::count());
    }

    public function test_welfare_can_submit_with_certificate_sighted_and_no_document(): void
    {
        $beneficiary = $this->member($this->club);
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload([
            'intent' => 'submit',
            'type' => 'welfare',
            'payee' => '',
            'welfare' => [
                'beneficiary_user_id' => $beneficiary->id,
                'subject_name' => 'Tan Ah Kow',
                'relationship' => 'Father',
                'recipient_name' => 'Tan Wei Ming',
                'certificate_sighted_by' => $this->checker->id,
                'certificate_sighted_at' => now()->toDateString(),
            ],
        ]))->assertSessionHasNoErrors();

        $e = Expense::latest('id')->first();
        $this->assertSame('submitted', $e->status);
        $this->assertNull($e->payee);
        $this->assertSame('Tan Wei Ming', $e->welfareCase->recipient_name);
        $this->assertSame(now()->addYears(7)->toDateString(), $e->welfareCase->retention_until->toDateString());

        // Encrypted at rest, and never copied into the audit log.
        $raw = \DB::table('welfare_cases')->where('id', $e->welfareCase->id)->value('recipient_name');
        $this->assertStringNotContainsString('Tan Wei Ming', $raw);
        $this->assertFalse(AuditLog::where('new_values', 'like', '%Tan Wei Ming%')->orWhere('description', 'like', '%Tan Wei Ming%')->exists());
    }

    public function test_welfare_without_document_or_sighting_cannot_submit(): void
    {
        $beneficiary = $this->member($this->club);
        $this->actingAs($this->maker);
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload([
            'intent' => 'submit', 'type' => 'welfare', 'payee' => '',
            'welfare' => ['beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'A', 'relationship' => 'Self', 'recipient_name' => 'B'],
        ]))->assertSessionHasErrors('files');
    }

    // ── Approve ─────────────────────────────────────────────────────────

    public function test_checker_approves_with_declaration(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])
            ->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('approved', $e->status);
        $this->assertSame($this->checker->id, (int) $e->approved_by);
        $log = AuditLog::where('action', 'fund_usage.approved')->where('auditable_id', $e->id)->first();
        $this->assertSame('submitted', $log->old_values['status']);
        $this->assertSame('approved', $log->new_values['status']);
        $this->assertTrue($log->new_values['conflict_of_interest_declared']);
    }

    public function test_approve_without_declaration_is_rejected(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), [])
            ->assertSessionHasErrors('declaration');
        $this->assertSame('submitted', $e->fresh()->status);
    }

    public function test_recorder_cannot_approve(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->maker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])
            ->assertForbidden();
        $this->assertSame('submitted', $e->fresh()->status);
    }

    public function test_beneficiary_cannot_approve_welfare(): void
    {
        // The beneficiary is also a club admin.
        $beneficiary = $this->admin($this->club, 'Beneficiary Admin');
        $e = $this->draft(over: [
            'type' => 'welfare', 'payee' => '',
            'welfare' => ['beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'X', 'relationship' => 'Mother', 'recipient_name' => 'Y'],
        ]);
        $this->actingAs($this->maker)->post(route('admin.fund-usage.submit', $e))->assertSessionHasNoErrors();

        $this->actingAs($beneficiary)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])
            ->assertForbidden();
        $this->assertSame('submitted', $e->fresh()->status);

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true]);
        $this->assertSame('approved', $e->fresh()->status);
    }

    public function test_draft_cannot_be_approved(): void
    {
        $e = $this->draft();

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $e->fresh()->status);
    }

    public function test_super_admin_may_approve_in_single_admin_club_but_not_own_record(): void
    {
        $solo = Club::factory()->create();
        $only = $this->admin($solo, 'Only Admin');
        $cat = ExpenseCategory::factory()->for($solo)->create();
        $this->actingAs($only)->post(route('admin.clubs.fund-usage.store', $solo), $this->payload(['intent' => 'submit', 'expense_category_id' => $cat->id, 'files' => [$this->pdf()]]))
            ->assertSessionHasNoErrors();
        $e = Expense::latest('id')->first();

        $this->get(route('admin.fund-usage.show', $e))
            ->assertInertia(fn ($page) => $page->where('needsSecondApprover', true)->where('eligibleApprovers', 0)->where('can.approve', false));
        $this->assertSame('submitted', $e->fresh()->status, 'never auto-approved');

        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $e->fresh()->status);

        // A super admin who recorded the item still cannot approve it.
        $this->actingAs($super)->post(route('admin.clubs.fund-usage.store', $solo), $this->payload(['intent' => 'submit', 'expense_category_id' => $cat->id, 'files' => [$this->pdf()]]));
        $own = Expense::latest('id')->first();
        $this->post(route('admin.fund-usage.approve', $own), ['declaration' => true])->assertForbidden();
    }

    // ── Return / edit ───────────────────────────────────────────────────

    public function test_return_requires_reason_and_goes_back_to_draft(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->checker);

        $this->post(route('admin.fund-usage.return', $e), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.fund-usage.return', $e), ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->assertSame('submitted', $e->fresh()->status);

        $this->post(route('admin.fund-usage.return', $e), ['reason' => 'Receipt is blurry, please re-upload.'])->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('draft', $e->status);
        $this->assertSame('Receipt is blurry, please re-upload.', $e->return_reason);
        $this->assertNull($e->attachments->first()->frozen_at, 'documents unlocked for the fix');
        $this->assertTrue(AuditLog::where('action', 'fund_usage.returned')->where('auditable_id', $e->id)->exists());
    }

    public function test_recorder_cannot_return_own_record(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->maker)->post(route('admin.fund-usage.return', $e), ['reason' => 'Withdrawing it'])->assertForbidden();
    }

    public function test_edit_after_submit_goes_back_to_draft(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload(['amount' => '168.50']))
            ->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('draft', $e->status);
        $this->assertSame('168.50', $e->amount);
        $this->assertNull($e->submitted_at);
        $log = AuditLog::where('action', 'fund_usage.updated')->where('auditable_id', $e->id)->first();
        $this->assertSame('submitted', $log->old_values['status']);
        $this->assertSame('draft', $log->new_values['status']);
    }

    public function test_unchanged_update_keeps_submitted_status(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame('submitted', $e->fresh()->status);
    }

    public function test_only_recorder_can_edit(): void
    {
        $e = $this->draft();
        $this->actingAs($this->checker)->put(route('admin.fund-usage.update', $e), $this->payload(['amount' => '1.00']))->assertForbidden();
        $this->get(route('admin.fund-usage.edit', $e))->assertForbidden();
        $this->assertSame('186.50', $e->fresh()->amount);
    }

    public function test_approved_record_cannot_be_edited_or_deleted(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true]);

        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload(['amount' => '1.00']))->assertSessionHasErrors('status');
        $this->delete(route('admin.fund-usage.destroy', $e))->assertSessionHasErrors('status');
        $this->assertSame('186.50', $e->fresh()->amount);
        $this->assertModelExists($e);
    }

    public function test_draft_can_be_deleted_with_its_files(): void
    {
        $e = $this->draft();
        $path = $e->attachments->first()->path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->maker)->delete(route('admin.fund-usage.destroy', $e))
            ->assertRedirect(route('admin.clubs.fund-usage.index', $this->club));

        $this->assertModelMissing($e);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, Attachment::count());
    }

    // ── Frozen attachments ──────────────────────────────────────────────

    public function test_frozen_attachments_cannot_be_added_or_removed(): void
    {
        $e = $this->submitted();
        $a = $e->attachments->first();
        $service = app(AttachmentService::class);
        $this->actingAs($this->maker);

        try {
            $service->store($e, $this->club->id, $this->pdf('late.pdf'), 'receipt');
            $this->fail('Adding to a submitted record should be refused.');
        } catch (ValidationException) {
        }
        try {
            $service->delete($a);
            $this->fail('Removing a frozen document should be refused.');
        } catch (ValidationException) {
        }

        // Approved: the edit route refuses file changes too.
        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true]);
        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload(['remove_attachment_ids' => [$a->id], 'files' => [$this->pdf('x.pdf')]]))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, $e->attachments()->count());
        Storage::disk('local')->assertExists($a->path);
    }

    public function test_adding_a_file_to_a_submitted_record_returns_it_to_draft(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload(['files' => [$this->pdf('second.pdf')], 'kinds' => ['receipt']]))
            ->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('draft', $e->status);
        $this->assertSame(2, $e->attachments()->count());
    }

    // ── Void ────────────────────────────────────────────────────────────

    public function test_void_needs_a_second_admin(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true]);

        $this->actingAs($this->checker)->post(route('admin.fund-usage.void-request', $e), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.fund-usage.void-request', $e), ['reason' => 'Duplicate of FU-0001'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $e->fresh()->status, 'still counted until confirmed');

        // Same admin cannot confirm.
        $this->post(route('admin.fund-usage.void-confirm', $e))->assertForbidden();
        $this->assertSame('approved', $e->fresh()->status);

        $this->actingAs($this->maker)->post(route('admin.fund-usage.void-confirm', $e))->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('void', $e->status);
        $this->assertSame($this->checker->id, (int) $e->voided_by);
        $this->assertSame($this->maker->id, (int) $e->void_approved_by);
        $this->assertNotNull($e->voided_at);
        $this->assertTrue(AuditLog::where('action', 'fund_usage.voided')->where('auditable_id', $e->id)->exists());
    }

    public function test_only_approved_records_can_be_voided(): void
    {
        $e = $this->submitted();
        $this->actingAs($this->checker)->post(route('admin.fund-usage.void-request', $e), ['reason' => 'Not needed now'])->assertSessionHasErrors('status');
        $this->post(route('admin.fund-usage.void-confirm', $e))->assertSessionHasErrors('status');
    }

    // ── Review follow-ups ───────────────────────────────────────────────

    public function test_approve_failure_redirects_to_the_record_page(): void
    {
        $e = $this->submitted();

        $this->actingAs($this->checker)->from('/somewhere-else')->post(route('admin.fund-usage.approve', $e), [])
            ->assertRedirect(route('admin.fund-usage.show', $e))
            ->assertSessionHasErrors('declaration');
    }

    public function test_file_limit_counts_existing_minus_removed_plus_new_before_touching_anything(): void
    {
        $this->actingAs($this->maker);
        $files = array_map(fn ($i) => ['file' => $this->pdf("f{$i}.pdf"), 'kind' => 'invoice'], range(1, 10));
        $e = app(\App\Services\FundUsageService::class)->create($this->club, $this->maker, $this->payload(), $files);
        $this->assertSame(10, $e->attachments()->count());
        $before = Storage::disk('local')->allFiles();
        $removeId = $e->attachments()->first()->id;

        $this->put(route('admin.fund-usage.update', $e), $this->payload(['remove_attachment_ids' => [$removeId], 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]]))
            ->assertSessionHasErrors('files');

        $this->assertSame(10, $e->attachments()->count());
        $this->assertModelExists(Attachment::find($removeId));
        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles(), 'nothing deleted, nothing orphaned');

        // Remove 1 + add 1 fits.
        $this->put(route('admin.fund-usage.update', $e), $this->payload(['remove_attachment_ids' => [$removeId], 'files' => [$this->pdf('a.pdf')]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(10, $e->attachments()->count());
        $this->assertCount(10, Storage::disk('local')->allFiles());
    }

    public function test_a_failing_file_in_create_leaves_no_draft_and_no_files(): void
    {
        $this->actingAs($this->maker);
        $broken = new class(tempnam(sys_get_temp_dir(), 'att'), 'broken.pdf', 'application/pdf', null, true) extends UploadedFile
        {
            public function get(): string|false
            {
                throw new \RuntimeException('disk read failed');
            }
        };
        $files = [['file' => $this->pdf('ok1.pdf'), 'kind' => 'invoice'], ['file' => $this->pdf('ok2.pdf'), 'kind' => 'receipt'], ['file' => $broken, 'kind' => 'other']];

        try {
            app(\App\Services\FundUsageService::class)->create($this->club, $this->maker, $this->payload(), $files);
            $this->fail('Expected the third file to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk read failed', $e->getMessage());
        }

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_beneficiary_cannot_return_welfare(): void
    {
        $beneficiary = $this->admin($this->club, 'Beneficiary Admin');
        $e = $this->draft(over: [
            'type' => 'welfare', 'payee' => '',
            'welfare' => ['beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'X', 'relationship' => 'Mother', 'recipient_name' => 'Y'],
        ]);
        $this->actingAs($this->maker)->post(route('admin.fund-usage.submit', $e));

        $this->actingAs($beneficiary)->get(route('admin.fund-usage.show', $e))
            ->assertInertia(fn ($page) => $page->where('can.return', false)->where('can.approve', false));
        $this->post(route('admin.fund-usage.return', $e), ['reason' => 'Please change the amount'])->assertForbidden();
        $this->assertSame('submitted', $e->fresh()->status);
    }

    public function test_certificate_must_be_sighted_by_a_different_active_admin(): void
    {
        $beneficiary = $this->member($this->club);
        $inactive = $this->admin($this->club, 'Former Admin');
        $this->club->members()->updateExistingPivot($inactive->id, ['is_active' => false]);
        $welfare = fn ($by) => [
            'intent' => 'submit', 'type' => 'welfare', 'payee' => '',
            'welfare' => ['beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'A', 'relationship' => 'Father', 'recipient_name' => 'B',
                'certificate_sighted_by' => $by, 'certificate_sighted_at' => now()->toDateString()],
        ];
        $this->actingAs($this->maker);

        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload($welfare($this->maker->id)))
            ->assertSessionHasErrors('welfare.certificate_sighted_by');
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload($welfare($inactive->id)))
            ->assertSessionHasErrors('welfare.certificate_sighted_by');
        $this->assertSame(0, Expense::count());

        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload($welfare($this->checker->id)))
            ->assertSessionHasNoErrors();
    }

    public function test_inactive_admin_gets_403_on_approve(): void
    {
        $e = $this->submitted();
        $this->club->members()->updateExistingPivot($this->checker->id, ['is_active' => false]);

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])->assertForbidden();
        $this->get(route('admin.fund-usage.show', $e))->assertForbidden();
        $this->assertSame('submitted', $e->fresh()->status);
    }

    public function test_admin_of_inactive_club_gets_403(): void
    {
        $e = $this->submitted();
        $this->club->update(['is_active' => false]);

        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true])->assertForbidden();
        $this->get(route('admin.clubs.fund-usage.index', $this->club))->assertForbidden();
    }

    public function test_audit_log_never_contains_welfare_personal_fields(): void
    {
        $beneficiary = $this->member($this->club);
        $w = ['beneficiary_user_id' => $beneficiary->id, 'subject_name' => 'PII-SUBJECT-Zainab', 'relationship' => 'Mother-in-law', 'recipient_name' => 'PII-RECIPIENT-Hafiz'];
        $e = $this->draft(over: ['type' => 'welfare', 'payee' => '', 'welfare' => $w]);
        $this->actingAs($this->maker)->put(route('admin.fund-usage.update', $e), $this->payload(['type' => 'welfare', 'payee' => '', 'welfare' => ['recipient_name' => 'PII-RECIPIENT-Hafiz2', 'subject_name' => 'PII-SUBJECT-Zainab2'] + $w]))
            ->assertSessionHasNoErrors();
        $this->post(route('admin.fund-usage.submit', $e));
        $this->actingAs($this->checker)->post(route('admin.fund-usage.return', $e), ['reason' => 'Check the slip please']);
        $this->actingAs($this->maker)->post(route('admin.fund-usage.submit', $e));
        $this->actingAs($this->checker)->post(route('admin.fund-usage.approve', $e), ['declaration' => true]);
        $this->post(route('admin.fund-usage.void-request', $e), ['reason' => 'Paid twice by mistake']);
        $this->actingAs($this->maker)->post(route('admin.fund-usage.void-confirm', $e));
        $this->assertSame('void', $e->fresh()->status);

        $dump = AuditLog::all()->map(fn ($l) => json_encode($l->getAttributes()))->implode("\n");
        $this->assertGreaterThan(5, AuditLog::count());
        foreach (['PII-SUBJECT', 'PII-RECIPIENT', 'Mother-in-law', 'Zainab', 'Hafiz'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
    }

    public function test_svg_and_html_disguised_as_pdf_are_rejected(): void
    {
        $this->actingAs($this->maker);
        $real = function (string $name, string $content, string $clientMime) {
            $path = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($path, $content);

            return new UploadedFile($path, $name, $clientMime, null, true);
        };

        $svg = $real('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['files' => [$svg]]))->assertSessionHasErrors('files.0');

        $html = $real('invoice.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'application/pdf');
        $this->post(route('admin.clubs.fund-usage.store', $this->club), $this->payload(['files' => [$html]]))->assertSessionHasErrors('files.0');

        $this->assertSame(0, Expense::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
