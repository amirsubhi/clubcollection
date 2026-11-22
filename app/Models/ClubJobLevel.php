<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A job level (tahap jawatan) a club defines for itself: members are put on one and fee rates are set per level.
 * `code` is the stable key stored in club_user.job_level and fee_rates.job_level; `name` is the label and may be
 * renamed freely. A level in use can only be deactivated (no longer offered), never deleted.
 */
class ClubJobLevel extends Model
{
    /** The one level a new club starts with. */
    public const DEFAULT_CODE = 'member';

    public const DEFAULT_NAME = 'Ahli';

    /** club_user.job_level / fee_rates.job_level are VARCHAR(40). */
    public const CODE_MAX = 40;

    protected $fillable = ['club_id', 'code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Members of the club on this level (active or not). */
    public function memberCount(): int
    {
        return DB::table('club_user')->where('club_id', $this->club_id)->where('job_level', $this->code)->count();
    }

    /** Fee-rate records of the club for this level (current and past). */
    public function feeRateCount(): int
    {
        return DB::table('fee_rates')->where('club_id', $this->club_id)->where('job_level', $this->code)->count();
    }

    /** Used by a member or a fee rate: such a level can only be deactivated. */
    public function isInUse(): bool
    {
        return DB::table('club_user')->where('club_id', $this->club_id)->where('job_level', $this->code)->exists()
            || DB::table('fee_rates')->where('club_id', $this->club_id)->where('job_level', $this->code)->exists();
    }

    /**
     * A new code for $name in $club: the name slugged with underscores (ASCII, lower case), made unique within the
     * club with a _2, _3 ... suffix. "level" when the name has no Latin letters or digits.
     */
    public static function codeFor(Club $club, string $name): string
    {
        $base = rtrim(substr(Str::slug($name, '_'), 0, self::CODE_MAX - 4), '_') ?: 'level';
        // Codes already used in the club (by a level, a member or a fee rate), compared in lower case:
        // MariaDB / MySQL compare them without case.
        $taken = DB::table('club_job_levels')->where('club_id', $club->id)->pluck('code')
            ->merge(DB::table('club_user')->where('club_id', $club->id)->distinct()->pluck('job_level'))
            ->merge(DB::table('fee_rates')->where('club_id', $club->id)->distinct()->pluck('job_level'))
            ->map(fn ($c) => mb_strtolower((string) $c))->flip();

        $code = $base;
        for ($i = 2; isset($taken[$code]); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
