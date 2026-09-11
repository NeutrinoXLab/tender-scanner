<?php

use App\Http\Controllers\NoticeController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/notices', [NoticeController::class, 'index'])
    ->name('notices.index');

Route::get('/notices/{notice}', [NoticeController::class, 'show'])
    ->name('notices.show');

Route::get('/', function () {
    return redirect()->route('notices.index');
});

Route::post('/scans', [ScanController::class, 'store'])->middleware('throttle:10,1')->name('scans.store');
Route::get('/scans/latest', [ScanController::class, 'latest'])->name('scans.latest');
