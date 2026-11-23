<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Support\UserLocale;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * users.locale (not fillable; UserLocale::save) is the member's saved language, null = the portal default.
 */
class User extends Authenticatable implements HasLocalePreference
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

    // ── Language ───────────────────────────────────────────────────────────

    /**
     * The language of this person's emails: Mail::to($user) and $user->notify() send in it (Laravel reads
     * HasLocalePreference; a plain email address would not). A member's saved language, else the portal default,
     * English for admins (UserLocale::forMail). Never the browser's choice: the password reset link too goes out in
     * the account's language, whichever browser asked for it (someone else's browser may hold another language).
     */
    public function preferredLocale(): string
    {
        return UserLocale::forMail($this);
    }

    /** The reset-link email, with the Malay time-of-day greeting (App\Notifications\ResetPassword). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
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

        // Claim the step atomically: two requests racing with the same code cannot both win.
        $claimed = static::query()->whereKey($this->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', (int) $step))
            ->update(['two_factor_last_used_step' => (int) $step]);
        if ($claimed !== 1) {
            return false;
        }
        $this->forceFill(['two_factor_last_used_step' => (int) $step])->syncOriginalAttribute('two_factor_last_used_step');

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

        return $this->useRecoveryCode($input);
    }

    /**
     * Spend a recovery code: read, match and remove it under a row lock, so one code cannot be used by two
     * requests at once. False when it does not match.
     */
    public function useRecoveryCode(string $input): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($input) {
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            if (! $fresh) {
                return false;
            }
            $index = $fresh->findRecoveryCode($input);
            if ($index === false) {
                return false;
            }

            $codes = $fresh->getTwoFactorRecoveryCodes();
            unset($codes[$index]);
            $stored = encrypt(json_encode(array_values($codes)));
            $fresh->forceFill(['two_factor_recovery_codes' => $stored])->save();
            $this->forceFill(['two_factor_recovery_codes' => $stored])->syncOriginalAttribute('two_factor_recovery_codes');

            return true;
        });
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
