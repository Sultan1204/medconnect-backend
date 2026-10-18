<?php

namespace App\Modules\Payments;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\MockPaymentGateway;
use App\Modules\Payments\Listeners\RefundCancelledAppointment;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => match (config('medconnect.drivers.payment')) {
            'mock' => new MockPaymentGateway,
            // A licensed Saudi payment provider adapter is added here once contracted.
            default => throw new InvalidArgumentException('Unsupported payment driver.'),
        });
    }

    public function boot(): void
    {
        Event::listen(AppointmentCancelled::class, RefundCancelledAppointment::class);

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhooks:'.$request->ip()));
    }
}
