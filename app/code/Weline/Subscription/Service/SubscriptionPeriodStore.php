<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionState;

/** Durable unique period store with an explicit memory-only test seam. */
final class SubscriptionPeriodStore
{
    public const ERROR_EXISTS = 'subscription_period_exists';
    public const ERROR_NOT_FOUND = 'subscription_period_not_found';
    public const ERROR_CONCURRENT = 'subscription_period_concurrent_update';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $periods = null;

    /** @var array<string, string> */
    private array $bySubscriptionIndex = [];

    /** @var (\Closure(): SubscriptionPeriod)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): SubscriptionPeriod)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->periods = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @param array{
     *   subscription_id:string,
     *   period_index:int,
     *   period_key:string,
     *   website_id:int
     * } $input
     * @return array<string, mixed>
     */
    public function openPeriod(array $input): array
    {
        $periodKey = trim((string) ($input['period_key'] ?? ''));
        $subscriptionId = trim((string) ($input['subscription_id'] ?? ''));
        $periodIndex = (int) ($input['period_index'] ?? 0);
        $websiteId = (int) ($input['website_id'] ?? -1);
        SubscriptionState::assertWebsiteId($websiteId);
        if ($periodKey === '' || $subscriptionId === '' || $periodIndex < 1) {
            throw new \InvalidArgumentException(__('Period 缺少 subscription_id/period_key/period_index'));
        }
        if (strlen($periodKey) > 160 || strlen($subscriptionId) > 64) {
            throw new \InvalidArgumentException(__('SubscriptionPeriod identity 过长'));
        }

        $existing = $this->find($periodKey);
        if ($existing !== null) {
            return $this->assertReplay($existing, $subscriptionId, $periodIndex, $websiteId);
        }
        $existing = $this->findBySubscriptionIndex($subscriptionId, $periodIndex);
        if ($existing !== null) {
            throw $this->periodTaken($existing);
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'period_key' => $periodKey,
            'subscription_id' => $subscriptionId,
            'period_index' => $periodIndex,
            'website_id' => $websiteId,
            'status' => SubscriptionState::PERIOD_OPEN,
            'period_version' => 1,
            'cas_token' => bin2hex(random_bytes(32)),
            'order_ref' => null,
            'missed_reason' => null,
            'opened_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->periods !== null) {
            $this->periods[$periodKey] = $row;
            $this->bySubscriptionIndex[$this->subscriptionIndexKey($subscriptionId, $periodIndex)] = $periodKey;
            return $row;
        }

        try {
            $this->newRecord()->clear()->setData($this->recordData($row))->save();
        } catch (Throwable $exception) {
            try {
                $winner = $this->find($periodKey);
                if ($winner !== null) {
                    return $this->assertReplay(
                        $winner,
                        $subscriptionId,
                        $periodIndex,
                        $websiteId,
                        $exception,
                    );
                }
                $winner = $this->findBySubscriptionIndex($subscriptionId, $periodIndex);
                if ($winner !== null) {
                    throw $this->periodTaken($winner, $exception);
                }
            } catch (SubscriptionConflictException $conflict) {
                throw $conflict;
            } catch (Throwable) {
            }
            throw $exception;
        }

        return $this->getByKey($periodKey);
    }

    /** @return array<string, mixed> */
    public function getByKey(string $periodKey): array
    {
        $periodKey = trim($periodKey);
        $row = $this->find($periodKey);
        if ($row === null) {
            throw new SubscriptionConflictException(
                self::ERROR_NOT_FOUND,
                __('Period 不存在：%{1}', [$periodKey]),
                ['period_key' => $periodKey],
            );
        }
        return $row;
    }

