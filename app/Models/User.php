<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'two_factor_confirmed_at'  => 'datetime',
            // Not fillable: set with forceFill / forceCreate where an admin emails a temporary password.
            'must_change_password'     => 'boolean',
            'password'                 => 'hashed',
        ];
    }

    // ── Two-factor authentication ──────────────────────────────────────────

    public function hasEnabledTwoFactor(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->two_factor_secret ? decrypt($this->two_factor_secret) : null;
    }

    public function getTwoFactorRecoveryCodes(): array
    {
        if (! $this->two_factor_recovery_codes) {
            return [];
        }
        return json_decode(decrypt($this->two_factor_recovery_codes), true) ?? [];
    }

    /**
     * Check a TOTP code and remember its time step, so the same code (or an
     * older one) is never accepted again (S1: no replay within its window).
     */
    public function verifyTwoFactorCode(string $code, ?string $secret = null): bool
    {
        $secret ??= $this->getTwoFactorSecret();
        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = (new \PragmaRX\Google2FA\Google2FA())->verifyKeyNewer($secret, $code, (int) ($this->two_factor_last_used_step ?? 0));
        if ($step === false) {
            return false;
        }

        $this->forceFill(['two_factor_last_used_step' => (int) $step])->save();

        return true;
    }

    /**
     * Index of the stored recovery code that $typed matches, or false. Compared without case, spaces or
     * dashes ("abcd efgh" matches "ABCD-EFGH"), in constant time per code.
     */
    public function findRecoveryCode(string $typed): int|false
    {
        $norm = fn ($c) => strtoupper(preg_replace('/[\s-]+/', '', (string) $c));
        $typed = $norm($typed);
        if ($typed === '') {
            return false;
        }

        foreach ($this->getTwoFactorRecoveryCodes() as $i => $code) {
            if (hash_equals($norm($code), $typed)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * A current TOTP code (not replayed) or one of the recovery codes. A matched recovery code is used up.
     */
    public function verifyTwoFactorCodeOrRecoveryCode(string $input): bool
    {
        $input = trim($input);
        if (preg_match('/^\d{6}$/', $input)) {
            return $this->verifyTwoFactorCode($input);
        }

        $index = $this->findRecoveryCode($input);
        if ($index === false) {
            return false;
        }

        $codes = $this->getTwoFactorRecoveryCodes();
        unset($codes[$index]);
        $this->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values($codes)))])->save();

        return true;
    }

    // ── Role helpers ───────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin']);
    }

    public function clubs()
    {
        return $this->belongsToMany(Club::class, 'club_user')
            ->withPivot(['role', 'job_level', 'joined_date', 'is_active'])
            ->withTimestamps();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
