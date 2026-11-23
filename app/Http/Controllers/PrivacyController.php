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
        // The notice exists in Malay and English only: Malay readers get Malay, every other language English first.
        $lang = app()->getLocale() === 'ms' ? 'bm' : 'en';

        return view('privacy', ['notice' => PrivacyNotice::forLanguage($lang), 'lang' => $lang]);
    }
}