    public function count(): int
    {
        if ($this->periods !== null) {
            return count($this->periods);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string, mixed> */
    public function attachOrder(string $periodKey, string $orderRef): array
    {
        $orderRef = trim($orderRef);
        if ($orderRef === '' || strlen($orderRef) > 64) {
            throw new \InvalidArgumentException(__('SubscriptionPeriod Order reference 非法'));
        }
        $current = $this->getByKey($periodKey);
        if ((string) ($current['order_ref'] ?? '') !== '') {
            if (hash_equals((string) $current['order_ref'], $orderRef)) {
                return $current;
            }
            throw new SubscriptionConflictException(
                'subscription_period_order_conflict',
                __('Period 已绑定不同 Order：%{1}', [$periodKey]),
                ['period_key' => $periodKey, 'order_ref' => $current['order_ref'], 'requested' => $orderRef],
            );
        }
        return $this->casUpdate($current, [
            'status' => SubscriptionState::PERIOD_BILLED,
            'order_ref' => $orderRef,
            'missed_reason' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public function markMissed(string $periodKey, string $reason = 'missed'): array
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255) {
            throw new \InvalidArgumentException(__('SubscriptionPeriod missed reason 非法'));
        }
        $current = $this->getByKey($periodKey);
        if ((string) $current['status'] === SubscriptionState::PERIOD_BILLED) {
            throw new SubscriptionConflictException(
                'subscription_period_already_billed',
                __('已出账 Period 不能标记 missed：%{1}', [$periodKey]),
                ['period_key' => $periodKey],
            );
        }
        if ((string) $current['status'] === SubscriptionState::PERIOD_MISSED
            && (string) ($current['missed_reason'] ?? '') === $reason
        ) {
            return $current;
        }
        return $this->casUpdate($current, [
            'status' => SubscriptionState::PERIOD_MISSED,
            'missed_reason' => $reason,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listForSubscription(string $subscriptionId): array
    {
        $subscriptionId = trim($subscriptionId);
        if ($this->periods !== null) {
            $rows = array_values(array_filter(
                $this->periods,
                static fn (array $row): bool => (string) $row['subscription_id'] === $subscriptionId,
            ));
        } else {
            $rows = array_map(
                fn (array $row): array => $this->normalize($row),
                $this->newRecord()->clear()
                    ->where(SubscriptionPeriod::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
                    ->select()
                    ->fetchArray(),
            );
        }
        usort($rows, static fn (array $a, array $b): int => ((int) $a['period_index']) <=> ((int) $b['period_index']));
        return $rows;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function casUpdate(array $current, array $patch): array
    {
        $periodKey = (string) $current['period_key'];
        $next = $current;
        foreach (['status', 'order_ref', 'missed_reason'] as $field) {
            if (array_key_exists($field, $patch)) {
                $next[$field] = $patch[$field];
            }
        }
        $next['status'] = SubscriptionState::assertPeriodStatus((string) $next['status']);
        $next['period_version'] = (int) $current['period_version'] + 1;
        $next['cas_token'] = bin2hex(random_bytes(32));
        $next['updated_at'] = gmdate('Y-m-d H:i:s');

        if ($this->periods !== null) {
            $this->periods[$periodKey] = $next;
            return $next;
        }

        $candidate = $this->newRecord()->clear();
        $candidate->getQuery(false)
            ->where(SubscriptionPeriod::schema_fields_PERIOD_KEY, $periodKey)
            ->where(SubscriptionPeriod::schema_fields_VERSION, (int) $current['period_version'])
            ->where(SubscriptionPeriod::schema_fields_CAS_TOKEN, (string) $current['cas_token'])
            ->update([
                SubscriptionPeriod::schema_fields_STATUS => $next['status'],
                SubscriptionPeriod::schema_fields_ORDER_REF => $next['order_ref'],
                SubscriptionPeriod::schema_fields_MISSED_REASON => $next['missed_reason'],
                SubscriptionPeriod::schema_fields_VERSION => $next['period_version'],
                SubscriptionPeriod::schema_fields_CAS_TOKEN => $next['cas_token'],
                SubscriptionPeriod::schema_fields_UPDATED_AT => $next['updated_at'],
            ])
            ->fetch();

        $saved = $this->getByKey($periodKey);
        if (!hash_equals((string) $next['cas_token'], (string) $saved['cas_token'])) {
            throw new SubscriptionConflictException(
                self::ERROR_CONCURRENT,
                __('SubscriptionPeriod 并发更新冲突'),
                ['period_key' => $periodKey, 'actual_version' => $saved['period_version']],
            );
        }
        return $saved;
    }

    /** @return array<string, mixed>|null */
    private function find(string $periodKey): ?array
    {
        if ($this->periods !== null) {
            return $this->periods[$periodKey] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(SubscriptionPeriod::schema_fields_PERIOD_KEY, $periodKey)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findBySubscriptionIndex(string $subscriptionId, int $periodIndex): ?array
    {
        if ($this->periods !== null) {
            $key = $this->bySubscriptionIndex[$this->subscriptionIndexKey($subscriptionId, $periodIndex)] ?? null;
            return $key !== null ? ($this->periods[$key] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(SubscriptionPeriod::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->where(SubscriptionPeriod::schema_fields_PERIOD_INDEX, $periodIndex)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function assertReplay(
        array $existing,
        string $subscriptionId,
        int $periodIndex,
        int $websiteId,
        ?Throwable $previous = null,
    ): array {
        if ((string) $existing['subscription_id'] !== $subscriptionId
            || (int) $existing['period_index'] !== $periodIndex
            || (int) $existing['website_id'] !== $websiteId
        ) {
            throw $this->periodTaken($existing, $previous);
        }
        return $existing + ['replayed' => true];
    }

    /** @param array<string, mixed> $existing */
    private function periodTaken(array $existing, ?Throwable $previous = null): SubscriptionConflictException
    {
        return new SubscriptionConflictException(
            self::ERROR_EXISTS,
            __('SubscriptionPeriod identity 已占用：%{1}', [$existing['period_key'] ?? '']),
            [
                'period_key' => $existing['period_key'] ?? null,
                'subscription_id' => $existing['subscription_id'] ?? null,
                'period_index' => $existing['period_index'] ?? null,
            ],
            0,
            $previous,
        );
    }

    private function subscriptionIndexKey(string $subscriptionId, int $periodIndex): string
    {
        return $subscriptionId . '|' . $periodIndex;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            SubscriptionPeriod::schema_fields_PERIOD_KEY => $row['period_key'],
            SubscriptionPeriod::schema_fields_SUBSCRIPTION_ID => $row['subscription_id'],
            SubscriptionPeriod::schema_fields_PERIOD_INDEX => $row['period_index'],
            SubscriptionPeriod::schema_fields_WEBSITE_ID => $row['website_id'],
            SubscriptionPeriod::schema_fields_STATUS => $row['status'],
            SubscriptionPeriod::schema_fields_VERSION => $row['period_version'],
            SubscriptionPeriod::schema_fields_CAS_TOKEN => $row['cas_token'],
            SubscriptionPeriod::schema_fields_ORDER_REF => $row['order_ref'],
            SubscriptionPeriod::schema_fields_MISSED_REASON => $row['missed_reason'],
            SubscriptionPeriod::schema_fields_OPENED_AT => $row['opened_at'],
            SubscriptionPeriod::schema_fields_UPDATED_AT => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $row['period_index'] = (int) ($row['period_index'] ?? 0);
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['period_version'] = (int) ($row['period_version'] ?? 0);
        return $row;
    }

    private function newRecord(): SubscriptionPeriod
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(SubscriptionPeriod::class, [], false);
    }
}
