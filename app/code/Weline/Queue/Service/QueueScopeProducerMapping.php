<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\LegacyQueueScopeProviderInterface;
use Weline\Queue\Queue\AsyncEventDeliveryQueue;
use Weline\Queue\Queue\R\Test as NestedQueueTest;
use Weline\Queue\Queue\Test as QueueTest;

/**
 * P1B-002：冻结的 Queue producer → Scope 映射契约。
 *
 * 只允许唯一可判定的生产者映成 kind；其余 unfinished 遗留行必须 quarantine，
 * 禁止猜成零号站 / default channel。
 */
final class QueueScopeProducerMapping
{
    public const DECISION_MAP = 'map';
    public const DECISION_QUARANTINE = 'quarantine';
    public const DECISION_CANCELLED = 'cancelled';

    public const QUARANTINE_RESULT_PREFIX = 'SCOPE_QUARANTINE:';

    /**
     * @return list<array{
     *   producer_key:string,
     *   classes:list<string>,
     *   module:string,
     *   kind:string,
     *   reason:string
     * }>
     */
    public function frozenContracts(): array
    {
        return [
            [
                'producer_key' => 'queue.async_event_delivery',
                'classes' => [AsyncEventDeliveryQueue::class],
                'module' => 'Weline_Queue',
                'kind' => ScopeIdentity::KIND_GLOBAL,
                'reason' => 'Framework async Delivery Transport 无站点载荷，确定性 global',
            ],
            [
                'producer_key' => 'queue.builtin_test',
                'classes' => [QueueTest::class, NestedQueueTest::class],
                'module' => 'Weline_Queue',
                'kind' => ScopeIdentity::KIND_GLOBAL,
                'reason' => '内置测试队列确定性 global',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row Queue 行（至少含 status/finished/result/module）
     * @return array{
     *   decision:string,
     *   producer_key:?string,
     *   kind:?string,
     *   envelope:?array<string,mixed>,
     *   reason:string
     * }
     */
    public function classify(array $row, ?string $typeClass): array
    {
        if ($this->isAlreadyQuarantined($row)) {
            return [
                'decision' => self::DECISION_QUARANTINE,
                'producer_key' => null,
                'kind' => null,
                'envelope' => null,
                'reason' => 'already_quarantined',
            ];
        }

        if ($this->isTerminalNonConsumable($row)) {
            return [
                'decision' => self::DECISION_CANCELLED,
                'producer_key' => null,
                'kind' => null,
                'envelope' => null,
                'reason' => 'terminal_non_consumable',
            ];
        }

        $typeClass = $typeClass !== null ? \trim($typeClass) : '';
        if ($typeClass === '') {
            return [
                'decision' => self::DECISION_QUARANTINE,
                'producer_key' => null,
                'kind' => null,
                'envelope' => null,
                'reason' => 'missing_type_class',
            ];
        }

        if (\is_a($typeClass, LegacyQueueScopeProviderInterface::class, true)) {
            try {
                /** @var class-string<LegacyQueueScopeProviderInterface> $typeClass */
                $envelope = $typeClass::recoverLegacyScopeEnvelope($row);
                $producerKey = \trim($typeClass::legacyScopeProducerKey());
            } catch (\Throwable) {
                $envelope = null;
                $producerKey = '';
            }
            if (!$envelope instanceof ScopeEnvelope || $producerKey === '') {
                return [
                    'decision' => self::DECISION_QUARANTINE,
                    'producer_key' => null,
                    'kind' => null,
                    'envelope' => null,
                    'reason' => 'declared_scope_provider_unresolved:' . $typeClass,
                ];
            }

            return [
                'decision' => self::DECISION_MAP,
                'producer_key' => $producerKey,
                'kind' => $envelope->scope->scopeKind,
                'envelope' => $envelope->toArray(),
                'reason' => 'handler 从冻结聚合快照确定性恢复 Scope',
            ];
        }

        foreach ($this->frozenContracts() as $contract) {
            if (!\in_array($typeClass, $contract['classes'], true)) {
                continue;
            }
            $envelope = ScopeEnvelope::of(ScopeIdentity::global())->toArray();

            return [
                'decision' => self::DECISION_MAP,
                'producer_key' => $contract['producer_key'],
                'kind' => $contract['kind'],
                'envelope' => $envelope,
                'reason' => $contract['reason'],
            ];
        }

        return [
            'decision' => self::DECISION_QUARANTINE,
            'producer_key' => null,
            'kind' => null,
            'envelope' => null,
            'reason' => 'no_frozen_producer_contract:' . $typeClass,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isAlreadyQuarantined(array $row): bool
    {
        $result = (string)($row['result'] ?? '');

        return \str_starts_with($result, self::QUARANTINE_RESULT_PREFIX);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isTerminalNonConsumable(array $row): bool
    {
        if ((int)($row['finished'] ?? 0) === 1) {
            return true;
        }
        $status = (string)($row['status'] ?? '');

        return $status === 'done' || $status === 'stop';
    }

    public function quarantineMessage(string $reason): string
    {
        $safe = \preg_replace('/[^\x20-\x7E\x{4e00}-\x{9fff}]/u', '', $reason) ?? 'unknown';

        return self::QUARANTINE_RESULT_PREFIX . ' ' . \trim($safe);
    }
}
