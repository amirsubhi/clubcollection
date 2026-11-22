<?php

namespace App\Http\Controllers;

use App\Support\Portal\PrivacyNotice;
use Illuminate\View\View;

/**
 * GET /privacy: the portal's privacy notice (settings phase 2), for anyone (no sign-in), on the sign-in layout.
 * BM by default with the BM/EN switch (AuthPageLocale). With no notice set it says so instead of showing any
 * default legal text.
 */
class PrivacyController extends Controller
{
    public function show(): View
    {
        $lang = app()->getLocale() === 'en' ? 'en' : 'bm';

        return view('privacy', ['notice' => PrivacyNotice::forLanguage($lang), 'lang' => $lang]);
    }
}
