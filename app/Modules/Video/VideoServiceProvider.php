<?php

namespace App\Modules\Video;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Events\ConsultationStatusChanged;
use App\Modules\Video\Contracts\VideoProvider;
use App\Modules\Video\Providers\MockVideoProvider;
use App\Modules\Video\Services\VideoService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class VideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoProvider::class, fn () => match (config('medconnect.drivers.video')) {
            'mock' => new MockVideoProvider,
            // Add an adapter for an assessed WebRTC provider here.
            default => throw new InvalidArgumentException('Unsupported video driver.'),
        });
    }

    public function boot(): void
    {
        // Rooms close as soon as the consultation is no longer active.
        Event::listen(function (ConsultationStatusChanged $event) {
            if ($event->consultation->status !== ConsultationStatus::Active) {
                app(VideoService::class)->endFor($event->consultation);
            }
        });

        RateLimiter::for('video', fn (Request $request) => Limit::perMinute(20)->by('video:'.$request->user()?->getAuthIdentifier()));
    }
}
