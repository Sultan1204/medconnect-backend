<?php

namespace App\Modules\Messaging;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(30)->by('messages:'.$request->user()?->getAuthIdentifier()));
    }
}
