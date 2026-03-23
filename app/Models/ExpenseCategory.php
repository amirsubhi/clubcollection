<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    protected $fillable = ['club_id', 'name'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
