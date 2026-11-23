<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Support\UiLang;
use App\Support\UserLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * POST /my/language: the member app's language switch saves the choice on the account (users.locale), so it
 * follows the member to another device and their emails go out in it. The app changes language at once and sends
 * this in the background (resources/js/app.tsx); it retries later when the request fails. Not audited: it is the
 * member's own display preference, like the theme.
 */
class LanguageController extends Controller
{
    public function update(Request $request): Response
    {
        $data = $request->validate([
            'lang' => ['required', 'string', Rule::in(UiLang::available())],
        ]);

        UserLocale::save($request->user(), $data['lang']);

        return response()->noContent();
    }
}
