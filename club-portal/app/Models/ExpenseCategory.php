<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
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
