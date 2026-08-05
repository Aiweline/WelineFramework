<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\Subscription;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Durable Subscription aggregate store with an explicit memory-only test seam.
 */
final class SubscriptionStore
{
    public const ERROR_EXISTS = 'subscription_already_exists';
    public const ERROR_NOT_FOUND = 'subscription_not_found';
    public const ERROR_IDEMPOTENCY = 'subscription_idempotency_conflict';
    public const ERROR_VERSION = 'subscription_version_conflict';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    /** @var array<string, string> */
    private array $byIdempotency = [];

    /** @var array<string, string> */
    private array $byOwnerPlan = [];

    /** @var (\Closure(): Subscription)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): Subscription)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    public function isMemory(): bool
    {
        return $this->rows !== null;
    }

    public function connection(): ConnectionFactory
    {
        return $this->newRecord()->getConnection();
    }

    /**
     * @param array{
     *   subscription_id?:string,
     *   customer_id:string,
     *   website_id:int,
     *   store_id?:int,
     *   provider_code:string,
     *   plan_code:string,
     *   idempotency_key:string,
     *   environment?:string
     * } $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        $planCode = trim((string) ($input['plan_code'] ?? ''));
        $providerCode = trim((string) ($input['provider_code'] ?? ''));
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        $environment = SubscriptionState::assertEnvironment(
            (string) ($input['environment'] ?? SubscriptionState::ENV_SANDBOX),
        );
        SubscriptionState::assertWebsiteId($websiteId);
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数：%{1}', [$storeId]));
        }
        if ($customerId === '' || $planCode === '' || $providerCode === '' || $idempotencyKey === '') {
            throw new \InvalidArgumentException(__('Subscription 创建缺少必填字段'));
        }
        if (strlen($customerId) > 64
            || strlen($providerCode) > 64
            || strlen($planCode) > 128
            || strlen($idempotencyKey) > 128
        ) {
            throw new \InvalidArgumentException(__('Subscription 创建字段过长'));
        }

        $requestHash = hash('sha256', implode('|', [
            $customerId,
            (string) $websiteId,
            (string) $storeId,
            $providerCode,
            $planCode,
            $environment,
        ]));
        $existingByIdempotency = $this->findByIdempotency($idempotencyKey);
        if ($existingByIdempotency !== null) {
            return $this->assertReplay($existingByIdempotency, $requestHash);
        }

        $ownerPlanKey = $this->ownerPlanKey($customerId, $websiteId, $planCode);
        $existingByOwner = $this->findByOwnerPlan($customerId, $websiteId, $planCode);
        if ($existingByOwner !== null) {
            throw $this->ownerPlanTaken($existingByOwner);
        }

        $subscriptionId = trim((string) ($input['subscription_id'] ?? ''));
        if ($subscriptionId === '') {
            $subscriptionId = 'sub_' . substr(hash('sha256', $ownerPlanKey . '|' . $idempotencyKey), 0, 24);
        }
        if (strlen($subscriptionId) > 64) {
            throw new \InvalidArgumentException(__('Subscription ID 过长'));
        }
        if ($this->find($subscriptionId) !== null) {
            throw $this->alreadyExists($subscriptionId);
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'provider_code' => $providerCode,
            'plan_code' => $planCode,
            'environment' => $environment,
            'status' => SubscriptionState::STATUS_ACTIVE,
            'version' => 1,
            'cas_token' => bin2hex(random_bytes(32)),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'current_period_index' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'cancelled_at' => null,
        ];

        if ($this->rows !== null) {
            $this->rows[$subscriptionId] = $row;
            $this->byIdempotency[$idempotencyKey] = $subscriptionId;
            $this->byOwnerPlan[$ownerPlanKey] = $subscriptionId;
            return $row;
        }

        try {
            $this->newRecord()->clear()->setData($this->recordData($row))->save();
        } catch (Throwable $exception) {
            try {
                $winner = $this->findByIdempotency($idempotencyKey);
                if ($winner !== null) {
                    return $this->assertReplay($winner, $requestHash, $exception);
                }
                $winner = $this->findByOwnerPlan($customerId, $websiteId, $planCode);
                if ($winner !== null) {
                    throw $this->ownerPlanTaken($winner, $exception);
                }
            } catch (SubscriptionConflictException $conflict) {
                throw $conflict;
            } catch (Throwable) {
                // PostgreSQL may keep a failed transaction aborted until rollback.
            }
            throw $exception;
        }

        return $this->get($subscriptionId);
    }

    /** @return array<string, mixed> */
    public function get(string $subscriptionId): array
    {
        $subscriptionId = trim($subscriptionId);
        $row = $this->find($subscriptionId);
        if ($row === null) {
            throw new SubscriptionConflictException(
                self::ERROR_NOT_FOUND,
                __('Subscription 不存在：%{1}', [$subscriptionId]),
                ['subscription_id' => $subscriptionId],
            );
        }

        return $row;
    }

