<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;

/**
 * Global SKU identity: claim / rename-alias / ref_count / orphan cleanup.
 *
 * Concurrent claims rely on UNIQUE(sku) + request_hash compare; never creates
 * orphan shard/Store rows (DDL/projection are outside this service).
 */
final class SkuRegistryService implements ProductIdentityResolverInterface
{
    private const MAX_CAS_ATTEMPTS = 8;

    /** @var (\Closure(): SkuRegistry)|null */
    private readonly mixed $registryFactory;

    /** @var (\Closure(): SkuAlias)|null */
    private readonly mixed $aliasFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $casTokenFactory;

    /**
     * @param (\Closure(): SkuRegistry)|null $registryFactory
     * @param (\Closure(): SkuAlias)|null $aliasFactory
     * @param (\Closure(): string)|null $casTokenFactory
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly ProductIdentityCutoverPolicyInterface $cutoverPolicy,
        ?callable $registryFactory = null,
        ?callable $aliasFactory = null,
        ?callable $casTokenFactory = null,
    ) {
        $this->registryFactory = $registryFactory;
        $this->aliasFactory = $aliasFactory;
        $this->casTokenFactory = $casTokenFactory;
    }

    /**
     * Claim or replay an identity for SKU under request_hash.
     * Same SKU+hash → idempotent replay; same SKU+different hash → conflict.
     */
    public function claimLocked(string $sku, string $requestHash): ProductIdentity
    {
        $this->assertLegacyWritesAllowed();
        $sku = $this->normalizeSku($sku);
        $requestHash = $this->normalizeRequestHash($requestHash);

        try {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use ($sku, $requestHash): ProductIdentity {
                    $existing = $this->findBySkuOrAlias($sku);
                    if ($existing !== null) {
                        $this->assertClaimable($existing, $sku);
                        $this->assertRequestHashMatches($existing, $requestHash, $sku);
                        return $this->toIdentity($existing);
                    }

                    $registry = $this->newRegistry();
                    $now = date('Y-m-d H:i:s');
                    $registry->clear()->setData([
                        SkuRegistry::schema_fields_SKU => $sku,
                        SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID => $this->newUuid(),
                        SkuRegistry::schema_fields_GLOBAL_OFFER_UUID => $this->newUuid(),
                        SkuRegistry::schema_fields_REQUEST_HASH => $requestHash,
                        SkuRegistry::schema_fields_REF_COUNT => 0,
                        SkuRegistry::schema_fields_STATUS => SkuRegistry::STATUS_ACTIVE,
                        SkuRegistry::schema_fields_CREATED_AT => $now,
                        SkuRegistry::schema_fields_UPDATED_AT => $now,
                    ])->save();

                    $created = $this->loadBySkuAnyStatus($sku);
                    if ($created === null) {
                        throw new \RuntimeException(__('SKU claim 写入后无法回读：%{1}', [$sku]));
                    }
                    $this->assertClaimable($created, $sku);
                    return $this->toIdentity($created);
                }
            );
        } catch (SkuIdentityConflictException|\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // PostgreSQL marks a transaction aborted after UNIQUE violation.
            // Reload the winner only after DatabaseTransactionRunner rolls back.
            $raced = $this->findBySkuOrAlias($sku);
            if ($raced === null) {
                throw $e;
            }
            $this->assertClaimable($raced, $sku);
            $this->assertRequestHashMatches($raced, $requestHash, $sku);
            return $this->toIdentity($raced);
        }
    }

    /**
     * Rename canonical SKU; old SKU becomes alias. Target must be free.
     */
    public function renameSku(string $fromSku, string $toSku): ProductIdentity
    {
        $this->assertLegacyWritesAllowed();
        $fromSku = $this->normalizeSku($fromSku);
        $toSku = $this->normalizeSku($toSku);
        if ($fromSku === $toSku) {
            $identity = $this->resolveBySku($fromSku);
            if ($identity === null) {
                throw new \InvalidArgumentException(__('SKU 不存在：%{1}', [$fromSku]));
            }
            return $identity;
        }

        try {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use ($fromSku, $toSku): ProductIdentity {
                    $row = $this->loadBySkuAnyStatus($fromSku);
                    if ($row !== null
                        && (string)$row->getData(SkuRegistry::schema_fields_STATUS)
                        !== SkuRegistry::STATUS_ACTIVE
                    ) {
                        throw new SkuIdentityConflictException(
                            'sku_identity_tombstoned',
                            __('SKU 已 tombstone：%{1}', [$fromSku]),
                            ['sku' => $fromSku],
                        );
                    }
                if ($row === null) {
                    // maybe already an alias pointing here — rename only from canonical
                    throw new \InvalidArgumentException(__('只能重命名 canonical SKU：%{1}', [$fromSku]));
                }

                if ($this->findBySkuOrAlias($toSku) !== null) {
                    throw new SkuIdentityConflictException(
                        'sku_rename_target_taken',
                        __('目标 SKU 已被占用：%{1}', [$toSku]),
                        ['from' => $fromSku, 'to' => $toSku],
                    );
                }

                $registryId = (int)$row->getId();
                $now = date('Y-m-d H:i:s');

                // Keep old SKU as alias first (unique), then flip canonical.
                $alias = $this->newAlias();
                $alias->clear()->setData([
                    SkuAlias::schema_fields_SKU => $fromSku,
                    SkuAlias::schema_fields_REGISTRY_ID => $registryId,
                    SkuAlias::schema_fields_CREATED_AT => $now,
                ])->save();

                $row->setData(SkuRegistry::schema_fields_SKU, $toSku)
                    ->setData(SkuRegistry::schema_fields_UPDATED_AT, $now)
                    ->save();

                $updated = $this->loadByRegistryId($registryId);
                if ($updated === null) {
                    throw new \RuntimeException(__('SKU rename 后无法回读 registry_id=%{1}', [$registryId]));
                }
                return $this->toIdentity($updated);
                }
            );
        } catch (SkuIdentityConflictException|\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // UNIQUE race is only classified after the failed transaction has
            // rolled back; querying inside an aborted PostgreSQL transaction
            // would mask the real winner.
            if ($this->findBySkuOrAlias($toSku) !== null) {
                throw new SkuIdentityConflictException(
                    'sku_rename_target_taken',
                    __('目标 SKU 已被并发占用：%{1}', [$toSku]),
                    ['from' => $fromSku, 'to' => $toSku],
                    $e,
                );
            }
            throw $e;
        }
    }

    public function resolveBySku(string $sku): ?ProductIdentity
    {
        $sku = $this->normalizeSku($sku);
        $row = $this->findActiveBySkuOrAlias($sku);
        return $row === null ? null : $this->toIdentity($row);
    }

    public function resolveByProductUuid(string $uuid): ?ProductIdentity
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }
        $row = $this->newRegistry()->clear()
            ->where(SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID, $uuid)
            ->where(SkuRegistry::schema_fields_STATUS, SkuRegistry::STATUS_ACTIVE)
            ->find()
            ->fetch();
        return $row->getId() ? $this->toIdentity($row) : null;
    }

    public function resolveByOfferUuid(string $uuid): ?ProductIdentity
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }
        $row = $this->newRegistry()->clear()
            ->where(SkuRegistry::schema_fields_GLOBAL_OFFER_UUID, $uuid)
            ->where(SkuRegistry::schema_fields_STATUS, SkuRegistry::STATUS_ACTIVE)
            ->find()
            ->fetch();
        return $row->getId() ? $this->toIdentity($row) : null;
    }

    public function incrementRefCount(int $registryId): int
    {
        return $this->adjustRefCount($registryId, +1);
    }

    public function decrementRefCount(int $registryId): int
    {
        return $this->adjustRefCount($registryId, -1);
    }

    /**
     * Forward cleanup of unreferenced identities. Never deletes when ref_count>0.
     *
     * @return bool true when tombstoned/removed
     */
    public function cleanupOrphanBySku(string $sku): bool
    {
        $this->assertLegacyWritesAllowed();
        $sku = $this->normalizeSku($sku);

        return (bool)$this->transactions->run(
            $this->connectionFactory,
            function () use ($sku): bool {
                $row = $this->findBySkuOrAlias($sku);
                if ($row === null) {
                    return false;
                }
                if ((string)$row->getData(SkuRegistry::schema_fields_STATUS)
                    !== SkuRegistry::STATUS_ACTIVE
                ) {
                    return false;
                }
                $ref = (int)$row->getData(SkuRegistry::schema_fields_REF_COUNT);
                if ($ref > 0) {
                    throw new SkuIdentityConflictException(
                        'sku_identity_still_referenced',
                        __('SKU 仍被引用，禁止清理：%{1} ref_count=%{2}', [$sku, $ref]),
                        ['sku' => $sku, 'ref_count' => $ref],
                    );
                }

                $registryId = (int)$row->getId();
                $now = date('Y-m-d H:i:s');
                if (!$this->conditionalRegistryUpdate(
                    $registryId,
                    0,
                    (string)$row->getData(SkuRegistry::schema_fields_CAS_TOKEN),
                    [
                        SkuRegistry::schema_fields_STATUS => SkuRegistry::STATUS_TOMBSTONED,
                        SkuRegistry::schema_fields_UPDATED_AT => $now,
                    ],
                )) {
                    $current = $this->loadByRegistryIdAnyStatus($registryId);
                    if ($current === null
                        || (string)$current->getData(SkuRegistry::schema_fields_STATUS)
                        !== SkuRegistry::STATUS_ACTIVE
                    ) {
                        return false;
                    }
                    $currentRef = (int)$current->getData(SkuRegistry::schema_fields_REF_COUNT);
                    throw new SkuIdentityConflictException(
                        $currentRef > 0
                            ? 'sku_identity_still_referenced'
                            : 'sku_cleanup_contention',
                        $currentRef > 0
                            ? __('SKU 仍被引用，禁止清理：%{1} ref_count=%{2}', [$sku, $currentRef])
                            : __('SKU cleanup 并发冲突：%{1}', [$sku]),
                        ['sku' => $sku, 'ref_count' => $currentRef],
                    );
                }

                // Aliases are identity history and permanent SKU reservations.
                // Retaining them prevents a tombstoned old SKU from being
                // silently claimed by another Product/Offer identity.
                return true;
            }
        );
    }

    public function normalizeSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException(__('SKU 不能为空'));
        }
        if (strlen($sku) > 128) {
            throw new \InvalidArgumentException(__('SKU 长度不能超过 128'));
        }
        return $sku;
    }

    private function assertLegacyWritesAllowed(): void
    {
        if ($this->cutoverPolicy->legacyWritesAllowed()) {
            return;
        }
        throw new SkuIdentityConflictException(
            'legacy_identity_writes_disabled',
            __('V2 身份已成为权威源，旧 SKU 注册表仅允许兼容读取'),
            ['mode' => $this->cutoverPolicy->mode()],
        );
    }

    private function normalizeRequestHash(string $requestHash): string
    {
        $requestHash = strtolower(trim($requestHash));
        if ($requestHash === '' || !preg_match('/^[a-f0-9]{32,128}$/', $requestHash)) {
            throw new \InvalidArgumentException(__('request_hash 必须是 32-128 位十六进制'));
        }
        return $requestHash;
    }

    private function assertRequestHashMatches(SkuRegistry $row, string $requestHash, string $sku): void
    {
        $existingHash = (string)$row->getData(SkuRegistry::schema_fields_REQUEST_HASH);
        if (!hash_equals($existingHash, $requestHash)) {
            throw new SkuIdentityConflictException(
                'sku_request_hash_conflict',
                __('SKU 已被其他请求占用：%{1}', [$sku]),
                [
                    'sku' => $sku,
                    'existing_request_hash' => $existingHash,
                    'request_hash' => $requestHash,
                ],
            );
        }
    }

    private function adjustRefCount(int $registryId, int $delta): int
    {
        $this->assertLegacyWritesAllowed();
        if ($registryId <= 0) {
            throw new \InvalidArgumentException(__('registry_id 无效'));
        }

        return (int)$this->transactions->run(
            $this->connectionFactory,
            function () use ($registryId, $delta): int {
                for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
                    $row = $this->loadByRegistryIdAnyStatus($registryId);
                    if ($row === null
                        || (string)$row->getData(SkuRegistry::schema_fields_STATUS)
                        !== SkuRegistry::STATUS_ACTIVE
                    ) {
                        throw new \InvalidArgumentException(__(
                            'SKU registry 不存在或已 tombstone：%{1}',
                            [$registryId],
                        ));
                    }
                    $current = (int)$row->getData(SkuRegistry::schema_fields_REF_COUNT);
                    $next = $current + $delta;
                    if ($next < 0) {
                        throw new SkuIdentityConflictException(
                            'sku_ref_count_underflow',
                            __('SKU ref_count 不能为负：registry_id=%{1}', [$registryId]),
                            ['registry_id' => $registryId, 'ref_count' => $current],
                        );
                    }
                    if ($this->conditionalRegistryUpdate(
                        $registryId,
                        $current,
                        (string)$row->getData(SkuRegistry::schema_fields_CAS_TOKEN),
                        [
                            SkuRegistry::schema_fields_REF_COUNT => $next,
                            SkuRegistry::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                        ],
                    )) {
                        return $next;
                    }
                }

                throw new SkuIdentityConflictException(
                    'sku_ref_count_contention',
                    __('SKU ref_count 并发重试耗尽：registry_id=%{1}', [$registryId]),
                    ['registry_id' => $registryId, 'attempts' => self::MAX_CAS_ATTEMPTS],
                );
            }
        );
    }

    private function findActiveBySkuOrAlias(string $sku): ?SkuRegistry
    {
        $row = $this->findBySkuOrAlias($sku);
        if ($row === null
            || (string)$row->getData(SkuRegistry::schema_fields_STATUS)
            !== SkuRegistry::STATUS_ACTIVE
        ) {
            return null;
        }
        return $row;
    }

    private function findBySkuOrAlias(string $sku): ?SkuRegistry
    {
        $direct = $this->loadBySkuAnyStatus($sku);
        if ($direct !== null) {
            return $direct;
        }
        $alias = $this->newAlias()->clear()
            ->where(SkuAlias::schema_fields_SKU, $sku)
            ->find()
            ->fetch();
        if (!$alias->getId()) {
            return null;
        }
        return $this->loadByRegistryIdAnyStatus(
            (int)$alias->getData(SkuAlias::schema_fields_REGISTRY_ID),
        );
    }

    private function loadBySku(string $sku): ?SkuRegistry
    {
        $row = $this->loadBySkuAnyStatus($sku);
        return $row !== null
            && (string)$row->getData(SkuRegistry::schema_fields_STATUS)
                === SkuRegistry::STATUS_ACTIVE
            ? $row
            : null;
    }

    private function loadBySkuAnyStatus(string $sku): ?SkuRegistry
    {
        $row = $this->newRegistry()->clear()
            ->where(SkuRegistry::schema_fields_SKU, $sku)
            ->find()
            ->fetch();
        return $row->getId() ? $row : null;
    }

    private function loadByRegistryId(int $registryId): ?SkuRegistry
    {
        $row = $this->loadByRegistryIdAnyStatus($registryId);
        return $row !== null
            && (string)$row->getData(SkuRegistry::schema_fields_STATUS)
                === SkuRegistry::STATUS_ACTIVE
            ? $row
            : null;
    }

    private function loadByRegistryIdAnyStatus(int $registryId): ?SkuRegistry
    {
        $row = $this->newRegistry()->clear()
            ->where(SkuRegistry::schema_fields_ID, $registryId)
            ->find()
            ->fetch();
        if (!$row->getId()) {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function conditionalRegistryUpdate(
        int $registryId,
        int $expectedRefCount,
        string $expectedCasToken,
        array $updates,
    ): bool {
        $casToken = $this->newCasToken();
        $candidate = $this->newRegistry()->clear();
        $candidate->getQuery()
            ->where(SkuRegistry::schema_fields_ID, $registryId)
            ->where(SkuRegistry::schema_fields_STATUS, SkuRegistry::STATUS_ACTIVE)
            ->where(SkuRegistry::schema_fields_REF_COUNT, $expectedRefCount)
            ->where(SkuRegistry::schema_fields_CAS_TOKEN, $expectedCasToken)
            ->update(array_merge($updates, [
                SkuRegistry::schema_fields_CAS_TOKEN => $casToken,
            ]))
            ->fetch();
        $current = $this->loadByRegistryIdAnyStatus($registryId);
        return $current !== null
            && hash_equals(
                $casToken,
                (string)$current->getData(SkuRegistry::schema_fields_CAS_TOKEN),
            );
    }

    private function assertClaimable(SkuRegistry $row, string $sku): void
    {
        if ((string)$row->getData(SkuRegistry::schema_fields_STATUS)
            === SkuRegistry::STATUS_ACTIVE
        ) {
            return;
        }

        throw new SkuIdentityConflictException(
            'sku_identity_tombstoned',
            __('SKU 已 tombstone，禁止重新占用：%{1}', [$sku]),
            ['sku' => $sku],
        );
    }

    private function toIdentity(SkuRegistry $row): ProductIdentity
    {
        return new ProductIdentity(
            registryId: (int)$row->getId(),
            sku: (string)$row->getData(SkuRegistry::schema_fields_SKU),
            globalProductUuid: (string)$row->getData(SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID),
            globalOfferUuid: (string)$row->getData(SkuRegistry::schema_fields_GLOBAL_OFFER_UUID),
            requestHash: (string)$row->getData(SkuRegistry::schema_fields_REQUEST_HASH),
            refCount: (int)$row->getData(SkuRegistry::schema_fields_REF_COUNT),
        );
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function newCasToken(): string
    {
        $token = $this->casTokenFactory !== null
            ? strtolower(trim((string)($this->casTokenFactory)()))
            : bin2hex(random_bytes(32));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw new \LogicException(__('SKU CAS token factory 必须返回 32-64 位十六进制'));
        }
        return $token;
    }

    private function newRegistry(): SkuRegistry
    {
        if ($this->registryFactory !== null) {
            return ($this->registryFactory)();
        }
        return ObjectManager::make(SkuRegistry::class);
    }

    private function newAlias(): SkuAlias
    {
        if ($this->aliasFactory !== null) {
            return ($this->aliasFactory)();
        }
        return ObjectManager::make(SkuAlias::class);
    }
}
