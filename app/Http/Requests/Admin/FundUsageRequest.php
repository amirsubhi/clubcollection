<?php

namespace App\Http\Requests\Admin;

use App\Models\Attachment;
use App\Models\Club;
use App\Models\Expense;
use App\Models\WelfareCase;
use App\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Create / update a Fund Usage record. authorize() repeats the club-admin
 * check so a cross-club request is refused before validation. Category, beneficiary and "sighted by" must belong to the club.
 */
class FundUsageRequest extends FormRequest
{
    /** Same check as authorizeClubAdmin(), run before validation so other clubs get a 403. */
    public function authorize(): bool
    {
        return app(\App\Services\FundUsageService::class)->isClubAdmin($this->club(), $this->user());
    }

    public function club(): Club
    {
        $club = $this->route('club');
        if ($club instanceof Club) {
            return $club;
        }
        /** @var Expense $expense */
        $expense = $this->route('expense');

        return $expense->club;
    }

    protected function prepareForValidation(): void
    {
        // Accept "1,234.50" from the amount box.
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([',', ' '], '', $this->input('amount'))]);
        }
        foreach (['payee', 'invoice_no', 'paid_date', 'minute_ref'] as $k) {
            if ($this->input($k) === '') {
                $this->merge([$k => null]);
            }
        }
    }

    public function rules(): array
    {
        $clubId = $this->club()->id;
        $welfare = $this->input('type') === 'welfare';
        $memberIds = fn () => Rule::exists('club_user', 'user_id')->where('club_id', $clubId);

        return [
            'intent' => ['required', Rule::in(['draft', 'submit'])],
            'type' => ['required', Rule::in(Expense::TYPES)],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('club_id', $clubId)],
            'description' => ['required', 'string', 'max:500'],
            'payee' => [Rule::requiredIf(! $welfare), 'nullable', 'string', 'max:255'],
            'invoice_no' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'paid_date' => ['nullable', 'date_format:Y-m-d'],
            'minute_ref' => ['nullable', 'string', 'max:255'],

            'files' => ['nullable', 'array', 'max:'.Attachment::MAX_FILES],
            'files.*' => AttachmentService::fileRules(),
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['nullable', Rule::in(Attachment::FUND_KINDS)],
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => ['integer'],

            'welfare' => [Rule::requiredIf($welfare), 'nullable', 'array'],
            'welfare.beneficiary_user_id' => [Rule::requiredIf($welfare), 'nullable', 'integer', $memberIds()],
            'welfare.subject_name' => [Rule::requiredIf($welfare), 'nullable', 'string', 'max:255'],
            'welfare.relationship' => [Rule::requiredIf($welfare), 'nullable', 'string', Rule::in(WelfareCase::RELATIONSHIPS)],
            'welfare.recipient_name' => [Rule::requiredIf($welfare), 'nullable', 'string', 'max:255'],
            // A second pair of eyes: an active admin of this club other than the maker.
            'welfare.certificate_sighted_by' => ['nullable', 'integer', Rule::exists('club_user', 'user_id')->where('club_id', $clubId)->where('role', 'admin')->where('is_active', true), Rule::notIn([$this->makerId()]), 'required_with:welfare.certificate_sighted_at'],
            'welfare.certificate_sighted_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'required_with:welfare.certificate_sighted_by'],
        ];
    }

    public function messages(): array
    {
        // One wording style (what to do, in plain words), shown in the error summary and under the field.
        $welfare = $this->input('type') === 'welfare';

        return [
            'type.required' => 'Choose a record type.',
            'type.in' => 'Choose a record type.',
            'description.required' => 'Enter a description.',
            'description.max' => 'Keep the description to 500 characters or fewer.',
            'payee.required' => 'Enter who was paid.',
            'amount.required' => 'Enter the amount.',
            'amount.numeric' => 'Enter the amount as a number, e.g. 120.50.',
            'amount.min' => 'Enter an amount more than RM 0.00.',
            'amount.max' => 'Enter an amount under RM 10,000,000.',
            'amount.regex' => 'Enter the amount with at most 2 decimal places.',
            'expense_date.required' => $welfare ? 'Enter the date the committee approved it.' : 'Enter the invoice date.',
            'expense_date.date_format' => $welfare ? 'Enter the date the committee approved it.' : 'Enter the invoice date.',
            'paid_date.date_format' => 'Enter the date paid as a full date.',
            'expense_category_id.required' => 'Choose a category.',
            'welfare.beneficiary_user_id.required' => 'Choose the beneficiary member.',
            'welfare.subject_name.required' => 'Enter the name of the deceased or affected person.',
            'welfare.relationship.required' => 'Choose the relationship to the member.',
            'welfare.relationship.in' => 'Choose the relationship to the member.',
            'welfare.recipient_name.required' => 'Enter who received the payout.',
            'welfare.certificate_sighted_by.required_with' => 'Choose who sighted the certificate.',
            'welfare.certificate_sighted_at.required_with' => 'Enter the date the certificate was sighted.',
            'welfare.certificate_sighted_at.before_or_equal' => 'The date the certificate was sighted cannot be in the future.',
            'expense_category_id.exists' => 'The selected category does not belong to this club.',
            'files.max' => 'You can attach at most '.Attachment::MAX_FILES.' documents.',
            'files.*.mimes' => 'Documents must be PDF, JPG, PNG or WebP.',
            'files.*.max' => 'Each document must be 10 MB or smaller.',
            'welfare.beneficiary_user_id.exists' => 'The beneficiary must be a member of this club.',
            'welfare.certificate_sighted_by.exists' => 'The person who sighted the certificate must be an active admin of this club.',
            'welfare.certificate_sighted_by.not_in' => 'The certificate must be sighted by a different admin, not the one recording this case.',
        ];
    }

    /** The maker: the recorder when editing, otherwise the current user. */
    private function makerId(): int
    {
        $expense = $this->route('expense');

        return (int) ($expense instanceof Expense ? $expense->recorded_by : $this->user()->id);
    }

    /** Final document count (existing − removed + new) must fit, checked before anything is written. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $expense = $this->route('expense');
            $existing = 0;
            if ($expense instanceof Expense) {
                $remove = array_map('intval', (array) $this->input('remove_attachment_ids', []));
                $existing = $expense->attachments()->whereNotIn('id', $remove)->count();
            }
            $new = count(array_filter((array) $this->file('files', []), fn ($f) => $f instanceof UploadedFile));
            if ($existing + $new > Attachment::MAX_FILES) {
                $v->errors()->add('files', 'A record can have at most '.Attachment::MAX_FILES.' documents.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function expenseData(): array
    {
        return $this->safe()->only([
            'type', 'expense_category_id', 'description', 'payee', 'invoice_no',
            'amount', 'expense_date', 'paid_date', 'minute_ref',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function welfareData(): ?array
    {
        return $this->input('type') === 'welfare' ? ($this->validated('welfare') ?? []) : null;
    }

    /** @return array<int, array{file: UploadedFile, kind: string}> */
    public function uploads(): array
    {
        $kinds = $this->validated('kinds') ?? [];
        $out = [];
        foreach ($this->file('files', []) as $i => $file) {
            if ($file instanceof UploadedFile) {
                $out[] = ['file' => $file, 'kind' => $kinds[$i] ?? 'other'];
            }
        }

        return $out;
    }

    /** @return array<int, int> */
    public function removeIds(): array
    {
        return array_map('intval', $this->validated('remove_attachment_ids') ?? []);
    }

    public function wantsSubmit(): bool
    {
        return $this->validated('intent') === 'submit';
    }

    public function certificateSighted(): bool
    {
        $w = $this->welfareData();

        return $w !== null && ! empty($w['certificate_sighted_by']) && ! empty($w['certificate_sighted_at']);
    }
}
