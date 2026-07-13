<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/** Immutable cross-module projection of a payment transaction. */
final readonly class PaymentTransactionRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        public int|string $id,
        public string $transactionNumber,
        public string $methodCode,
        public string $status,
        public array $response,
    ) {
    }
}
