<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferenceController;
use App\Modules\Notifications\Http\Controllers\PushDeviceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification');

    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

    Route::post('push-devices', [PushDeviceController::class, 'store']);
    Route::delete('push-devices/{device}', [PushDeviceController::class, 'destroy'])->whereUuid('device');
});
