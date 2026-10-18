<?php

use App\Modules\Messaging\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])
    ->prefix('consultations/{consultation}')
    ->whereUuid(['consultation', 'attachment'])
    ->group(function () {
        Route::get('messages', [MessageController::class, 'index']);
        Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:messages');
        Route::post('messages/ack', [MessageController::class, 'acknowledge']);
        Route::get('attachments/{attachment}', [MessageController::class, 'attachment']);
    });
