<?php

use App\Support\Files\PrivateFileController;
use App\Support\Health\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  (prefix /api/v1 - see bootstrap/app.php)
|--------------------------------------------------------------------------
| Each module owns its routes in app/Modules/<Module>/routes.php.
*/

Route::get('health', HealthController::class)->name('health');

// Short-lived signed download links for private files (see PrivateFileStore).
Route::get('files/{token}', PrivateFileController::class)->middleware('signed')->name('private-files.show');

foreach (config('modules.routes', []) as $routeFile) {
    require base_path($routeFile);
}
