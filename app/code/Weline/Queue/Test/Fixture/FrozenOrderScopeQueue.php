<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Fixture;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\LegacyQueueScopeProviderInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Api\ScopedQueueConsumerInterface;

/**
 * TEST-MIG-P1A-03 fixture: an old handler recovers Scope from a frozen Order
 * snapshot embedded in the immutable Queue payload.
 */
final class FrozenOrderScopeQueue implements LegacyQueueScopeProviderInterface, ScopedQueueConsumerInterface
{
    public static function legacyScopeProducerKey(): string
    {
        return 'test.order.frozen_scope';
    }

    public static function recoverLegacyScopeEnvelope(array $queueRow): ?ScopeEnvelope
    {
        $payload = \json_decode((string)($queueRow['content'] ?? ''), true);
        $envelope = \is_array($payload) ? ($payload['frozen_order']['scope_envelope'] ?? null) : null;

        return \is_array($envelope) ? ScopeEnvelope::fromArray($envelope) : null;
    }

    public function name(): string
    {
        return 'TEST-MIG-P1A frozen Order consumer';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return 'TEST-MIG-P1A-03 only';
    }

    public function acceptedScopeKinds(): array
    {
        return [ScopeIdentity::KIND_STORE, ScopeIdentity::KIND_CHANNEL];
    }

    public function requiredScopeDimensions(): array
    {
        return ['website_id', 'website_code', 'store_code', 'store_mode'];
    }

    public function rejectedScopeMessage(ScopeEnvelope $envelope): string
    {
        return 'frozen_order_scope_rejected';
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $payload = \json_decode($queue->getContent(), true);
        if (!\is_array($payload)) {
            throw new \RuntimeException('frozen_order_payload_invalid');
        }
        $count = (int)($payload['business_effect_count'] ?? 0);
        if ($count > 0) {
            return 'MIG_P1A_ORDER_REPLAYED';
        }

        $payload['business_effect_count'] = 1;
        $queue->setContent((string)\json_encode(
            $payload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ));
        $queue->persist();

        return 'MIG_P1A_ORDER_CONSUMED';
    }
}
