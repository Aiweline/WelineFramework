<?php

declare(strict_types=1);

namespace Weline\Payment\Queue;

use Weline\Payment\Service\PaymentIntentOrchestrator;

/**
 * Provider command outbox consumer（MOD-P2F-002）。
 * 事务外以 provider_request_key 调 Provider；第二事务 CAS 落响应。
 */
final class PaymentProviderCommandConsumer
{
    public function __construct(
        private readonly PaymentIntentOrchestrator $orchestrator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(int $limit = 20): array
    {
        return $this->orchestrator->processPendingOutbox($limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function runOne(string $commandCode): array
    {
        return $this->orchestrator->processOneOutbox($commandCode);
    }
}
