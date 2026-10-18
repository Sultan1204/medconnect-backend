<?php

namespace App\Modules\Settlements;

use App\Modules\Consultations\Events\ConsultationCompleted;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Settlements\Services\Ledger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SettlementsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(fn (ConsultationCompleted $event) => app(Ledger::class)->recordEarning($event->consultation->appointment));
        Event::listen(fn (RefundSucceeded $event) => app(Ledger::class)->recordRefund($event->refund));
    }
}
