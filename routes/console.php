<?php

use App\Modules\Identity\Models\OtpCode;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled maintenance (run by the `scheduler` container: schedule:work).
| Times are Asia/Riyadh.
*/

Schedule::command('appointments:expire-unpaid')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('consultations:enforce-time-limits')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('prescriptions:expire')->hourly()->onOneServer();

Schedule::command('doctors:expire-licenses')->dailyAt('00:15')->timezone('Asia/Riyadh')->onOneServer();

// Expired bearer tokens (keeps the device list accurate).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// One-time codes are useless after expiry; keep a day for abuse investigation.
Schedule::command('model:prune', ['--model' => [OtpCode::class]])->hourly()->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
