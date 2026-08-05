<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\SubscriptionSchedulerLease;

/** Durable per-Subscription scheduler lease with an explicit memory test seam. */
final class SubscriptionSchedulerLeaseStore
{
    public const ERROR_HELD = 'subscription_scheduler_lease_held';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $leases = null;

    /** @var (\Closure(): SubscriptionSchedulerLease)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): SubscriptionSchedulerLease)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->leases = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @return array{ok:bool,token?:string,worker_id?:string,error?:string,version?:int,expires_at?:int}
     */
    public function acquire(
        string $subscriptionId,
        string $workerId,
        int $ttlSeconds = 30,
        ?int $now = null,
    ): array {
        $subscriptionId = trim($subscriptionId);
        $workerId = trim($workerId);
        $now ??= time();
        $ttlSeconds = max(1, $ttlSeconds);
        if ($subscriptionId === '' || strlen($subscriptionId) > 64
            || $workerId === '' || strlen($workerId) > 128
        ) {
            throw new \InvalidArgumentException(__('Subscription scheduler lease identity 非法'));
        }

        $current = $this->find($subscriptionId);
        if ($current !== null
            && (int) $current['expires_at'] > $now
            && (string) $current['worker_id'] !== $workerId
        ) {
            return $this->held($current);
        }

        $next = [
            'subscription_id' => $subscriptionId,
            'worker_id' => $workerId,
            'token' => bin2hex(random_bytes(32)),
            'version' => $current === null ? 1 : ((int) $current['version'] + 1),
            'expires_at' => $now + $ttlSeconds,
            'updated_at' => gmdate('Y-m-d H:i:s', $now),
        ];

        if ($this->leases !== null) {
            $this->leases[$subscriptionId] = $next;
            return $this->granted($next);
        }

        if ($current === null) {
            try {
                $this->newRecord()->clear()->setData($this->recordData($next))->save();
            } catch (Throwable $exception) {
                $winner = $this->find($subscriptionId);
                if ($winner !== null) {
                    return $this->held($winner);
                }
                throw $exception;
            }
        } else {
            $candidate = $this->newRecord()->clear();
            $candidate->getQuery(false)
                ->where(SubscriptionSchedulerLease::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
                ->where(SubscriptionSchedulerLease::schema_fields_VERSION, (int) $current['version'])
                ->where(SubscriptionSchedulerLease::schema_fields_TOKEN, (string) $current['token'])
                ->update([
                    SubscriptionSchedulerLease::schema_fields_WORKER_ID => $next['worker_id'],
                    SubscriptionSchedulerLease::schema_fields_TOKEN => $next['token'],
                    SubscriptionSchedulerLease::schema_fields_VERSION => $next['version'],
                    SubscriptionSchedulerLease::schema_fields_EXPIRES_AT_EPOCH => $next['expires_at'],
                    SubscriptionSchedulerLease::schema_fields_UPDATED_AT => $next['updated_at'],
                ])
                ->fetch();
        }

        $saved = $this->find($subscriptionId);
        if ($saved === null || !hash_equals((string) $next['token'], (string) $saved['token'])) {
            return $this->held($saved ?? [
                'worker_id' => '',
                'expires_at' => 0,
                'version' => 0,
            ]);
        }

        return $this->granted($saved);
    }

    public function release(string $subscriptionId, string $workerId, string $token): bool
    {
        $subscriptionId = trim($subscriptionId);
        $workerId = trim($workerId);
        $token = trim($token);
        $current = $this->find($subscriptionId);
        if ($current === null) {
            return true;
        }
        if ((string) $current['worker_id'] !== $workerId
            || !hash_equals((string) $current['token'], $token)
        ) {
            return false;
        }
        if ($this->leases !== null) {
            unset($this->leases[$subscriptionId]);
            return true;
        }

        $this->newRecord()->clear()
            ->where(SubscriptionSchedulerLease::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->where(SubscriptionSchedulerLease::schema_fields_WORKER_ID, $workerId)
            ->where(SubscriptionSchedulerLease::schema_fields_TOKEN, $token)
            ->delete()
            ->fetch();

        $saved = $this->find($subscriptionId);
        return $saved === null || !hash_equals($token, (string) $saved['token']);
    }

    public function holder(string $subscriptionId, ?int $now = null): ?string
    {
        $current = $this->find(trim($subscriptionId));
        $now ??= time();
        if ($current === null || (int) $current['expires_at'] <= $now) {
            return null;
        }
        return (string) $current['worker_id'];
    }

    /** @return array<string, mixed>|null */
    private function find(string $subscriptionId): ?array
    {
        if ($this->leases !== null) {
            return $this->leases[$subscriptionId] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(SubscriptionSchedulerLease::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        $row = $model->getData();
        return [
            'subscription_id' => (string) ($row[SubscriptionSchedulerLease::schema_fields_SUBSCRIPTION_ID] ?? ''),
            'worker_id' => (string) ($row[SubscriptionSchedulerLease::schema_fields_WORKER_ID] ?? ''),
            'token' => (string) ($row[SubscriptionSchedulerLease::schema_fields_TOKEN] ?? ''),
            'version' => (int) ($row[SubscriptionSchedulerLease::schema_fields_VERSION] ?? 0),
            'expires_at' => (int) ($row[SubscriptionSchedulerLease::schema_fields_EXPIRES_AT_EPOCH] ?? 0),
            'updated_at' => (string) ($row[SubscriptionSchedulerLease::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            SubscriptionSchedulerLease::schema_fields_SUBSCRIPTION_ID => $row['subscription_id'],
            SubscriptionSchedulerLease::schema_fields_WORKER_ID => $row['worker_id'],
            SubscriptionSchedulerLease::schema_fields_TOKEN => $row['token'],
            SubscriptionSchedulerLease::schema_fields_VERSION => $row['version'],
            SubscriptionSchedulerLease::schema_fields_EXPIRES_AT_EPOCH => $row['expires_at'],
            SubscriptionSchedulerLease::schema_fields_UPDATED_AT => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function granted(array $row): array
    {
        return [
            'ok' => true,
            'token' => (string) $row['token'],
            'worker_id' => (string) $row['worker_id'],
            'version' => (int) $row['version'],
            'expires_at' => (int) $row['expires_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function held(array $row): array
    {
        return [
            'ok' => false,
            'error' => self::ERROR_HELD,
            'worker_id' => (string) ($row['worker_id'] ?? ''),
            'version' => (int) ($row['version'] ?? 0),
            'expires_at' => (int) ($row['expires_at'] ?? 0),
        ];
    }

    private function newRecord(): SubscriptionSchedulerLease
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(SubscriptionSchedulerLease::class, [], false);
    }
}
