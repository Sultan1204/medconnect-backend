<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Reviews\Http\Controllers\Admin\ReviewAdminController;
use App\Modules\Reviews\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Public: reviews and rating summary of a listed doctor.
Route::get('doctors/{doctor}/reviews', [ReviewController::class, 'forDoctor'])->whereUuid('doctor');
Route::get('doctors/{doctor}/reviews/summary', [ReviewController::class, 'summary'])->whereUuid('doctor');

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::get('doctors/me/reviews', [ReviewController::class, 'mineAsDoctor'])->middleware('role:'.RoleName::Doctor->value);

    Route::middleware('role:'.RoleName::Patient->value)->group(function () {
        Route::post('consultations/{consultation}/review', [ReviewController::class, 'store'])->whereUuid('consultation')->middleware('throttle:10,1');
        Route::get('reviews/mine', [ReviewController::class, 'mine']);
    });

    Route::post('reviews/{review}/report', [ReviewController::class, 'report'])->whereUuid('review')->middleware('throttle:20,1');

    Route::middleware('permission:'.Permission::ReviewsModerate->value)->prefix('admin')->group(function () {
        Route::get('reviews', [ReviewAdminController::class, 'index']);
        Route::post('reviews/{review}/moderate', [ReviewAdminController::class, 'moderate'])->whereUuid('review');
        Route::get('review-reports', [ReviewAdminController::class, 'reports']);
        Route::post('review-reports/{report}/resolve', [ReviewAdminController::class, 'resolveReport'])->whereUuid('report');
    });
});
