<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Operator-adjustable settings (allow-listed, bounded). Changes are audited.
 * Workers and the scheduler need a restart to pick a change up.
 *
 * @tags Admin - Settings
 */
class SettingsController extends Controller
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * List settings with defaults, current values and whether you may edit them.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->all($request->user())]);
    }

    /**
     * Change a setting. Requires step-up MFA. System-tier keys need a super administrator.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $request->validate(['value' => ['required']]);
        $this->settings->set($key, $request->input('value'), $request->user());

        return response()->json(['data' => collect($this->settings->all($request->user()))->firstWhere('key', $key)]);
    }

    /**
     * Reset a setting to its default.
     */
    public function destroy(Request $request, string $key): Response
    {
        $this->settings->reset($key, $request->user());

        return response()->noContent();
    }
}
