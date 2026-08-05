<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

use Weline\Payment\Api\Data\PaymentEffectRecord;

/**
 * Public, transaction-owning boundary for durable Payment effects.
 *
 * The handler runs inside the same default-connector write transaction that
 * locks the Payment outbox row. Throwing rolls back both the downstream
 * database effect and the outbox terminal state.
 */
interface PaymentEffectOutboxProcessorInterface
{
    /**
     * @param list<string> $effectTypes
     * @return list<string>
     */
    public function pendingCodes(array $effectTypes, int $limit = 20): array;

    /**
     * @param callable(PaymentEffectRecord): array<string, mixed> $handler
     * @return array<string, mixed>
     */
    public function process(string $outboxCode, callable $handler): array;
}
