<?php

namespace App\Modules\Payments\Data;

final readonly class RefundResult
{
    /**
     * @param  string  $status  pending (confirmed later by webhook) | succeeded | failed
     */
    public function __construct(
        public string $providerRefundId,
        public string $status,
        public ?string $failureCode = null,
    ) {}
}
