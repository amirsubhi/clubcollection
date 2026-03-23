<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\DashboardController;

Route::get('/', function () {
    return redirect()->route('login');
});

Auth::routes(['register' => false]);

Route::middleware(['auth'])->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    // Super Admin only
    Route::middleware(['super_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('clubs', ClubController::class);
        Route::resource('admins', AdminUserController::class);
    });

    // Club Admin + Super Admin
    Route::middleware(['club_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('clubs.members', MemberController::class)->shallow();
        Route::resource('clubs.fee-rates', FeeRateController::class)->shallow();

        // Financial dashboard per club
        Route::get('clubs/{club}/dashboard', [DashboardController::class, 'index'])->name('clubs.dashboard');

        // Payments
        Route::resource('clubs.payments', PaymentController::class)->shallow();
        Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');

        // Expenses
        Route::resource('clubs.expenses', ExpenseController::class)->shallow();

        // Expense Categories
        Route::resource('clubs.expense-categories', ExpenseCategoryController::class)->shallow();

        // Discounts
        Route::resource('clubs.discounts', DiscountController::class)->shallow();
    });
});
