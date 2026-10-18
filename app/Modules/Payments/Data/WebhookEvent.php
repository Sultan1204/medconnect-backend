<?php

namespace App\Modules\Payments\Data;

/**
 * Provider-neutral webhook. Only the fields the platform needs are kept.
 */
final readonly class WebhookEvent
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const PAYMENT_FAILED = 'payment.failed';

    public const REFUND_SUCCEEDED = 'refund.succeeded';

    public const REFUND_FAILED = 'refund.failed';

    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $providerPaymentId,
        public ?string $providerRefundId,
        public ?int $amount,
        public ?string $currency,
        public ?string $failureCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
