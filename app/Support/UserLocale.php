<?php

namespace App\Support;

use App\Http\Middleware\AuthPageLocale;
use App\Models\User;
use App\Support\Portal\PortalSettings;
use Illuminate\Http\Request;

/**
 * Which language someone gets on the member side: the one rule, used by the sign-in pages (AuthPageLocale), the
 * member app (HandleInertiaRequests' shared `locale`, which app.tsx starts from) and member emails
 * (User::preferredLocale(), so Mail::to($user) and $user->notify() send in it).
 *
 *   - a signed-in member, and every email to a member: the language saved on the account (users.locale), else the
 *     portal's default language (Portal settings; Malay unless the super admin changed it);
 *   - a guest (a sign-in page before signing in) and an admin on a sign-in page: the browser's choice (?lang=, the
 *     page's switch, else the cp_lang cookie), else the portal default.
 *
 * An account's language is written only by save(), called from a POST its signed-in owner makes from a language
 * switch (Member\LanguageController). Nothing a browser merely carries (the cp_lang cookie, the member app's
 * cp-lang storage, a ?lang= link) is ever written to an account, so someone signing in on a browser another
 * person used gets their own language (or the portal default), never the other person's. Every value goes through
 * UiLang::normalise(), so a code that is no longer available falls through to the next step.
 */
final class UserLocale
{
    /** Admins and the super admin: the admin side, their emails included, stays English (owner's decision). */
    public const ADMIN_LOCALE = 'en';

    /** The language of email sent to this person: a member's saved language (else the default), English for anyone else. */
    public static function forMail(User $user): string
    {
        return $user->role === 'member' ? self::forMember($user) : self::ADMIN_LOCALE;
    }

    public static function resolve(?User $user, ?Request $request = null): string
    {
        if ($user !== null && $user->role === 'member') {
            return self::forMember($user);
        }

        return ($request !== null ? self::browserChoice($request) : null) ?? PortalSettings::defaultLocale();
    }

    /** A member, signed in or written to: the saved language, else the portal default (never the browser's). */
    public static function forMember(User $user): string
    {
        return self::saved($user) ?? PortalSettings::defaultLocale();
    }

    /** The account's saved language, or null (none saved, or no longer available). */
    public static function saved(?User $user): ?string
    {
        return $user === null ? null : UiLang::normalise($user->locale);
    }

    /** ?lang= first (a switch clicked just now), else the cp_lang cookie; null when neither holds a language. */
    public static function browserChoice(Request $request): ?string
    {
        return UiLang::normalise($request->query('lang'))
            ?? UiLang::normalise($request->cookie(AuthPageLocale::COOKIE));
    }

    /** Save a language on the account: only from its signed-in owner's own POST (Member\LanguageController). */
    public static function save(User $user, string $code): void
    {
        $code = UiLang::normalise($code);
        // Members only: an admin's emails are English whatever the switch shows, so nothing is kept for them.
        if ($user->role !== 'member' || $code === null || $user->locale === $code) {
            return;
        }
        // Only this column: a language choice must never write back anything else held on the model.
        User::query()->whereKey($user->getKey())->update(['locale' => $code]);
        $user->forceFill(['locale' => $code])->syncOriginalAttribute('locale');
    }
}
