<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\ClubBillingController;
use App\Http\Controllers\Admin\ClubProfileController;
use App\Http\Controllers\Admin\PortalSettingsController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\JobLevelController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\FeeGenerationController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\FundUsageController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\TransferVerificationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Member\DashboardController as MemberDashboard;
use App\Http\Controllers\Member\PaymentController as MemberPayment;
use App\Http\Controllers\Member\PayAllController as MemberPayAll;

// ── Installation wizard (only accessible when NOT installed) ────────────────
Route::middleware('redirect_if_installed')->group(function () {
    Route::get('/install', [InstallController::class, 'index'])->name('install');
    Route::post('/install', [InstallController::class, 'process'])->middleware('throttle:install')->name('install.process');
});

// ── Payment gateway webhooks — external, no install check, no CSRF ────────
// Each gateway has its own route because payload shape and signature
// scheme differ; the controller funnels them into a shared helper.
// Exclude the class the web group actually uses (PreventRequestForgery in
// Laravel 13): excluding its subclass VerifyCsrfToken did not match, so real
// gateway callbacks got 419 (tests skip CSRF, so only the e2e smoke caught it).
Route::post('/webhook/toyyibpay', [WebhookController::class, 'toyyibpay'])
    ->name('webhook.toyyibpay')
    ->middleware('throttle:webhooks')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

Route::post('/webhook/billplz', [WebhookController::class, 'billplz'])
    ->name('webhook.billplz')
    ->middleware('throttle:webhooks')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

// ── Web app manifest (portal name, brand colour, icons) — public, before install too ──
// No session, cookies or CSRF: it sets no cookie and reads only the portal settings.
Route::get('/app.webmanifest', \App\Http\Controllers\AppManifestController::class)
    ->name('pwa.manifest')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);

// ── Dev-only UI kit (Inertia wiring check) — local & testing only ────────────
if (app()->environment('local', 'testing')) {
    Route::get('/dev/ui-kit', fn () => \Inertia\Inertia::render('Dev/UiKit'))->name('dev.ui-kit');
}

