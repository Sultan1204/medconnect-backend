<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\DeviceResource;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Signed-in devices (one per active token).
 *
 * @tags Authentication - Devices
 */
class DeviceController extends Controller
{
    /**
     * List signed-in devices.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $devices = $user->tokens()
            ->where('abilities', 'like', '%"*"%')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('last_used_at')
            ->get();

        return DeviceResource::collection($devices);
    }

    /**
     * Sign out a specific device.
     */
    public function destroy(Request $request, string $device, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var AccessToken $token */
        $token = $user->tokens()->where('public_id', $device)->firstOrFail();
        $token->delete();

        $audit->record('auth.device_revoked', $user, ['metadata' => ['device' => $token->public_id]]);

        return response()->json(['data' => ['status' => 'revoked']]);
    }
}
