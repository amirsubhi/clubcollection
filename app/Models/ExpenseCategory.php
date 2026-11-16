<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    /** The category name for bank charges entered by hand (see DEFAULTS). */
    public const BANK_CHARGES = 'Bank charges';

    protected $fillable = ['club_id', 'name', 'name_ms'];

    /**
     * Default categories for the demo and e2e seeders (new clubs start with none): English name => Bahasa Melayu name.
     * "Bank charges" is for charges the portal can't see (account fees, IBG/DuitNow charges, a gateway charge lost after
     * a revert); gateway fees the club absorbs on online payments are added to the figures automatically.
     */
    public const DEFAULTS = [
        'Event' => 'Acara',
        'Food & Beverage' => 'Makanan & minuman',
        'Supplies' => 'Bekalan',
        'Decoration' => 'Hiasan',
        'Transportation' => 'Pengangkutan',
        'Printing & Stationery' => 'Cetakan & alat tulis',
        'Maintenance' => 'Penyelenggaraan',
        'Bank charges' => 'Caj bank',
        'Miscellaneous' => 'Pelbagai',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
