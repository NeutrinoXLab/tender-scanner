<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\NoticePersonalController;
use App\Http\Controllers\NoticeSupplierController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/', fn () => redirect()->route('notices.index'));
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/notices', [NoticeController::class, 'index'])->name('notices.index');
    Route::get('/notices/{notice}', [NoticeController::class, 'show'])->name('notices.show');
    Route::patch('/notices/{notice}/personal', [NoticePersonalController::class, 'update'])->name('notices.personal.update');
    Route::get('/notices/{notice}/suppliers', [NoticeSupplierController::class, 'index'])->middleware('throttle:30,1')->name('notices.suppliers');
    Route::post('/scans', [ScanController::class, 'store'])->middleware('throttle:10,1')->name('scans.store');
    Route::get('/scans/latest', [ScanController::class, 'latest'])->name('scans.latest');
    Route::middleware('can:manage-users')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', AdminUserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    });
});
