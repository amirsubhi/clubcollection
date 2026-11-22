<?php

namespace App\Models;

use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'logo', 'email', 'whatsapp_number', 'is_active',
        'registration_no', 'address', 'financial_year_start_month',
        'payment_gateway',
        'toyyibpay_secret_key', 'toyyibpay_category_code',
        'billplz_api_key', 'billplz_collection_id', 'billplz_x_signature_key',
        'allow_monthly', 'allow_quarterly', 'allow_yearly', 'default_plan',
        'gateway_fee_payer', 'gateway_fee_sen', 'overdue_grace_days',
        'transfer_enabled', 'bank_name', 'bank_account_name', 'bank_account_no', 'duitnow_id',
    ];

    /** Billing plans and how many months each covers. */
    public const PLAN_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const FEE_PAYERS = ['club', 'member'];

    protected function casts(): array
    {
        return [
            // Only secret/key material is encrypted; category/collection IDs
            // are semi-public identifiers that appear in payment URLs.
            'toyyibpay_secret_key'     => 'encrypted',
            'billplz_api_key'          => 'encrypted',
            'billplz_x_signature_key'  => 'encrypted',
            'allow_monthly'            => 'boolean',
            'allow_quarterly'          => 'boolean',
            'allow_yearly'             => 'boolean',
            'gateway_fee_sen'          => 'integer',
            'overdue_grace_days'       => 'integer',
            'financial_year_start_month' => 'integer',
            'transfer_enabled'         => 'boolean',
            'pending_bank_change'      => 'array',
            'bank_change_requested_at' => 'datetime',
        ];
    }

    /**
     * The active payment gateway for this club. Defaults to ToyyibPay for
     * legacy rows where the column is null/empty.
     */
    public function activeGateway(): string
    {
        return $this->payment_gateway ?: 'toyyibpay';
    }

    /**
     * Plans members may pick when paying ahead, in PLAN_MONTHS order.
     * Rows created before the columns existed (null) count as allowed.
     *
     * @return list<string>
     */
    public function allowedPlans(): array
    {
        return array_values(array_filter(
            array_keys(self::PLAN_MONTHS),
            fn (string $plan) => $this->getAttribute("allow_{$plan}") !== false
        ));
    }

    /** The default plan, falling back to the first allowed one. */
    public function defaultPlan(): string
    {
        $allowed = $this->allowedPlans();
        $plan = $this->default_plan ?: 'yearly';

        return in_array($plan, $allowed, true) ? $plan : ($allowed[0] ?? 'monthly');
    }

    /**
     * 'club' (absorbs the gateway fee) or 'member' (pays it on top). A ToyyibPay
     * club falls back to 'club' until the customer-charge flag is verified
     * (config toyyibpay.charge_to_customer_verified), whatever is stored.
     */
    public function gatewayFeePayer(): string
    {
        if ($this->gateway_fee_payer !== 'member') {
            return 'club';
        }

        return $this->memberFeeAllowed() ? 'member' : 'club';
    }

    /** Whether this club's gateway may pass its fee on to members. */
    public function memberFeeAllowed(): bool
    {
        return $this->activeGateway() !== 'toyyibpay' || (bool) config('toyyibpay.charge_to_customer_verified');
    }

    /** Days after the due date before an unpaid fee counts as overdue (DisplayStatus). */
    public function overdueGraceDays(): int
    {
        return max(0, (int) ($this->overdue_grace_days ?? \App\Support\Payments\DisplayStatus::DEFAULT_GRACE_DAYS));
    }

    /** Expected gateway fee per bill, in sen. */
    public function gatewayFeeSen(): int
    {
        return max(0, (int) ($this->gateway_fee_sen ?? 100));
    }

    /** Bank details are complete enough to show (bank, holder, and account number or DuitNow ID). */
    public function transferDetailsComplete(): bool
    {
        return filled($this->bank_name) && filled($this->bank_account_name)
            && (filled($this->bank_account_no) || filled($this->duitnow_id));
    }

    /** Bank / DuitNow fields whose change needs a second approver (S1). */
    public const BANK_DETAIL_FIELDS = ['bank_name', 'bank_account_name', 'bank_account_no', 'duitnow_id'];

    public function hasPendingBankChange(): bool
    {
        return ! empty($this->pending_bank_change);
    }

    /** Who asked for the pending bank-detail change. */
    public function bankChangeRequestedBy()
    {
        return $this->belongsTo(User::class, 'bank_change_requested_by');
    }

    /** Members may pay by manual bank / DuitNow transfer with a slip (P2). */
    public function transferReady(): bool
    {
        return (bool) $this->transfer_enabled && $this->transferDetailsComplete();
    }

    /**
     * Whether deleting this club would destroy anything worth keeping: members,
     * fees, gateway bills, expenses or documents (G2: such a club can only be
     * deactivated).
     */
    public function hasRecords(): bool
    {
        return $this->members()->exists()
            || $this->payments()->exists()
            || $this->expenses()->exists()
            || \Illuminate\Support\Facades\DB::table('payment_bills')->where('club_id', $this->id)->exists()
            || \Illuminate\Support\Facades\DB::table('attachments')->where('club_id', $this->id)->exists();
    }

    /**
     * Check whether this club has its own ToyyibPay credentials configured.
     */
    public function hasToyyibPayCredentials(): bool
    {
        return ! empty($this->toyyibpay_secret_key)
            && ! empty($this->toyyibpay_category_code);
    }

    /**
     * Check whether this club has its own Billplz credentials configured.
     * Webhook verification requires x_signature_key, so we treat it as
     * mandatory alongside the API key + collection.
     */
    public function hasBillplzCredentials(): bool
    {
        return ! empty($this->billplz_api_key)
            && ! empty($this->billplz_collection_id)
            && ! empty($this->billplz_x_signature_key);
    }

    /**
     * Active admins of this club (club_user.role = admin).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function activeAdmins()
    {
        return $this->members()->wherePivot('role', 'admin')->wherePivot('is_active', true)->get();
    }

    /**
     * Email every active admin of the club (S1 security notices). Failures are
     * logged, never thrown: the change itself already happened.
     *
     * @param  list<string>  $lines
     */
    public function notifyAdmins(string $heading, array $lines, ?string $actionUrl = null, ?string $actionLabel = null, ?User $alsoTo = null): void
    {
        $recipients = $this->activeAdmins()->pluck('email');
        if ($alsoTo) {
            $recipients->push($alsoTo->email);
        }
        foreach ($recipients->filter()->unique()->values() as $email) {
            try {
                \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\ClubAdminNotice($this, $heading, $lines, $actionUrl, $actionLabel));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Club admin notice email failed', ['club_id' => $this->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'club_user')
            ->withPivot(['role', 'job_level', 'joined_date', 'is_active'])
            ->withTimestamps();
    }

    /** The club's job levels (inactive ones included), in display order. */
    public function jobLevels()
    {
        return $this->hasMany(ClubJobLevel::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * code => name for every level of the club (inactive ones too, so existing members and fee rates keep their
     * label), in display order. Uses the loaded jobLevels relation.
     *
     * @return array<string, string>
     */
    public function jobLevelNames(): array
    {
        return $this->jobLevels->pluck('name', 'code')->all();
    }

    /** The display name of a level code; the code itself when the club has no such level. */
    public function jobLevelName(?string $code): string
    {
        return $this->jobLevelNames()[(string) $code] ?? (string) $code;
    }

    /**
     * code => name of the levels offered for new members and fee rates, in display order.
     *
     * @return array<string, string>
     */
    public function activeJobLevelNames(): array
    {
        return $this->jobLevels->where('is_active', true)->pluck('name', 'code')->all();
    }

    /** Give a new club its one starting level ("Ahli"); admins add and rename levels themselves. */
    public function createDefaultJobLevel(): ClubJobLevel
    {
        $level = $this->jobLevels()->create([
            'code' => ClubJobLevel::DEFAULT_CODE,
            'name' => ClubJobLevel::DEFAULT_NAME,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $this->unsetRelation('jobLevels');

        return $level;
    }

    public function feeRates()
    {
        return $this->hasMany(FeeRate::class);
    }

    public function currentFeeRates()
    {
        return $this->hasMany(FeeRate::class)->where(function ($q) {
            $q->whereNull('effective_to')
                ->orWhere('effective_to', '>=', now()->toDateString());
        });
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function discounts()
    {
        return $this->hasMany(Discount::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return \App\Services\LogoService::url($this->logo);
    }

    /** Month (1-12) the club's financial year starts; 1 (January, the calendar year) when unset. */
    public function financialYearStartMonth(): int
    {
        $month = (int) ($this->financial_year_start_month ?? 1);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /**
     * The club details a receipt is issued with (payments.issuer_snapshot): written once when a fee becomes
     * paid, so later profile edits never change an issued receipt. The logo is kept as a path (its URL is built
     * when shown); old logo files are never deleted (LogoService). The receipt footer (settings phase 2) is part
     * of it, so a receipt keeps the footer it was issued with.
     *
     * @return array{name: string, registration_no: ?string, address: ?string, logo: ?string, email: ?string, footer: ?string}
     */
    public function issuerSnapshot(): array
    {
        return [
            'name' => (string) $this->name,
            'registration_no' => $this->registration_no ?: null,
            'address' => $this->address ?: null,
            'logo' => $this->logo ?: null,
            'email' => $this->email ?: null,
            'footer' => $this->receipt_footer ?: null,
        ];
    }
}
