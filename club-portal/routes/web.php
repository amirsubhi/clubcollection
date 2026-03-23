<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Member\DashboardController as MemberDashboard;
use App\Http\Controllers\Member\PaymentController as MemberPayment;

Route::get('/', function () {
    return redirect()->route('login');
});

Auth::routes(['register' => false]);

// ToyyibPay webhook — no auth, exempt from CSRF
Route::post('/webhook/toyyibpay', [WebhookController::class, 'toyyibpay'])
    ->name('webhook.toyyibpay')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::middleware(['auth'])->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    // ── Member Portal ───────────────────────────────────────────
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
    });

    // ── Club Admin + Super Admin ─────────────────────────────────
    Route::middleware(['club_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('clubs.members', MemberController::class)->shallow();
        Route::resource('clubs.fee-rates', FeeRateController::class)->shallow();

        Route::get('clubs/{club}/dashboard', [DashboardController::class, 'index'])->name('clubs.dashboard');

        Route::resource('clubs.payments', PaymentController::class)->shallow();
        Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');

        Route::resource('clubs.expenses', ExpenseController::class)->shallow();
        Route::resource('clubs.expense-categories', ExpenseCategoryController::class)->shallow();
        Route::resource('clubs.discounts', DiscountController::class)->shallow();
    });
});
