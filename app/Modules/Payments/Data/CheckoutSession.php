<?php

namespace App\Modules\Payments\Data;

use Carbon\CarbonImmutable;

final readonly class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $clientData  provider-specific data for the client SDK
     */
    public function __construct(
        public string $providerPaymentId,
        public ?string $checkoutUrl,
        public CarbonImmutable $expiresAt,
        public array $clientData = [],
    ) {}
}
