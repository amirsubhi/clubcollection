<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Member\DashboardController as MemberDashboard;
use App\Http\Controllers\Member\PaymentController as MemberPayment;

// ── Installation wizard (only accessible when NOT installed) ────────────────
Route::middleware('redirect_if_installed')->group(function () {
    Route::get('/install', [InstallController::class, 'index'])->name('install');
    Route::post('/install', [InstallController::class, 'process'])->middleware('throttle:5,1')->name('install.process');
});

// ── ToyyibPay webhook — external, no install check, no CSRF ────────────────
Route::post('/webhook/toyyibpay', [WebhookController::class, 'toyyibpay'])
    ->name('webhook.toyyibpay')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

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

    // ── Two-factor challenge — auth required, but 2FA not yet needed ──────────
    Route::middleware(['auth'])->group(function () {
        Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
        Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])->name('two-factor.verify');
    });

    Route::middleware(['auth', 'two_factor'])->group(function () {
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
            Route::post('clubs/{club}/payments/future', [MemberPayment::class, 'generateFuture'])->name('payments.generate-future');
            Route::get('payments/{payment}/invoice', [MemberPayment::class, 'invoice'])->name('payments.invoice');
            Route::get('payments/{payment}/pay', [MemberPayment::class, 'pay'])->name('payments.pay');
            Route::get('payments/{payment}/thankyou', [MemberPayment::class, 'thankyou'])->name('payments.thankyou');
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

            Route::resource('clubs.payments', PaymentController::class)->shallow();
            Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');

            Route::resource('clubs.expenses', ExpenseController::class)->shallow();
            Route::resource('clubs.expense-categories', ExpenseCategoryController::class)->shallow();
            Route::resource('clubs.discounts', DiscountController::class)->shallow();
            Route::get('clubs/{club}/audit-logs', [AuditLogController::class, 'clubLogs'])->name('clubs.audit-logs');
        });
    });

});
