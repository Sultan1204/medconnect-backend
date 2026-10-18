<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Specialties\Http\Controllers\SpecialtyController;
use Illuminate\Support\Facades\Route;

Route::get('specialties', [SpecialtyController::class, 'index']);

Route::middleware(['auth:sanctum', 'account.active', 'token.full', 'permission:'.Permission::SpecialtiesManage->value])
    ->prefix('admin/specialties')
    ->group(function () {
        Route::get('/', [SpecialtyController::class, 'adminIndex']);
        Route::post('/', [SpecialtyController::class, 'store']);
        Route::patch('{specialty}', [SpecialtyController::class, 'update'])->whereUuid('specialty');
    });
