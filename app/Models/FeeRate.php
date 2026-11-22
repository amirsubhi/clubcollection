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

    // job_level is a code of the club's own levels (ClubJobLevel); its label is $club->jobLevelName($code).
}
