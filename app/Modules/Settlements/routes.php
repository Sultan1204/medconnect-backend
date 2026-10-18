<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Settlements\Http\Controllers\Admin\SettlementAdminController;
use App\Modules\Settlements\Http\Controllers\EarningsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::middleware('role:'.RoleName::Doctor->value)->prefix('doctors/me')->group(function () {
        Route::get('earnings', [EarningsController::class, 'index']);
        Route::get('payouts', [EarningsController::class, 'payouts']);
    });

    Route::middleware('permission:'.Permission::SettlementsManage->value)->prefix('admin')->group(function () {
        Route::get('settlements/balances', [SettlementAdminController::class, 'balances']);

        Route::middleware('mfa.step-up')->group(function () {
            Route::post('payouts', [SettlementAdminController::class, 'store']);
            Route::post('payouts/{payout}/execute', [SettlementAdminController::class, 'execute'])->whereUuid('payout');
        });
        Route::post('payouts/{payout}/cancel', [SettlementAdminController::class, 'cancel'])->whereUuid('payout');
    });
});
