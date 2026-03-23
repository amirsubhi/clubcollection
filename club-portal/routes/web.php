<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\FeeRateController;
use App\Http\Controllers\Admin\AdminUserController;

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
    });
});
