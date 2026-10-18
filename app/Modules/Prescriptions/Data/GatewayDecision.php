<?php

namespace App\Modules\Prescriptions\Data;

use Carbon\CarbonImmutable;

final readonly class GatewayDecision
{
    public const PENDING = 'pending';

    public const ISSUED = 'issued';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public string $status,
        public string $externalReference,
        public ?string $reason = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
