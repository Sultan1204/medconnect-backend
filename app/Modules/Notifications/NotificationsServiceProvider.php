<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Console\SendAppointmentReminders;
use App\Modules\Notifications\Contracts\PushProvider;
use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Listeners\NotificationEventSubscriber;
use App\Modules\Notifications\Push\FakePushProvider;
use App\Modules\Notifications\Push\LogPushProvider;
use App\Modules\Notifications\Sms\FakeSmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, fn () => match (config('medconnect.drivers.sms')) {
            'log' => new LogSmsProvider,
            'fake' => new FakeSmsProvider,
            // A real Saudi SMS gateway adapter is added here once contracted
            // (sender ID registration with CST is required).
            default => throw new InvalidArgumentException('Unsupported SMS driver ['.config('medconnect.drivers.sms').'].'),
        });

        $this->app->singleton(PushProvider::class, fn () => match (config('medconnect.drivers.push')) {
            'log' => new LogPushProvider,
            'fake' => new FakePushProvider,
            // An FCM/APNs adapter is added here once credentials and a privacy
            // assessment of the push provider are in place.
            default => throw new InvalidArgumentException('Unsupported push driver ['.config('medconnect.drivers.push').'].'),
        });
    }

    public function boot(): void
    {
        Event::subscribe(NotificationEventSubscriber::class);

        $this->commands([SendAppointmentReminders::class]);
    }
}
