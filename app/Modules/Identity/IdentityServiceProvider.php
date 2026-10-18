<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Models\AccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);

        // Credential endpoints: per IP, plus per identifier to slow targeted guessing.
        RateLimiter::for('auth', function (Request $request) {
            $identifier = mb_strtolower((string) ($request->input('identifier') ?? $request->input('phone') ?? ''));

            return [
                Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-id:'.sha1($identifier.'|'.$request->ip())),
            ];
        });

        // Endpoints that send SMS / email: limits cost and message flooding.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(30)->by('otp-ip-hour:'.$request->ip()),
        ]);
    }
}