// ── All other routes — require app to be installed ─────────────────────────
Route::middleware('check_installed')->group(function () {

    // Sign-in pages: BM by default, EN via the page's switch (AuthPageLocale).
    Route::middleware('auth_locale')->group(function () {
        // The front page is the sign-in page itself (no extra step before the form). LoginController's
        // `guest` middleware sends a signed-in user on to /home.
        Route::get('/', [\App\Http\Controllers\Auth\LoginController::class, 'showLoginForm'])->name('front');

        // The portal's privacy notice: public (no sign-in, no `guest`, so members can open it too).
        Route::get('/privacy', [\App\Http\Controllers\PrivacyController::class, 'show'])->name('privacy');

        // Register disabled.
        Auth::routes(['register' => false]);

        // Replaces Auth::routes()' POST /login (no name, so it doesn't clash with GET 'login' under route:cache).
        // throttle:login (AppServiceProvider): 5 a minute per email + IP and 30 a minute per IP (an office network
        // shares one IP); LoginController also pauses one account for 15 minutes after 10 wrong passwords.
        Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'login'])
            ->middleware('throttle:login');

        // Password reset and confirm POSTs: each form its own limiter, per email + IP and per IP (AppServiceProvider).
        Route::post('password/email', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'sendResetLinkEmail'])
            ->middleware('throttle:password-email')->name('password.email');
        Route::post('password/reset', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset'])
            ->middleware('throttle:password-reset')->name('password.update');
        Route::post('password/confirm', [\App\Http\Controllers\Auth\ConfirmPasswordController::class, 'confirm'])
            ->middleware(['auth', 'throttle:password-confirm']);

        // ── Two-factor challenge — no `auth` middleware ───────────────────────────
        // LoginController::authenticated() calls auth()->logout() and pins the
        // user id in the session under 'two_factor_user_id'. If we required `auth`
        // here, the redirect after login would bounce back to /login. The
        // controller itself gates on session('two_factor_user_id') and redirects
        // already-authenticated users home.
        Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
        // Throttle the verify POST to defend against brute-forcing TOTP codes
        // (10**6 search space) and recovery codes (8 codes, alphanumeric).
        Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
            ->middleware('throttle:two-factor')
            ->name('two-factor.verify');
    }); // auth_locale

    // auth.session (AuthenticateSession) ends every other session of a user
    // whose password changed, e.g. after a password reset (S1).
    // password_changed: an account with an emailed temporary password must choose its own first
    // (EnsurePasswordChanged); it allows only the change-password page and logout.
    // admin_two_factor: with REQUIRE_ADMIN_2FA (default on) a super admin / club admin without 2FA is sent to
    // the Security page to set it up (RequireAdminTwoFactor). Members are never forced.
    Route::middleware(['auth', 'auth.session', 'two_factor', 'password_changed', 'admin_two_factor'])->group(function () {
        Route::get('/home', [HomeController::class, 'index'])->name('home');

        // ── Change password (sign-in page style, BM by default) ─────────────
        Route::middleware('auth_locale')->group(function () {
            Route::get('/password/change', [\App\Http\Controllers\Auth\ChangePasswordController::class, 'show'])->name('password.change');
            Route::post('/password/change', [\App\Http\Controllers\Auth\ChangePasswordController::class, 'update'])
                ->middleware('throttle:password-change')->name('password.change.update');
        });

        // ── Profile / 2FA setup ──────────────────────────────────────────────
        Route::get('/profile/security', [ProfileController::class, 'security'])->name('profile.security');
        // Throttled like the challenge: both check the password, and confirm also checks a TOTP code.
        Route::post('/profile/two-factor/enable', [ProfileController::class, 'enableTwoFactor'])->middleware('throttle:two-factor-setup')->name('profile.2fa.enable');
        Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->middleware('throttle:two-factor-setup')->name('profile.2fa.confirm');
        Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->middleware('throttle:two-factor-setup')->name('profile.2fa.disable');

        // ── Member Portal ────────────────────────────────────────────
        Route::middleware(['member'])->prefix('my')->name('member.')->group(function () {
            Route::get('dashboard', [MemberDashboard::class, 'index'])->name('dashboard');

            Route::get('clubs/{club}/payments', [MemberPayment::class, 'index'])->name('payments.index');
            // "Ke mana wang anda pergi": the club's fund this year (aggregates only).
            Route::get('clubs/{club}/fund', [\App\Http\Controllers\Member\FundController::class, 'show'])->name('clubs.fund');
            Route::post('clubs/{club}/payments/future', [MemberPayment::class, 'generateFuture'])->middleware('throttle:10,1,payments.generate-future|')->name('payments.generate-future');
            Route::get('payments/{payment}/invoice', [MemberPayment::class, 'invoice'])->name('payments.invoice');
            // Review is the GET page (email links point here); pay is POST-only so a
            // link, prefetch or crawler can never open a bill at the gateway.
            Route::get('payments/{payment}/review', [MemberPayment::class, 'review'])->name('payments.review');
            Route::post('payments/{payment}/pay', [MemberPayment::class, 'pay'])->middleware('throttle:10,1,member-pay|')->name('payments.pay');
            Route::get('payments/{payment}/status', [MemberPayment::class, 'status'])->name('payments.status');
            Route::get('payments/{payment}/thankyou', [MemberPayment::class, 'thankyou'])->name('payments.thankyou');
            // Manual bank / DuitNow transfer: upload a slip; a club admin approves it.
            Route::post('payments/{payment}/transfer', [MemberPayment::class, 'transfer'])->middleware('throttle:10,1,member-transfer|')->name('payments.transfer');
            // The member's own slip (owner only, audited, no-cache).
            Route::get('slips/{attachment}', [MemberPayment::class, 'slip'])->middleware('throttle:60,1,payments.slip|')->name('payments.slip');
            // "Bayar semua": every open fee in a club with one bill or one slip (R1b).
            Route::get('clubs/{club}/pay-all', [MemberPayAll::class, 'review'])->name('payments.review-all');
            Route::post('clubs/{club}/pay-all', [MemberPayAll::class, 'pay'])->middleware('throttle:10,1,member-pay|')->name('payments.pay-all');
            Route::post('clubs/{club}/pay-all/transfer', [MemberPayAll::class, 'transfer'])->middleware('throttle:10,1,member-transfer|')->name('payments.transfer-all');
            Route::get('pay-all/{paymentBill}', [MemberPayAll::class, 'result'])->name('payments.group-result');
            Route::get('pay-all/{paymentBill}/status', [MemberPayAll::class, 'status'])->name('payments.group-status');
        });

        // ── Super Admin ──────────────────────────────────────────────
        Route::middleware(['super_admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::resource('clubs', ClubController::class);
            Route::resource('admins', AdminUserController::class)->except('show');
            // Privacy notice and data-protection contact (App\Support\Portal\PrivacyNotice), shown on /privacy.
            Route::get('portal-settings/privacy', [\App\Http\Controllers\Admin\PrivacyNoticeController::class, 'edit'])->name('privacy-notice.edit');
            Route::put('portal-settings/privacy', [\App\Http\Controllers\Admin\PrivacyNoticeController::class, 'update'])->middleware('throttle:20,1,privacy-notice.update|')->name('privacy-notice.update');
            Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            // Portal name and logo (App\Support\Portal\PortalSettings).
            Route::get('portal-settings', [PortalSettingsController::class, 'edit'])->name('portal-settings.edit');
            Route::put('portal-settings', [PortalSettingsController::class, 'update'])->middleware('throttle:20,1,portal-settings.update|')->name('portal-settings.update');
        });

        // ── Club Admin + Super Admin ─────────────────────────────────
        Route::middleware(['club_admin'])->prefix('admin')->name('admin.')->group(function () {
            // Member import routes must come before the resource to avoid {member} wildcard collision
            Route::get('clubs/{club}/members/import', [MemberController::class, 'import'])->name('members.import');
            Route::post('clubs/{club}/members/import', [MemberController::class, 'importProcess'])->middleware('throttle:5,1,members.import.process|')->name('members.import.process');
            Route::get('clubs/{club}/members/template', [MemberController::class, 'downloadTemplate'])->name('members.template');

            Route::resource('clubs.members', MemberController::class)->shallow()->except('show');
            Route::resource('clubs.fee-rates', FeeRateController::class)->shallow()->only(['index', 'create', 'store', 'destroy']);
            // The club's own job levels (fee rates are set per level). Shallow actions authorize via the level's club.
            Route::patch('job-levels/{jobLevel}/active', [JobLevelController::class, 'setActive'])->name('job-levels.active');
            Route::resource('clubs.job-levels', JobLevelController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);

            Route::get('clubs/{club}/dashboard', [DashboardController::class, 'index'])->name('clubs.dashboard');

            // Club profile: name, logo, contact, registration no., address, financial year (club admins of this club + super admin).
            Route::get('clubs/{club}/profile', [ClubProfileController::class, 'edit'])->name('clubs.profile.edit');
            Route::put('clubs/{club}/profile', [ClubProfileController::class, 'update'])->middleware('throttle:20,1,clubs.profile.update|')->name('clubs.profile.update');

            // Billing plans and gateway-fee settings (club admins of this club + super admin).
            Route::get('clubs/{club}/billing', [ClubBillingController::class, 'edit'])->name('clubs.billing.edit');
            Route::put('clubs/{club}/billing', [ClubBillingController::class, 'update'])->middleware('throttle:20,1,clubs.billing.update|')->name('clubs.billing.update');
            // Bank / DuitNow detail changes need a second admin (S1).
            Route::post('clubs/{club}/billing/bank-change/approve', [ClubBillingController::class, 'approveBankChange'])->middleware('throttle:20,1,clubs.billing.bank-change.approve|')->name('clubs.billing.bank-change.approve');
            Route::post('clubs/{club}/billing/bank-change/cancel', [ClubBillingController::class, 'cancelBankChange'])->middleware('throttle:20,1,clubs.billing.bank-change.cancel|')->name('clubs.billing.bank-change.cancel');
            // The previous Blade dashboard (month filter, paid/unpaid members, job levels).
            Route::get('clubs/{club}/dashboard/classic', [DashboardController::class, 'classic'])->name('clubs.dashboard.classic');

            // "Generate fees for a month" (MonthlyFeeGenerator): preview, then create; literal routes before the resource.
            Route::get('clubs/{club}/payments/generate', [FeeGenerationController::class, 'preview'])->name('clubs.payments.generate');
            Route::post('clubs/{club}/payments/generate', [FeeGenerationController::class, 'store'])->middleware('throttle:6,1,clubs.payments.generate.store|')->name('clubs.payments.generate.store');
            Route::resource('clubs.payments', PaymentController::class)->shallow();
            Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');
            // "Cancel payment" (PaymentCancellationService): a paid fee, or the set it was paid with, back to unpaid with a reason.
            Route::get('payments/{payment}/cancel', [PaymentController::class, 'cancelForm'])->name('payments.cancel-form');
            Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->middleware('throttle:10,1,payments.cancel|')->name('payments.cancel');

            // ── Fund Usage (expenses + welfare, maker-checker) ─────────────
            Route::get('clubs/{club}/fund-usage', [FundUsageController::class, 'index'])->name('clubs.fund-usage.index');
            Route::get('clubs/{club}/fund-usage/create', [FundUsageController::class, 'create'])->name('clubs.fund-usage.create');
            Route::post('clubs/{club}/fund-usage', [FundUsageController::class, 'store'])->middleware('throttle:20,1,clubs.fund-usage.store|')->name('clubs.fund-usage.store');
            Route::get('fund-usage/{expense}', [FundUsageController::class, 'show'])->name('fund-usage.show');
            Route::get('fund-usage/{expense}/edit', [FundUsageController::class, 'edit'])->name('fund-usage.edit');
            Route::put('fund-usage/{expense}', [FundUsageController::class, 'update'])->middleware('throttle:20,1,fund-usage.update|')->name('fund-usage.update');
            Route::delete('fund-usage/{expense}', [FundUsageController::class, 'destroy'])->name('fund-usage.destroy');
            Route::middleware('throttle:20,1,fund-usage-workflow|')->group(function () {
                Route::post('fund-usage/{expense}/submit', [FundUsageController::class, 'submit'])->name('fund-usage.submit');
                Route::post('fund-usage/{expense}/approve', [FundUsageController::class, 'approve'])->name('fund-usage.approve');
                Route::post('fund-usage/{expense}/return', [FundUsageController::class, 'returnToDraft'])->name('fund-usage.return');
                Route::post('fund-usage/{expense}/void-request', [FundUsageController::class, 'voidRequest'])->name('fund-usage.void-request');
                Route::post('fund-usage/{expense}/void-confirm', [FundUsageController::class, 'voidConfirm'])->name('fund-usage.void-confirm');
            });
            // ── Payment verifications (member transfer slips) ─────────────
            Route::get('clubs/{club}/verifications', [TransferVerificationController::class, 'index'])->name('clubs.verifications.index');
            Route::middleware('throttle:20,1,verifications|')->group(function () {
                Route::post('verifications/{payment}/approve', [TransferVerificationController::class, 'approve'])->name('verifications.approve');
                Route::post('verifications/{payment}/reject', [TransferVerificationController::class, 'reject'])->name('verifications.reject');
            });

            // Private documents: club admins of the owning club only, audited.
            Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->middleware('throttle:60,1,attachments.show|')->name('attachments.show');
            // Gallery thumbnails (images only): authorized, not audited, looser limit.
            Route::get('attachments/{attachment}/thumb', [AttachmentController::class, 'thumb'])->middleware('throttle:300,1,attachments.thumb|')->name('attachments.thumb');

            // Old Expenses URLs redirect to Fund Usage (read-only GETs only).
            Route::get('clubs/{club}/expenses', [ExpenseController::class, 'index'])->name('clubs.expenses.index');
            Route::get('clubs/{club}/expenses/create', [ExpenseController::class, 'create'])->name('clubs.expenses.create');
            Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
            Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
            Route::resource('clubs.expense-categories', ExpenseCategoryController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
            Route::resource('clubs.discounts', DiscountController::class)->shallow()->except('show');
            Route::get('clubs/{club}/audit-logs', [AuditLogController::class, 'clubLogs'])->name('clubs.audit-logs');

            Route::get('clubs/{club}/ledger',            [LedgerController::class, 'index'])->name('clubs.ledger');
            Route::get('clubs/{club}/ledger/export',     [LedgerController::class, 'export'])->name('clubs.ledger.export');
            Route::get('clubs/{club}/ledger/export-pdf', [LedgerController::class, 'exportPdf'])->name('clubs.ledger.export-pdf');
        });
    });

});

// Unknown URLs (App\Http\Controllers\FallbackController): /admin* keeps the session (admin-styled 404 for
// admins); every other unknown URL runs without it, so a guest's typo starts no session. Neither checks CSRF.
Route::any('admin/{any?}', \App\Http\Controllers\FallbackController::class)->where('any', '.*')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])
    ->name('fallback.admin');
Route::any('{any}', \App\Http\Controllers\FallbackController::class)->where('any', '.*')->fallback()->name('fallback')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
