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
        'name', 'logo', 'email', 'is_active',
        'payment_gateway',
        'toyyibpay_secret_key', 'toyyibpay_category_code',
        'billplz_api_key', 'billplz_collection_id', 'billplz_x_signature_key',
        'allow_monthly', 'allow_quarterly', 'allow_yearly', 'default_plan',
        'gateway_fee_payer', 'gateway_fee_sen',
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

    /** Expected gateway fee per bill, in sen. */
    public function gatewayFeeSen(): int
    {
        return max(0, (int) ($this->gateway_fee_sen ?? 100));
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

    public function members()
    {
        return $this->belongsToMany(User::class, 'club_user')
            ->withPivot(['role', 'job_level', 'joined_date', 'is_active'])
            ->withTimestamps();
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
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }
}
