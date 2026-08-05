<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\SubscriptionMissedWatermark;

/** Durable monotonic missed-period watermark with an explicit memory test seam. */
final class SubscriptionMissedWatermarkStore
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $watermarks = null;

    /** @var list<array<string, mixed>> */
    private array $memoryEvents = [];

    /** @var (\Closure(): SubscriptionMissedWatermark)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): SubscriptionMissedWatermark)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->watermarks = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /** @return array<string, mixed> */
    public function record(
        string $subscriptionId,
        int $periodIndex,
        string $periodKey,
        string $reason,
    ): array {
        $subscriptionId = trim($subscriptionId);
        $periodKey = trim($periodKey);
        $reason = trim($reason);
        if ($subscriptionId === '' || strlen($subscriptionId) > 64
            || $periodIndex < 1
            || $periodKey === '' || strlen($periodKey) > 160
            || $reason === '' || strlen($reason) > 255
        ) {
            throw new \InvalidArgumentException(__('Subscription missed watermark identity 非法'));
        }

        $current = $this->find($subscriptionId);
        if ($current !== null && (int) $current['watermark'] >= $periodIndex) {
            return $current + ['replayed' => true];
        }
        $next = [
            'subscription_id' => $subscriptionId,
            'watermark' => $periodIndex,
            'period_index' => $periodIndex,
            'period_key' => $periodKey,
            'reason' => $reason,
            'version' => $current === null ? 1 : ((int) $current['version'] + 1),
            'cas_token' => bin2hex(random_bytes(32)),
            'at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($this->watermarks !== null) {
            $this->watermarks[$subscriptionId] = $next;
            $this->memoryEvents[] = $next;
            return $next;
        }

        if ($current === null) {
            try {
                $this->newRecord()->clear()->setData($this->recordData($next))->save();
            } catch (Throwable $exception) {
                $winner = $this->find($subscriptionId);
                if ($winner !== null && (int) $winner['watermark'] >= $periodIndex) {
                    return $winner + ['replayed' => true];
                }
                throw $exception;
            }
        } else {
            $candidate = $this->newRecord()->clear();
            $candidate->getQuery(false)
                ->where(SubscriptionMissedWatermark::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
                ->where(SubscriptionMissedWatermark::schema_fields_VERSION, (int) $current['version'])
                ->where(SubscriptionMissedWatermark::schema_fields_CAS_TOKEN, (string) $current['cas_token'])
                ->update($this->recordData($next))
                ->fetch();
        }

        $saved = $this->find($subscriptionId);
        if ($saved === null || !hash_equals((string) $next['cas_token'], (string) $saved['cas_token'])) {
            $winner = $this->find($subscriptionId);
            if ($winner !== null && (int) $winner['watermark'] >= $periodIndex) {
                return $winner + ['replayed' => true];
            }
            throw new SubscriptionConflictException(
                'subscription_missed_watermark_conflict',
                __('Subscription missed watermark 并发更新冲突'),
                ['subscription_id' => $subscriptionId, 'period_index' => $periodIndex],
            );
        }
        return $saved;
    }

    public function watermark(string $subscriptionId): int
    {
        return (int) ($this->find(trim($subscriptionId))['watermark'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        if ($this->watermarks !== null) {
            return $this->memoryEvents;
        }
        return array_map(
            fn (array $row): array => $this->normalize($row),
            $this->newRecord()->clear()->select()->fetchArray(),
        );
    }

    /** @return array<string, mixed>|null */
    private function find(string $subscriptionId): ?array
    {
        if ($this->watermarks !== null) {
            return $this->watermarks[$subscriptionId] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(SubscriptionMissedWatermark::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            SubscriptionMissedWatermark::schema_fields_SUBSCRIPTION_ID => $row['subscription_id'],
            SubscriptionMissedWatermark::schema_fields_PERIOD_INDEX => $row['watermark'],
            SubscriptionMissedWatermark::schema_fields_PERIOD_KEY => $row['period_key'],
            SubscriptionMissedWatermark::schema_fields_REASON => $row['reason'],
            SubscriptionMissedWatermark::schema_fields_VERSION => $row['version'],
            SubscriptionMissedWatermark::schema_fields_CAS_TOKEN => $row['cas_token'],
            SubscriptionMissedWatermark::schema_fields_UPDATED_AT => $row['at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $periodIndex = (int) ($row[SubscriptionMissedWatermark::schema_fields_PERIOD_INDEX] ?? 0);
        return [
            'subscription_id' => (string) ($row[SubscriptionMissedWatermark::schema_fields_SUBSCRIPTION_ID] ?? ''),
            'watermark' => $periodIndex,
            'period_index' => $periodIndex,
            'period_key' => (string) ($row[SubscriptionMissedWatermark::schema_fields_PERIOD_KEY] ?? ''),
            'reason' => (string) ($row[SubscriptionMissedWatermark::schema_fields_REASON] ?? ''),
            'version' => (int) ($row[SubscriptionMissedWatermark::schema_fields_VERSION] ?? 0),
            'cas_token' => (string) ($row[SubscriptionMissedWatermark::schema_fields_CAS_TOKEN] ?? ''),
            'at' => (string) ($row[SubscriptionMissedWatermark::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    private function newRecord(): SubscriptionMissedWatermark
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(SubscriptionMissedWatermark::class, [], false);
    }
}

