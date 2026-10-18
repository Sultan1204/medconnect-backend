<?php

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Payments\Http\Controllers\Admin\PaymentAdminController;
use App\Modules\Payments\Http\Controllers\MockCheckoutController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Provider webhooks: signature-authenticated, separately rate limited.
Route::post('webhooks/payments', WebhookController::class)
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:webhooks');

Route::middleware(['auth:sanctum', 'account.active', 'token.full'])->group(function () {
    Route::post('payments/checkout', [PaymentController::class, 'checkout'])->middleware('role:'.RoleName::Patient->value);
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereUuid('payment');

    Route::prefix('admin/payments')->whereUuid('payment')->group(function () {
        Route::middleware('permission:'.Permission::PaymentsView->value)->group(function () {
            Route::get('/', [PaymentAdminController::class, 'index']);
            Route::get('{payment}', [PaymentAdminController::class, 'show']);
        });
        Route::post('{payment}/refunds', [PaymentAdminController::class, 'refund'])
            ->middleware(['permission:'.Permission::PaymentsRefund->value, 'mfa.step-up']);
    });
});

// Development only: a stand-in for the provider's hosted checkout page.
if (! app()->isProduction() && config('medconnect.drivers.payment') === 'mock') {
    Route::get('dev/mock-payments/{payment}', [MockCheckoutController::class, 'show'])->whereUuid('payment');
    Route::post('dev/mock-payments/{payment}', [MockCheckoutController::class, 'complete'])->whereUuid('payment');
}
