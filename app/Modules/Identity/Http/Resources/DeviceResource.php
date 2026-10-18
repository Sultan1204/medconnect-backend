<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\AccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessToken
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'platform' => $this->platform,
            'ip_address' => $this->ip_address,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'is_current' => $current instanceof AccessToken && $current->is($this->resource),
        ];
    }
}
