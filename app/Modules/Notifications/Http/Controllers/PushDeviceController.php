<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\RegisterPushDeviceRequest;
use App\Modules\Notifications\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Push notification device registration.
 *
 * @tags Notifications - Push devices
 */
class PushDeviceController extends Controller
{
    /**
     * Register (or refresh) a device for push notifications.
     *
     * A token already registered to another account moves to the current user:
     * a shared phone only ever notifies whoever last signed in on it.
     */
    public function store(RegisterPushDeviceRequest $request): JsonResponse
    {
        $token = $request->validated('token');

        $device = PushDevice::updateOrCreate(
            ['token_hash' => PushDevice::hashToken($token)],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->validated('platform'),
                'token' => $token,
                'device_name' => $request->validated('device_name'),
            ],
        );

        return response()->json(['data' => [
            'id' => $device->id,
            'platform' => $device->platform,
            'device_name' => $device->device_name,
        ]], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Unregister a device (e.g. on sign-out).
     */
    public function destroy(Request $request, string $device): Response
    {
        PushDevice::where('user_id', $request->user()->id)->findOrFail($device)->delete();

        return response()->noContent();
    }
}
