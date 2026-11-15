<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\ClubBillingController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PaymentController;
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

// ── Installation wizard (only accessible when NOT installed) ────────────────
Route::middleware('redirect_if_installed')->group(function () {
    Route::get('/install', [InstallController::class, 'index'])->name('install');
    Route::post('/install', [InstallController::class, 'process'])->middleware('throttle:5,1')->name('install.process');
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

// ── Dev-only UI kit (Inertia wiring check) — local & testing only ────────────
if (app()->environment('local', 'testing')) {
    Route::get('/dev/ui-kit', fn () => \Inertia\Inertia::render('Dev/UiKit'))->name('dev.ui-kit');
}

// ── All other routes — require app to be installed ─────────────────────────
Route::middleware('check_installed')->group(function () {

    Route::get('/', function () {
        return redirect()->route('login');
    });

    // Register disabled; login throttled to 5 attempts / minute per IP+email
    Auth::routes(['register' => false]);

    // Override login POST with explicit rate limiting
    Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');  // overrides the one from Auth::routes()

    // Same for the password reset and confirm POSTs (S1): 5 a minute per IP,
    // on their own named limiter (AppServiceProvider).
    Route::post('password/email', [\App\Http\Controllers\Auth\ForgotPasswordController::class, 'sendResetLinkEmail'])
        ->middleware('throttle:password-reset')->name('password.email');
    Route::post('password/reset', [\App\Http\Controllers\Auth\ResetPasswordController::class, 'reset'])
        ->middleware('throttle:password-reset')->name('password.update');
    Route::post('password/confirm', [\App\Http\Controllers\Auth\ConfirmPasswordController::class, 'confirm'])
        ->middleware(['auth', 'throttle:password-reset']);

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
        ->middleware('throttle:5,1')
        ->name('two-factor.verify');

    // auth.session (AuthenticateSession) ends every other session of a user
    // whose password changed, e.g. after a password reset (S1).
    Route::middleware(['auth', 'auth.session', 'two_factor'])->group(function () {
        Route::get('/home', [HomeController::class, 'index'])->name('home');

        // ── Profile / 2FA setup ──────────────────────────────────────────────
        Route::get('/profile/security', [ProfileController::class, 'security'])->name('profile.security');
        Route::post('/profile/two-factor/enable', [ProfileController::class, 'enableTwoFactor'])->name('profile.2fa.enable');
        Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.2fa.confirm');
        Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.2fa.disable');

        // ── Member Portal ────────────────────────────────────────────
        Route::middleware(['member'])->prefix('my')->name('member.')->group(function () {
            Route::get('dashboard', [MemberDashboard::class, 'index'])->name('dashboard');

            Route::get('clubs/{club}/payments', [MemberPayment::class, 'index'])->name('payments.index');
            Route::post('clubs/{club}/payments/future', [MemberPayment::class, 'generateFuture'])->middleware('throttle:10,1')->name('payments.generate-future');
            Route::get('payments/{payment}/invoice', [MemberPayment::class, 'invoice'])->name('payments.invoice');
            // Review is the GET page (email links point here); pay is POST-only so a
            // link, prefetch or crawler can never open a bill at the gateway.
            Route::get('payments/{payment}/review', [MemberPayment::class, 'review'])->name('payments.review');
            Route::post('payments/{payment}/pay', [MemberPayment::class, 'pay'])->name('payments.pay');
            Route::get('payments/{payment}/status', [MemberPayment::class, 'status'])->name('payments.status');
            Route::get('payments/{payment}/thankyou', [MemberPayment::class, 'thankyou'])->name('payments.thankyou');
            // Manual bank / DuitNow transfer: upload a slip; a club admin approves it.
            Route::post('payments/{payment}/transfer', [MemberPayment::class, 'transfer'])->middleware('throttle:10,1')->name('payments.transfer');
            // The member's own slip (owner only, audited, no-cache).
            Route::get('slips/{attachment}', [MemberPayment::class, 'slip'])->middleware('throttle:60,1')->name('payments.slip');
        });

        // ── Super Admin ──────────────────────────────────────────────
        Route::middleware(['super_admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::resource('clubs', ClubController::class);
            Route::resource('admins', AdminUserController::class);
            Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        });

        // ── Club Admin + Super Admin ─────────────────────────────────
        Route::middleware(['club_admin'])->prefix('admin')->name('admin.')->group(function () {
            // Member import routes must come before the resource to avoid {member} wildcard collision
            Route::get('clubs/{club}/members/import', [MemberController::class, 'import'])->name('members.import');
            Route::post('clubs/{club}/members/import', [MemberController::class, 'importProcess'])->middleware('throttle:5,1')->name('members.import.process');
            Route::get('clubs/{club}/members/template', [MemberController::class, 'downloadTemplate'])->name('members.template');

            Route::resource('clubs.members', MemberController::class)->shallow();
            Route::resource('clubs.fee-rates', FeeRateController::class)->shallow();

            Route::get('clubs/{club}/dashboard', [DashboardController::class, 'index'])->name('clubs.dashboard');

            // Billing plans and gateway-fee settings (club admins of this club + super admin).
            Route::get('clubs/{club}/billing', [ClubBillingController::class, 'edit'])->name('clubs.billing.edit');
            Route::put('clubs/{club}/billing', [ClubBillingController::class, 'update'])->middleware('throttle:20,1')->name('clubs.billing.update');
            // Bank / DuitNow detail changes need a second admin (S1).
            Route::post('clubs/{club}/billing/bank-change/approve', [ClubBillingController::class, 'approveBankChange'])->middleware('throttle:20,1')->name('clubs.billing.bank-change.approve');
            Route::post('clubs/{club}/billing/bank-change/cancel', [ClubBillingController::class, 'cancelBankChange'])->middleware('throttle:20,1')->name('clubs.billing.bank-change.cancel');
            // The previous Blade dashboard (month filter, paid/unpaid members, job levels).
            Route::get('clubs/{club}/dashboard/classic', [DashboardController::class, 'classic'])->name('clubs.dashboard.classic');

            Route::resource('clubs.payments', PaymentController::class)->shallow();
            Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');

            // ── Fund Usage (expenses + welfare, maker-checker) ─────────────
            Route::get('clubs/{club}/fund-usage', [FundUsageController::class, 'index'])->name('clubs.fund-usage.index');
            Route::get('clubs/{club}/fund-usage/create', [FundUsageController::class, 'create'])->name('clubs.fund-usage.create');
            Route::post('clubs/{club}/fund-usage', [FundUsageController::class, 'store'])->middleware('throttle:20,1')->name('clubs.fund-usage.store');
            Route::get('fund-usage/{expense}', [FundUsageController::class, 'show'])->name('fund-usage.show');
            Route::get('fund-usage/{expense}/edit', [FundUsageController::class, 'edit'])->name('fund-usage.edit');
            Route::put('fund-usage/{expense}', [FundUsageController::class, 'update'])->middleware('throttle:20,1')->name('fund-usage.update');
            Route::delete('fund-usage/{expense}', [FundUsageController::class, 'destroy'])->name('fund-usage.destroy');
            Route::middleware('throttle:20,1')->group(function () {
                Route::post('fund-usage/{expense}/submit', [FundUsageController::class, 'submit'])->name('fund-usage.submit');
                Route::post('fund-usage/{expense}/approve', [FundUsageController::class, 'approve'])->name('fund-usage.approve');
                Route::post('fund-usage/{expense}/return', [FundUsageController::class, 'returnToDraft'])->name('fund-usage.return');
                Route::post('fund-usage/{expense}/void-request', [FundUsageController::class, 'voidRequest'])->name('fund-usage.void-request');
                Route::post('fund-usage/{expense}/void-confirm', [FundUsageController::class, 'voidConfirm'])->name('fund-usage.void-confirm');
            });
            // ── Payment verifications (member transfer slips) ─────────────
            Route::get('clubs/{club}/verifications', [TransferVerificationController::class, 'index'])->name('clubs.verifications.index');
            Route::middleware('throttle:20,1')->group(function () {
                Route::post('verifications/{payment}/approve', [TransferVerificationController::class, 'approve'])->name('verifications.approve');
                Route::post('verifications/{payment}/reject', [TransferVerificationController::class, 'reject'])->name('verifications.reject');
            });

            // Private documents: club admins of the owning club only, audited.
            Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->middleware('throttle:60,1')->name('attachments.show');
            // Gallery thumbnails (images only): authorized, not audited, looser limit.
            Route::get('attachments/{attachment}/thumb', [AttachmentController::class, 'thumb'])->middleware('throttle:300,1')->name('attachments.thumb');

            // Old Expenses URLs redirect to Fund Usage (read-only GETs only).
            Route::get('clubs/{club}/expenses', [ExpenseController::class, 'index'])->name('clubs.expenses.index');
            Route::get('clubs/{club}/expenses/create', [ExpenseController::class, 'create'])->name('clubs.expenses.create');
            Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
            Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
            Route::resource('clubs.expense-categories', ExpenseCategoryController::class)->shallow();
            Route::resource('clubs.discounts', DiscountController::class)->shallow();
            Route::get('clubs/{club}/audit-logs', [AuditLogController::class, 'clubLogs'])->name('clubs.audit-logs');

            Route::get('clubs/{club}/ledger',            [LedgerController::class, 'index'])->name('clubs.ledger');
            Route::get('clubs/{club}/ledger/export',     [LedgerController::class, 'export'])->name('clubs.ledger.export');
            Route::get('clubs/{club}/ledger/export-pdf', [LedgerController::class, 'exportPdf'])->name('clubs.ledger.export-pdf');
        });
    });

});
