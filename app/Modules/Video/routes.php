<?php

use App\Modules\Video\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::post('consultations/{consultation}/video-token', [VideoController::class, 'token'])
        ->whereUuid('consultation')
        ->middleware('throttle:video');
    Route::post('video-sessions/{session}/events', [VideoController::class, 'event'])->whereUuid('session');
});
