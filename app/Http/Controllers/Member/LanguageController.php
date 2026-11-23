<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Support\UiLang;
use App\Support\UserLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * POST /my/language: a language switch saves the choice on the account (users.locale), so it follows the member to
 * another device and their emails go out in it. This is the only way an account's language is written (UserLocale).
 * The member app changes language at once and sends this in the background (resources/js/lib/langSave.ts, JSON, 204;
 * retried later when the request fails); the sign-in-style pages a member sees signed in (change password, privacy
 * notice) post it as a plain form (layouts/auth.blade.php) and get the page back. Allowed while a temporary password
 * must still be changed (EnsurePasswordChanged). Not audited: it is the member's own display preference, like the
 * theme.
 */
class LanguageController extends Controller
{
    public function update(Request $request): Response|RedirectResponse
    {
        $data = $request->validate([
            'lang' => ['required', 'string', Rule::in(UiLang::available())],
        ]);

        UserLocale::save($request->user(), $data['lang']);

        return $request->expectsJson() ? response()->noContent() : back(fallback: route('home'));
    }
}
