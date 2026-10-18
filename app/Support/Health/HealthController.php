<?php

namespace App\Support\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness probe: verifies the dependencies a request needs.
 * Reports only pass/fail per dependency - never versions, hosts or errors.
 *
 * @tags Health
 */
class HealthController
{
    /**
     * Readiness check.
     *
     * Returns 200 when the database and cache are reachable, otherwise 503.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::pull($key) === 'ok';
                throw_unless($ok, new \RuntimeException('cache round-trip failed'));
            }),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
