<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Sms\FakeSmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
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
    }
}
