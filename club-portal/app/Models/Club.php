<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    protected $fillable = [
        'name', 'logo', 'email', 'is_active',
        'toyyibpay_secret_key', 'toyyibpay_category_code',
    ];

    protected function casts(): array
    {
        return [
            // Secret key is encrypted at rest using the APP_KEY
            'toyyibpay_secret_key' => 'encrypted',
        ];
    }

    /**
     * Check whether this club has its own ToyyibPay credentials configured.
     */
    public function hasToyyibPayCredentials(): bool
    {
        return !empty($this->toyyibpay_secret_key)
            && !empty($this->toyyibpay_category_code);
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
        return $this->hasMany(FeeRate::class)
            ->whereNull('effective_to')
            ->orWhere('effective_to', '>=', now()->toDateString());
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
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }
}
