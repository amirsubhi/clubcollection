<?php

namespace App\Models;

use Database\Factories\FeeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeRate extends Model
{
    /** @use HasFactory<FeeRateFactory> */
    use HasFactory;

    protected $fillable = ['club_id', 'job_level', 'monthly_amount', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'monthly_amount' => 'decimal:2',
        ];
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public static function jobLevelLabels(): array
    {
        return [
            'gm' => 'GM',
            'agm' => 'AGM',
            'manager' => 'Manager',
            'executive' => 'Executive',
            'non_exec' => 'Non-Executive',
        ];
    }
}
