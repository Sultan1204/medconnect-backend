<?php

namespace App\Providers;

use App\Support\Files\Contracts\FileScanner;
use App\Support\Files\MockFileScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileScanner::class, fn () => match (config('medconnect.drivers.file_scanner')) {
            'mock' => new MockFileScanner,
            // A ClamAV / cloud scanning adapter is required before production.
            default => throw new InvalidArgumentException('Unsupported file scanner driver.'),
        });
    }

    public function boot(): void
    {
        // Stable short names in polymorphic columns instead of PHP class names.
        Relation::enforceMorphMap(config('morph_map'));

        // Fail loudly on N+1 lazy loading and silently discarded attributes outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(10)->max(128)->letters()->numbers();

            // Breached-password check (k-anonymity API) only where outbound calls are expected.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
