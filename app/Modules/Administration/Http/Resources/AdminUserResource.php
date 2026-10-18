<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Account summary for administrators. Contact details are masked.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone ? PhoneNumber::mask($this->phone) : null,
            'email' => $this->email ? self::maskEmail($this->email) : null,
            'roles' => $this->roles->pluck('name')->values(),
            'status' => $this->status->value,
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'mfa_enabled' => $this->hasMfaEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
