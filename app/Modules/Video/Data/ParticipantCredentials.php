<?php

namespace App\Modules\Video\Data;

use Carbon\CarbonImmutable;

final readonly class ParticipantCredentials
{
    /**
     * @param  array<string, mixed>  $connection  provider-specific join data (room, URL, ICE servers...)
     */
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
        public array $connection,
    ) {}
}
