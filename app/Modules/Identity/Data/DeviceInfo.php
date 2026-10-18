<?php

namespace App\Modules\Identity\Data;

use Illuminate\Http\Request;

/**
 * Client device description attached to each issued token.
 */
final readonly class DeviceInfo
{
    public function __construct(
        public string $name,
        public ?string $deviceId,
        public ?string $platform,
        public ?string $ip,
        public ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: mb_substr((string) ($request->input('device_name') ?: 'Unknown device'), 0, 100),
            deviceId: $request->filled('device_id') ? mb_substr((string) $request->input('device_id'), 0, 100) : null,
            platform: $request->input('platform'),
            ip: $request->ip(),
            userAgent: mb_substr((string) $request->userAgent(), 0, 500) ?: null,
        );
    }

    /**
     * Validation rules shared by every endpoint that issues tokens.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'platform' => ['sometimes', 'nullable', 'in:web,android,ios'],
        ];
    }
}