    /**
     * Conditional aggregate update. Immutable identity/request fields are never patchable.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function replaceWithVersionBump(string $subscriptionId, int $expectedVersion, array $patch): array
    {
        $current = $this->get($subscriptionId);
        if ((int) $current['version'] !== $expectedVersion) {
            throw $this->versionConflict($subscriptionId, $expectedVersion, (int) $current['version']);
        }

        $allowed = [
            'status' => true,
            'current_period_index' => true,
            'cancelled_at' => true,
        ];
        $next = $current;
        foreach ($patch as $key => $value) {
            if (isset($allowed[$key])) {
                $next[$key] = $value;
            }
        }
        $next['status'] = SubscriptionState::assertStatus((string) $next['status']);
        if ((int) $next['current_period_index'] < 0) {
            throw new \InvalidArgumentException(__('Subscription current_period_index 非法'));
        }
        $next['version'] = $expectedVersion + 1;
        $next['cas_token'] = bin2hex(random_bytes(32));
        $next['updated_at'] = gmdate('Y-m-d H:i:s');

        if ($this->rows !== null) {
            $this->rows[$subscriptionId] = $next;
            return $next;
        }

        $expectedToken = (string) $current['cas_token'];
        $candidate = $this->newRecord()->clear();
        $candidate->getQuery(false)
            ->where(Subscription::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->where(Subscription::schema_fields_VERSION, $expectedVersion)
            ->where(Subscription::schema_fields_CAS_TOKEN, $expectedToken)
            ->update([
                Subscription::schema_fields_STATUS => $next['status'],
                Subscription::schema_fields_CURRENT_PERIOD_INDEX => $next['current_period_index'],
                Subscription::schema_fields_CANCELLED_AT => $next['cancelled_at'],
                Subscription::schema_fields_VERSION => $next['version'],
                Subscription::schema_fields_CAS_TOKEN => $next['cas_token'],
                Subscription::schema_fields_UPDATED_AT => $next['updated_at'],
            ])
            ->fetch();

        $saved = $this->get($subscriptionId);
        if (!hash_equals((string) $next['cas_token'], (string) $saved['cas_token'])) {
            throw $this->versionConflict($subscriptionId, $expectedVersion, (int) $saved['version']);
        }

        return $saved;
    }

    public function count(): int
    {
        if ($this->rows !== null) {
            return count($this->rows);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string, mixed>|null */
    private function find(string $subscriptionId): ?array
    {
        if ($this->rows !== null) {
            return $this->rows[$subscriptionId] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(Subscription::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotency(string $idempotencyKey): ?array
    {
        if ($this->rows !== null) {
            $id = $this->byIdempotency[$idempotencyKey] ?? null;
            return $id !== null ? ($this->rows[$id] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(Subscription::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @return array<string, mixed>|null */
    private function findByOwnerPlan(
        string $customerId,
        int $websiteId,
        string $planCode,
    ): ?array
    {
        if ($this->rows !== null) {
            $id = $this->byOwnerPlan[$this->ownerPlanKey($customerId, $websiteId, $planCode)] ?? null;
            return $id !== null ? ($this->rows[$id] ?? null) : null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(Subscription::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Subscription::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Subscription::schema_fields_PLAN_CODE, $planCode)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function assertReplay(array $row, string $requestHash, ?Throwable $previous = null): array
    {
        if (!hash_equals((string) ($row['request_hash'] ?? ''), $requestHash)) {
            throw new SubscriptionConflictException(
                self::ERROR_IDEMPOTENCY,
                __('Subscription idempotency 冲突：%{1}', [$row['idempotency_key'] ?? '']),
                ['idempotency_key' => $row['idempotency_key'] ?? null],
                0,
                $previous,
            );
        }
        return $row + ['replayed' => true];
    }

    private function alreadyExists(string $subscriptionId, ?Throwable $previous = null): SubscriptionConflictException
    {
        return new SubscriptionConflictException(
            self::ERROR_EXISTS,
            __('Subscription 已存在：%{1}', [$subscriptionId]),
            ['subscription_id' => $subscriptionId],
            0,
            $previous,
        );
    }

    /** @param array<string, mixed> $existing */
    private function ownerPlanTaken(array $existing, ?Throwable $previous = null): SubscriptionConflictException
    {
        return new SubscriptionConflictException(
            self::ERROR_EXISTS,
            __('同一客户/站点/计划订阅已存在'),
            [
                'customer_id' => $existing['customer_id'] ?? null,
                'website_id' => $existing['website_id'] ?? null,
                'store_id' => $existing['store_id'] ?? null,
                'plan_code' => $existing['plan_code'] ?? null,
                'existing_subscription_id' => $existing['subscription_id'] ?? null,
            ],
            0,
            $previous,
        );
    }

    private function versionConflict(
        string $subscriptionId,
        int $expectedVersion,
        int $actualVersion,
    ): SubscriptionConflictException {
        return new SubscriptionConflictException(
            self::ERROR_VERSION,
            __('Subscription 版本冲突：expected=%{1} actual=%{2}', [$expectedVersion, $actualVersion]),
            [
                'subscription_id' => $subscriptionId,
                'expected_version' => $expectedVersion,
                'actual_version' => $actualVersion,
            ],
        );
    }

    private function ownerPlanKey(string $customerId, int $websiteId, string $planCode): string
    {
        return $customerId . ':' . $websiteId . ':' . $planCode;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            Subscription::schema_fields_SUBSCRIPTION_ID => $row['subscription_id'],
            Subscription::schema_fields_CUSTOMER_ID => $row['customer_id'],
            Subscription::schema_fields_WEBSITE_ID => $row['website_id'],
            Subscription::schema_fields_STORE_ID => $row['store_id'],
            Subscription::schema_fields_PROVIDER_CODE => $row['provider_code'],
            Subscription::schema_fields_PLAN_CODE => $row['plan_code'],
            Subscription::schema_fields_ENVIRONMENT => $row['environment'],
            Subscription::schema_fields_STATUS => $row['status'],
            Subscription::schema_fields_VERSION => $row['version'],
            Subscription::schema_fields_CAS_TOKEN => $row['cas_token'],
            Subscription::schema_fields_CURRENT_PERIOD_INDEX => $row['current_period_index'],
            Subscription::schema_fields_IDEMPOTENCY_KEY => $row['idempotency_key'],
            Subscription::schema_fields_REQUEST_HASH => $row['request_hash'],
            Subscription::schema_fields_CREATED_AT => $row['created_at'],
            Subscription::schema_fields_UPDATED_AT => $row['updated_at'],
            Subscription::schema_fields_CANCELLED_AT => $row['cancelled_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $row['website_id'] = (int) ($row['website_id'] ?? 0);
        $row['store_id'] = (int) ($row['store_id'] ?? 0);
        $row['version'] = (int) ($row['version'] ?? 0);
        $row['current_period_index'] = (int) ($row['current_period_index'] ?? 0);
        return $row;
    }

    private function newRecord(): Subscription
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(Subscription::class, [], false);
    }
}
