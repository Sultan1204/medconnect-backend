<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own account. Never use for other users' data.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'phone_verified' => $this->phone_verified_at !== null,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'preferred_locale' => $this->preferred_locale,
            'status' => $this->status->value,
            'roles' => $this->getRoleNames()->values(),
            'mfa' => [
                'enabled' => $this->hasMfaEnabled(),
                'required' => $this->requiresMfa(),
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
