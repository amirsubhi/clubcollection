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
    ];

    protected function casts(): array
    {
        return [
            // Only secret/key material is encrypted; category/collection IDs
            // are semi-public identifiers that appear in payment URLs.
            'toyyibpay_secret_key'     => 'encrypted',
            'billplz_api_key'          => 'encrypted',
            'billplz_x_signature_key'  => 'encrypted',
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
