<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = ['club_id', 'name', 'type', 'value', 'valid_from', 'valid_to', 'is_active'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
