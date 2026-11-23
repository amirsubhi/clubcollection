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
 *   1. the language saved on the account (users.locale, set by the member app's switch);
 *   2. the browser's choice: ?lang= (the sign-in pages' switch), else the cp_lang cookie (both switches write it);
 *   3. the portal's default language (Portal settings; Malay unless the super admin changed it).
 *
 * Mail sent without a request (the overdue reminders, any mail sent for someone else) uses 1 and 3 only. Every value
 * goes through UiLang::normalise(), so a code that is no longer available falls through to the next step.
 */
final class UserLocale
{
    public static function resolve(?User $user, ?Request $request = null): string
    {
        return self::saved($user)
            ?? ($request !== null ? self::browserChoice($request) : null)
            ?? PortalSettings::defaultLocale();
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

    /** Save a language on the account (the member app's switch, the sign-in pages' switch while signed in). */
    public static function save(User $user, string $code): void
    {
        $code = UiLang::normalise($code);
        if ($code === null || $user->locale === $code) {
            return;
        }
        // Only this column: a language choice must never write back anything else held on the model.
        User::query()->whereKey($user->getKey())->update(['locale' => $code]);
        $user->forceFill(['locale' => $code])->syncOriginalAttribute('locale');
    }

    /**
     * Adopt a language chosen before languages were saved: a signed-in account with nothing saved and a valid
     * cp_lang cookie gets that language saved, once (afterwards the account has one). So someone who switched to
     * English keeps getting English emails instead of the portal default.
     */
    public static function adoptBrowserChoice(User $user, Request $request): void
    {
        if (self::saved($user) !== null) {
            return;
        }
        $cookie = UiLang::normalise($request->cookie(AuthPageLocale::COOKIE));
        if ($cookie !== null) {
            self::save($user, $cookie);
        }
    }
}
