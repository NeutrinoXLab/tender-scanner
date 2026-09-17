<?php

use App\Http\Controllers\SeapWorkerController;
use App\Http\Middleware\AuthenticateSeapWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('seap-worker')->middleware([AuthenticateSeapWorker::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/ping', [SeapWorkerController::class, 'ping']);
    Route::post('/claim', [SeapWorkerController::class, 'claim']);
    Route::post('/batches/{batch}', [SeapWorkerController::class, 'store'])->whereUuid('batch');
});
