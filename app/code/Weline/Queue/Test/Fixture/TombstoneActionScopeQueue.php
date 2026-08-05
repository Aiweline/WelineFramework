<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Fixture;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Service\TombstoneHistoricalResourcePolicy;
use Weline\Queue\Api\LegacyQueueScopeProviderInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Api\ScopedQueueConsumerInterface;

/**
 * TEST-MIG-P1A-06 fixture for tombstone historical obligations.
 */
final class TombstoneActionScopeQueue implements LegacyQueueScopeProviderInterface, ScopedQueueConsumerInterface
{
    private static ?TombstoneHistoricalResourcePolicy $policy = null;

    public static function legacyScopeProducerKey(): string
    {
        return 'test.order.tombstone_action';
    }

    public static function recoverLegacyScopeEnvelope(array $queueRow): ?ScopeEnvelope
    {
        $payload = \json_decode((string)($queueRow['content'] ?? ''), true);
        $envelope = \is_array($payload) ? ($payload['scope_envelope'] ?? null) : null;

        return \is_array($envelope) ? ScopeEnvelope::fromArray($envelope) : null;
    }

    public function name(): string
    {
        return 'TEST-MIG-P1A tombstone action consumer';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return 'TEST-MIG-P1A-06 only';
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
        return 'tombstone_action_scope_rejected';
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $payload = \json_decode($queue->getContent(), true);
        if (!\is_array($payload)) {
            throw new \RuntimeException('tombstone_action_payload_invalid');
        }
        $scope = $queue->getScopeEnvelope()?->scope;
        $storeCode = $scope?->storeCode ?? '';
        $action = \trim((string)($payload['action'] ?? ''));
        if ($storeCode === '' || $action === '') {
            throw new \RuntimeException('tombstone_action_context_incomplete');
        }

        $policy = self::$policy ??= TombstoneHistoricalResourcePolicy::forTesting();
        if ($policy->store($storeCode) === []) {
            $policy->registerStore($storeCode);
            $policy->tombstone($storeCode);
        }
        $decision = $policy->assertAllowed($storeCode, $action);
        if (empty($decision['ok'])) {
            throw new \RuntimeException(
                (string)($decision['error_code'] ?? TombstoneHistoricalResourcePolicy::ERROR_DENIED)
                . '|urgent=' . \count($policy->urgent())
            );
        }

        return (string)\json_encode([
            'allowed' => true,
            'action' => $action,
            'resource_mode' => $decision['resource_mode'] ?? null,
            'urgent' => \count($policy->urgent()),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
