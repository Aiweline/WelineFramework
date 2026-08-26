<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\OfferIdentityV2;
use Weline\Product\Api\Data\ProductIdentityV2;
use Weline\Product\Api\ProductIdentityV2ResolverInterface;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Product\Model\OfferSkuAlias;
use Weline\Product\Model\ProductAuditLog;
use Weline\Product\Model\ProductIdentityRegistry;

/**
 * Global Product and Offer identity source. Website catalog facts never live here.
 */
final class ProductIdentityV2Service implements ProductIdentityV2ResolverInterface
{
    /** @var (\Closure(): ProductIdentityRegistry)|null */
    private readonly mixed $productRegistryFactory;
    /** @var (\Closure(): OfferIdentityRegistry)|null */
    private readonly mixed $offerRegistryFactory;
    /** @var (\Closure(): OfferSkuAlias)|null */
    private readonly mixed $offerAliasFactory;
    /** @var (\Closure(): ProductAuditLog)|null */
    private readonly mixed $auditFactory;

    /**
     * @param (\Closure(): ProductIdentityRegistry)|null $productRegistryFactory
     * @param (\Closure(): OfferIdentityRegistry)|null $offerRegistryFactory
     * @param (\Closure(): OfferSkuAlias)|null $offerAliasFactory
     * @param (\Closure(): ProductAuditLog)|null $auditFactory
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        ?callable $productRegistryFactory = null,
        ?callable $offerRegistryFactory = null,
        ?callable $offerAliasFactory = null,
        ?callable $auditFactory = null,
    ) {
        $this->productRegistryFactory = $productRegistryFactory;
        $this->offerRegistryFactory = $offerRegistryFactory;
        $this->offerAliasFactory = $offerAliasFactory;
        $this->auditFactory = $auditFactory;
    }

    public function createProduct(
        int $ownerWebsiteId,
        string $providerCode,
        string $productType,
        string $requestHash,
    ): ProductIdentityV2 {
        return $this->createProductWithUuid(
            $this->uuidFromRequestHash($requestHash, 'product'),
            $ownerWebsiteId,
            $providerCode,
            $productType,
            $requestHash,
        );
    }

    /**
     * Migration-only idempotent import that preserves the legacy global UUID.
     */
    public function createProductWithUuid(
        string $globalProductUuid,
        int $ownerWebsiteId,
        string $providerCode,
        string $productType,
        string $requestHash,
    ): ProductIdentityV2 {
        $globalProductUuid = $this->normalizeUuid($globalProductUuid);
        $ownerWebsiteId = $this->normalizeWebsiteId($ownerWebsiteId);
        $providerCode = $this->normalizeCode($providerCode, 'provider_code');
        $productType = $this->normalizeCode($productType, 'product_type');
        $requestHash = $this->normalizeRequestHash($requestHash);

        try {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use (
                    $globalProductUuid,
                    $ownerWebsiteId,
                    $providerCode,
                    $productType,
                    $requestHash,
                ): ProductIdentityV2 {
                    $replay = $this->loadProductByRequestHash($requestHash);
                    if ($replay !== null) {
                        $this->assertProductReplay(
                            $replay,
                            $globalProductUuid,
                            $ownerWebsiteId,
                            $providerCode,
                            $productType,
                        );
                        return $this->toProductIdentity($replay);
                    }
                    $existing = $this->loadProductByUuid($globalProductUuid);
                    if ($existing !== null) {
                        $this->assertProductReplay(
                            $existing,
                            $globalProductUuid,
                            $ownerWebsiteId,
                            $providerCode,
                            $productType,
                        );
                        return $this->toProductIdentity($existing);
                    }

                    $now = date('Y-m-d H:i:s');
                    $row = $this->newProductRegistry();
                    $row->clear()->setData([
                        ProductIdentityRegistry::schema_fields_UUID => $globalProductUuid,
                        ProductIdentityRegistry::schema_fields_PRODUCT_CODE => $this->productCode($globalProductUuid),
                        ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID => $ownerWebsiteId,
                        ProductIdentityRegistry::schema_fields_PROVIDER_CODE => $providerCode,
                        ProductIdentityRegistry::schema_fields_PRODUCT_TYPE => $productType,
                        ProductIdentityRegistry::schema_fields_LIFECYCLE_STATUS => ProductIdentityRegistry::STATUS_DRAFT,
                        ProductIdentityRegistry::schema_fields_VERSION => 1,
                        ProductIdentityRegistry::schema_fields_SHARE_POLICY => $ownerWebsiteId === 0
                            ? ProductIdentityRegistry::SHARE_DEFAULT_SITE
                            : ProductIdentityRegistry::SHARE_PRIVATE,
                        ProductIdentityRegistry::schema_fields_REQUEST_HASH => $requestHash,
                        ProductIdentityRegistry::schema_fields_CREATED_AT => $now,
                        ProductIdentityRegistry::schema_fields_UPDATED_AT => $now,
                    ])->save();

                    $created = $this->loadProductByUuid($globalProductUuid);
                    if ($created === null) {
                        throw new \RuntimeException('product_v2_create_readback_failed');
                    }
                    $this->audit(
                        $globalProductUuid,
                        null,
                        $ownerWebsiteId,
                        'product.identity.created',
                        0,
                        1,
                        $requestHash,
                        ['provider_code' => $providerCode, 'product_type' => $productType],
                    );
                    return $this->toProductIdentity($created);
                },
            );
        } catch (ProductV2ConflictException|\InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $winner = $this->loadProductByUuid($globalProductUuid)
                ?? $this->loadProductByRequestHash($requestHash);
            if ($winner !== null) {
                $this->assertProductReplay(
                    $winner,
                    $globalProductUuid,
                    $ownerWebsiteId,
                    $providerCode,
                    $productType,
                );
                return $this->toProductIdentity($winner);
            }
            throw $exception;
        }
    }

    public function createOffer(
        string $globalProductUuid,
        string $sku,
        string $requestHash,
    ): OfferIdentityV2 {
        return $this->createOfferWithUuid(
            $globalProductUuid,
            $this->uuidFromRequestHash($requestHash, 'offer:' . strtolower(trim($globalProductUuid))),
            $sku,
            $requestHash,
        );
    }

    /**
     * Migration-only idempotent import that preserves legacy Offer UUID and SKU.
     */
    public function createOfferWithUuid(
        string $globalProductUuid,
        string $globalOfferUuid,
        string $sku,
        string $requestHash,
    ): OfferIdentityV2 {
        $globalProductUuid = $this->normalizeUuid($globalProductUuid);
        $globalOfferUuid = $this->normalizeUuid($globalOfferUuid);
        $sku = $this->normalizeSku($sku);
        $requestHash = $this->normalizeRequestHash($requestHash);
        if ($this->loadProductByUuid($globalProductUuid) === null) {
            throw new \InvalidArgumentException('product_v2_identity_not_found');
        }

        try {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use ($globalProductUuid, $globalOfferUuid, $sku, $requestHash): OfferIdentityV2 {
                    $replay = $this->loadOfferByRequestHash($requestHash);
                    if ($replay !== null) {
                        $this->assertOfferReplay($replay, $globalProductUuid, $globalOfferUuid, $sku);
                        return $this->toOfferIdentity($replay);
                    }
                    $occupied = $this->resolveOfferBySku($sku);
                    if ($occupied !== null) {
                        throw new ProductV2ConflictException(
                            'offer_sku_taken',
                            __('SKU 已被其他 Offer 占用：%{1}', [$sku]),
                            ['sku' => $sku, 'global_offer_uuid' => $occupied->globalOfferUuid],
                        );
                    }
                    $existing = $this->loadOfferByUuid($globalOfferUuid);
                    if ($existing !== null) {
                        $this->assertOfferReplay($existing, $globalProductUuid, $globalOfferUuid, $sku);
                        return $this->toOfferIdentity($existing);
                    }

                    $now = date('Y-m-d H:i:s');
                    $row = $this->newOfferRegistry();
                    $row->clear()->setData([
                        OfferIdentityRegistry::schema_fields_UUID => $globalOfferUuid,
                        OfferIdentityRegistry::schema_fields_PRODUCT_UUID => $globalProductUuid,
                        OfferIdentityRegistry::schema_fields_SKU => $sku,
                        OfferIdentityRegistry::schema_fields_STATUS => OfferIdentityRegistry::STATUS_ACTIVE,
                        OfferIdentityRegistry::schema_fields_VERSION => 1,
                        OfferIdentityRegistry::schema_fields_REQUEST_HASH => $requestHash,
                        OfferIdentityRegistry::schema_fields_CREATED_AT => $now,
                        OfferIdentityRegistry::schema_fields_UPDATED_AT => $now,
                    ])->save();

                    $created = $this->loadOfferByUuid($globalOfferUuid);
                    if ($created === null) {
                        throw new \RuntimeException('offer_v2_create_readback_failed');
                    }
                    $product = $this->requireProduct($globalProductUuid);
                    $this->audit(
                        $globalProductUuid,
                        $globalOfferUuid,
                        (int)$product->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID),
                        'offer.identity.created',
                        0,
                        1,
                        $requestHash,
                        ['sku' => $sku],
                    );
                    return $this->toOfferIdentity($created);
                },
            );
        } catch (ProductV2ConflictException|\InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $winner = $this->loadOfferByUuid($globalOfferUuid)
                ?? $this->loadOfferByRequestHash($requestHash);
            if ($winner !== null) {
                $this->assertOfferReplay($winner, $globalProductUuid, $globalOfferUuid, $sku);
                return $this->toOfferIdentity($winner);
            }
            throw $exception;
        }
    }

    public function renameSku(
        string $globalOfferUuid,
        string $newSku,
        int $expectedVersion,
        int $actingWebsiteId,
        string $requestHash,
    ): OfferIdentityV2 {
        $globalOfferUuid = $this->normalizeUuid($globalOfferUuid);
        $newSku = $this->normalizeSku($newSku);
        $requestHash = $this->normalizeRequestHash($requestHash);

        return $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $globalOfferUuid,
                $newSku,
                $expectedVersion,
                $actingWebsiteId,
                $requestHash,
            ): OfferIdentityV2 {
                $offer = $this->requireOffer($globalOfferUuid);
                $product = $this->requireProduct(
                    (string)$offer->getData(OfferIdentityRegistry::schema_fields_PRODUCT_UUID),
                );
                $this->assertOwner($product, $actingWebsiteId);
                $currentVersion = (int)$offer->getData(OfferIdentityRegistry::schema_fields_VERSION);
                if ($currentVersion !== $expectedVersion) {
                    throw $this->versionConflict('offer_version_conflict', $expectedVersion, $currentVersion);
                }
                $oldSku = (string)$offer->getData(OfferIdentityRegistry::schema_fields_SKU);
                if ($oldSku === $newSku) {
                    return $this->toOfferIdentity($offer);
                }
                if ($this->resolveOfferBySku($newSku) !== null) {
                    throw new ProductV2ConflictException(
                        'offer_sku_taken',
                        __('目标 SKU 已被占用：%{1}', [$newSku]),
                        ['sku' => $newSku],
                    );
                }

                $this->newOfferAlias()->clear()->setData([
                    OfferSkuAlias::schema_fields_SKU => $oldSku,
                    OfferSkuAlias::schema_fields_OFFER_UUID => $globalOfferUuid,
                    OfferSkuAlias::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                ])->save();

                $nextVersion = $currentVersion + 1;
                $this->newOfferRegistry()->clear()->getQuery()
                    ->where(OfferIdentityRegistry::schema_fields_UUID, $globalOfferUuid)
                    ->where(OfferIdentityRegistry::schema_fields_VERSION, $currentVersion)
                    ->update([
                        OfferIdentityRegistry::schema_fields_SKU => $newSku,
                        OfferIdentityRegistry::schema_fields_VERSION => $nextVersion,
                        OfferIdentityRegistry::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->fetch();

                $updated = $this->requireOffer($globalOfferUuid);
                if ((int)$updated->getData(OfferIdentityRegistry::schema_fields_VERSION) !== $nextVersion) {
                    throw $this->versionConflict(
                        'offer_version_conflict',
                        $expectedVersion,
                        (int)$updated->getData(OfferIdentityRegistry::schema_fields_VERSION),
                    );
                }
                $this->audit(
                    (string)$updated->getData(OfferIdentityRegistry::schema_fields_PRODUCT_UUID),
                    $globalOfferUuid,
                    $actingWebsiteId,
                    'offer.sku.renamed',
                    $currentVersion,
                    $nextVersion,
                    $requestHash,
                    ['from' => $oldSku, 'to' => $newSku],
                );
                return $this->toOfferIdentity($updated);
            },
        );
    }

    public function transitionOfferStatus(
        string $globalOfferUuid,
        string $targetStatus,
        int $expectedVersion,
        int $actingWebsiteId,
        string $requestHash,
    ): OfferIdentityV2 {
        $globalOfferUuid = $this->normalizeUuid($globalOfferUuid);
        $targetStatus = strtolower(trim($targetStatus));
        $actingWebsiteId = $this->normalizeWebsiteId($actingWebsiteId);
        $requestHash = $this->normalizeRequestHash($requestHash);
        if (!in_array($targetStatus, [
            OfferIdentityRegistry::STATUS_ACTIVE,
            OfferIdentityRegistry::STATUS_DISABLED,
            OfferIdentityRegistry::STATUS_ARCHIVED,
        ], true)) {
            throw new \InvalidArgumentException('offer_identity_status_invalid');
        }

        return $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $globalOfferUuid,
                $targetStatus,
                $expectedVersion,
                $actingWebsiteId,
                $requestHash,
            ): OfferIdentityV2 {
                $offer = $this->requireOffer($globalOfferUuid);
                $productUuid = (string)$offer->getData(
                    OfferIdentityRegistry::schema_fields_PRODUCT_UUID,
                );
                $product = $this->requireProduct($productUuid);
                $this->assertOwner($product, $actingWebsiteId);
                $currentVersion = (int)$offer->getData(OfferIdentityRegistry::schema_fields_VERSION);
                if ($currentVersion !== $expectedVersion) {
                    throw $this->versionConflict(
                        'offer_version_conflict',
                        $expectedVersion,
                        $currentVersion,
                    );
                }
                $currentStatus = (string)$offer->getData(OfferIdentityRegistry::schema_fields_STATUS);
                if ($currentStatus === $targetStatus) {
                    return $this->toOfferIdentity($offer);
                }
                if ($currentStatus === OfferIdentityRegistry::STATUS_ARCHIVED) {
                    throw new ProductV2ConflictException(
                        'offer_identity_archived',
                        (string)__('已归档 Offer 身份不能重新启用'),
                        ['global_offer_uuid' => $globalOfferUuid],
                    );
                }

                $nextVersion = $currentVersion + 1;
                $this->newOfferRegistry()->clear()->getQuery()
                    ->where(OfferIdentityRegistry::schema_fields_UUID, $globalOfferUuid)
                    ->where(OfferIdentityRegistry::schema_fields_VERSION, $currentVersion)
                    ->update([
                        OfferIdentityRegistry::schema_fields_STATUS => $targetStatus,
                        OfferIdentityRegistry::schema_fields_VERSION => $nextVersion,
                        OfferIdentityRegistry::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->fetch();

                $updated = $this->requireOffer($globalOfferUuid);
                if ((int)$updated->getData(OfferIdentityRegistry::schema_fields_VERSION) !== $nextVersion) {
                    throw $this->versionConflict(
                        'offer_version_conflict',
                        $expectedVersion,
                        (int)$updated->getData(OfferIdentityRegistry::schema_fields_VERSION),
                    );
                }
                $this->audit(
                    $productUuid,
                    $globalOfferUuid,
                    $actingWebsiteId,
                    'offer.identity.status_changed',
                    $currentVersion,
                    $nextVersion,
                    $requestHash,
                    ['status' => $targetStatus],
                );
                return $this->toOfferIdentity($updated);
            },
        );
    }

    public function transitionProduct(
        string $globalProductUuid,
        string $targetStatus,
        int $expectedVersion,
        int $actingWebsiteId,
        string $requestHash,
    ): ProductIdentityV2 {
        $targetStatus = strtolower(trim($targetStatus));
        $allowed = [
            ProductIdentityRegistry::STATUS_DRAFT => [
                ProductIdentityRegistry::STATUS_PUBLISHED,
                ProductIdentityRegistry::STATUS_ARCHIVED,
            ],
            ProductIdentityRegistry::STATUS_PUBLISHED => [ProductIdentityRegistry::STATUS_DISABLED],
            ProductIdentityRegistry::STATUS_DISABLED => [
                ProductIdentityRegistry::STATUS_PUBLISHED,
                ProductIdentityRegistry::STATUS_ARCHIVED,
            ],
            ProductIdentityRegistry::STATUS_ARCHIVED => [],
        ];

        return $this->mutateProduct(
            $globalProductUuid,
            $expectedVersion,
            $actingWebsiteId,
            $requestHash,
            'product.lifecycle.' . $targetStatus,
            function (ProductIdentityRegistry $product) use ($targetStatus, $allowed): array {
                $current = (string)$product->getData(ProductIdentityRegistry::schema_fields_LIFECYCLE_STATUS);
                if (!in_array($targetStatus, $allowed[$current] ?? [], true)) {
                    throw new ProductV2ConflictException(
                        'product_lifecycle_transition_invalid',
                        __('非法商品状态转换：%{1} → %{2}', [$current, $targetStatus]),
                        ['from' => $current, 'to' => $targetStatus],
                    );
                }
                return [ProductIdentityRegistry::schema_fields_LIFECYCLE_STATUS => $targetStatus];
            },
        );
    }

    public function changeType(
        string $globalProductUuid,
        string $providerCode,
        string $productType,
        int $expectedVersion,
        int $actingWebsiteId,
        string $requestHash,
        bool $hasCopies,
        bool $hasOrderReferences,
    ): ProductIdentityV2 {
        $providerCode = $this->normalizeCode($providerCode, 'provider_code');
        $productType = $this->normalizeCode($productType, 'product_type');

        return $this->mutateProduct(
            $globalProductUuid,
            $expectedVersion,
            $actingWebsiteId,
            $requestHash,
            'product.type.changed',
            static function (ProductIdentityRegistry $product) use (
                $providerCode,
                $productType,
                $hasCopies,
                $hasOrderReferences,
            ): array {
                if ((string)$product->getData(ProductIdentityRegistry::schema_fields_LIFECYCLE_STATUS)
                    !== ProductIdentityRegistry::STATUS_DRAFT
                    || $hasCopies
                    || $hasOrderReferences
                ) {
                    throw new ProductV2ConflictException(
                        'product_type_change_forbidden',
                        __('仅未发布、未复制、无订单引用的草稿可转换类型'),
                        ['has_copies' => $hasCopies, 'has_order_references' => $hasOrderReferences],
                    );
                }
                return [
                    ProductIdentityRegistry::schema_fields_PROVIDER_CODE => $providerCode,
                    ProductIdentityRegistry::schema_fields_PRODUCT_TYPE => $productType,
                ];
            },
        );
    }

    public function resolveProductByUuid(string $globalProductUuid): ?ProductIdentityV2
    {
        $row = $this->loadProductByUuid($this->normalizeUuid($globalProductUuid));
        return $row === null ? null : $this->toProductIdentity($row);
    }

    public function resolveProductByCode(string $productCode): ?ProductIdentityV2
    {
        $productCode = strtoupper(trim($productCode));
        if ($productCode === '') {
            return null;
        }
        $row = $this->newProductRegistry()->clear()
            ->where(ProductIdentityRegistry::schema_fields_PRODUCT_CODE, $productCode)
            ->find()->fetch();
        return $row->getId() ? $this->toProductIdentity($row) : null;
    }

    /**
     * Immutable identity/governance audit events for one Product.
     *
     * Request hashes remain server-side correlation data and are deliberately
     * excluded from the admin read model.
     *
     * @return list<array<string,mixed>>
     */
    public function listAudit(string $globalProductUuid, int $limit = 100): array
    {
        $globalProductUuid = strtolower(trim($globalProductUuid));
        if (preg_match(
            '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/',
            $globalProductUuid,
        ) !== 1) {
            throw new \InvalidArgumentException('product_v2_uuid_invalid');
        }
        $limit = max(1, min(200, $limit));
        $rows = $this->newAudit()
            ->clear()
            ->where(ProductAuditLog::schema_fields_PRODUCT_UUID, $globalProductUuid)
            ->order(ProductAuditLog::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $events = [];
        foreach ($rows as $row) {
            $payload = [];
            $payloadCorrupt = false;
            try {
                $decoded = json_decode(
                    (string)($row[ProductAuditLog::schema_fields_PAYLOAD_JSON] ?? '{}'),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                if (is_array($decoded)) {
                    $payload = $decoded;
                } else {
                    $payloadCorrupt = true;
                }
            } catch (\JsonException) {
                $payloadCorrupt = true;
            }
            $offerUuid = trim((string)($row[ProductAuditLog::schema_fields_OFFER_UUID] ?? ''));
            $events[] = [
                'event_id' => (int)($row[ProductAuditLog::schema_fields_ID] ?? 0),
                'global_offer_uuid' => $offerUuid !== '' ? $offerUuid : null,
                'website_id' => (int)($row[ProductAuditLog::schema_fields_WEBSITE_ID] ?? 0),
                'action' => (string)($row[ProductAuditLog::schema_fields_ACTION] ?? ''),
                'before_version' => (int)($row[ProductAuditLog::schema_fields_BEFORE_VERSION] ?? 0),
                'after_version' => (int)($row[ProductAuditLog::schema_fields_AFTER_VERSION] ?? 0),
                'payload' => $payload,
                'payload_corrupt' => $payloadCorrupt,
                'created_at' => (string)($row[ProductAuditLog::schema_fields_CREATED_AT] ?? ''),
            ];
        }
        return $events;
    }

    public function resolveOfferByUuid(string $globalOfferUuid): ?OfferIdentityV2
    {
        $row = $this->loadOfferByUuid($this->normalizeUuid($globalOfferUuid));
        return $row === null ? null : $this->toOfferIdentity($row);
    }

    public function resolveOfferBySku(string $sku): ?OfferIdentityV2
    {
        $sku = $this->normalizeSku($sku);
        $row = $this->newOfferRegistry()->clear()
            ->where(OfferIdentityRegistry::schema_fields_SKU, $sku)
            ->find()->fetch();
        if ($row->getId()) {
            return $this->toOfferIdentity($row);
        }
        $alias = $this->newOfferAlias()->clear()
            ->where(OfferSkuAlias::schema_fields_SKU, $sku)
            ->find()->fetch();
        if (!$alias->getId()) {
            return null;
        }
        return $this->resolveOfferByUuid(
            (string)$alias->getData(OfferSkuAlias::schema_fields_OFFER_UUID),
        );
    }

    public function listOffers(string $globalProductUuid, bool $onlyActive = true): array
    {
        $globalProductUuid = $this->normalizeUuid($globalProductUuid);
        $query = $this->newOfferRegistry()->clear()
            ->where(OfferIdentityRegistry::schema_fields_PRODUCT_UUID, $globalProductUuid);
        if ($onlyActive) {
            $query->where(
                OfferIdentityRegistry::schema_fields_STATUS,
                OfferIdentityRegistry::STATUS_ACTIVE,
            );
        }

        return array_map(
            fn (array $row): OfferIdentityV2 => $this->toOfferIdentityRow($row),
            $query->select()->fetchArray(),
        );
    }

    /**
     * @param callable(ProductIdentityRegistry):array<string,mixed> $mutation
     */
    private function mutateProduct(
        string $globalProductUuid,
        int $expectedVersion,
        int $actingWebsiteId,
        string $requestHash,
        string $action,
        callable $mutation,
    ): ProductIdentityV2 {
        $globalProductUuid = $this->normalizeUuid($globalProductUuid);
        $actingWebsiteId = $this->normalizeWebsiteId($actingWebsiteId);
        $requestHash = $this->normalizeRequestHash($requestHash);

        return $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $globalProductUuid,
                $expectedVersion,
                $actingWebsiteId,
                $requestHash,
                $action,
                $mutation,
            ): ProductIdentityV2 {
                $product = $this->requireProduct($globalProductUuid);
                $this->assertOwner($product, $actingWebsiteId);
                $currentVersion = (int)$product->getData(ProductIdentityRegistry::schema_fields_VERSION);
                if ($currentVersion !== $expectedVersion) {
                    throw $this->versionConflict('product_version_conflict', $expectedVersion, $currentVersion);
                }
                $updates = $mutation($product);
                $nextVersion = $currentVersion + 1;
                $updates[ProductIdentityRegistry::schema_fields_VERSION] = $nextVersion;
                $updates[ProductIdentityRegistry::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
                $this->newProductRegistry()->clear()->getQuery()
                    ->where(ProductIdentityRegistry::schema_fields_UUID, $globalProductUuid)
                    ->where(ProductIdentityRegistry::schema_fields_VERSION, $currentVersion)
                    ->update($updates)->fetch();

                $updated = $this->requireProduct($globalProductUuid);
                if ((int)$updated->getData(ProductIdentityRegistry::schema_fields_VERSION) !== $nextVersion) {
                    throw $this->versionConflict(
                        'product_version_conflict',
                        $expectedVersion,
                        (int)$updated->getData(ProductIdentityRegistry::schema_fields_VERSION),
                    );
                }
                $this->audit(
                    $globalProductUuid,
                    null,
                    $actingWebsiteId,
                    $action,
                    $currentVersion,
                    $nextVersion,
                    $requestHash,
                    $updates,
                );
                return $this->toProductIdentity($updated);
            },
        );
    }

    private function assertOwner(ProductIdentityRegistry $product, int $websiteId): void
    {
        $owner = (int)$product->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID);
        if ($owner !== $websiteId) {
            throw new ProductV2ConflictException(
                'product_structure_owner_required',
                __('仅归属 Website 可修改 SKU、类型和规格结构'),
                ['owner_website_id' => $owner, 'acting_website_id' => $websiteId],
            );
        }
    }

    private function assertProductReplay(
        ProductIdentityRegistry $row,
        string $uuid,
        int $ownerWebsiteId,
        string $providerCode,
        string $productType,
    ): void {
        $actual = [
            (string)$row->getData(ProductIdentityRegistry::schema_fields_UUID),
            (int)$row->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID),
            (string)$row->getData(ProductIdentityRegistry::schema_fields_PROVIDER_CODE),
            (string)$row->getData(ProductIdentityRegistry::schema_fields_PRODUCT_TYPE),
        ];
        if ($actual !== [$uuid, $ownerWebsiteId, $providerCode, $productType]) {
            throw new ProductV2ConflictException(
                'product_identity_request_conflict',
                __('商品身份幂等请求与已存在记录不一致'),
                ['global_product_uuid' => $uuid],
            );
        }
    }

    private function assertOfferReplay(
        OfferIdentityRegistry $row,
        string $productUuid,
        string $offerUuid,
        string $sku,
    ): void {
        $actual = [
            (string)$row->getData(OfferIdentityRegistry::schema_fields_PRODUCT_UUID),
            (string)$row->getData(OfferIdentityRegistry::schema_fields_UUID),
            (string)$row->getData(OfferIdentityRegistry::schema_fields_SKU),
        ];
        if ($actual !== [$productUuid, $offerUuid, $sku]) {
            throw new ProductV2ConflictException(
                'offer_identity_request_conflict',
                __('Offer 身份幂等请求与已存在记录不一致'),
                ['global_offer_uuid' => $offerUuid, 'sku' => $sku],
            );
        }
    }

    private function audit(
        string $productUuid,
        ?string $offerUuid,
        int $websiteId,
        string $action,
        int $beforeVersion,
        int $afterVersion,
        string $requestHash,
        array $payload,
    ): void {
        $this->newAudit()->clear()->setData([
            ProductAuditLog::schema_fields_PRODUCT_UUID => $productUuid,
            ProductAuditLog::schema_fields_OFFER_UUID => $offerUuid,
            ProductAuditLog::schema_fields_WEBSITE_ID => $websiteId,
            ProductAuditLog::schema_fields_ACTION => $action,
            ProductAuditLog::schema_fields_BEFORE_VERSION => $beforeVersion,
            ProductAuditLog::schema_fields_AFTER_VERSION => $afterVersion,
            ProductAuditLog::schema_fields_REQUEST_HASH => $requestHash,
            ProductAuditLog::schema_fields_PAYLOAD_JSON => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            ProductAuditLog::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    private function versionConflict(string $code, int $expected, int $actual): ProductV2ConflictException
    {
        return new ProductV2ConflictException(
            $code,
            __('版本已变化，请刷新后重试'),
            ['expected_version' => $expected, 'actual_version' => $actual],
        );
    }

    private function requireProduct(string $uuid): ProductIdentityRegistry
    {
        $row = $this->loadProductByUuid($uuid);
        if ($row === null) {
            throw new \InvalidArgumentException('product_v2_identity_not_found');
        }
        return $row;
    }

    private function requireOffer(string $uuid): OfferIdentityRegistry
    {
        $row = $this->loadOfferByUuid($uuid);
        if ($row === null) {
            throw new \InvalidArgumentException('offer_v2_identity_not_found');
        }
        return $row;
    }

    private function loadProductByUuid(string $uuid): ?ProductIdentityRegistry
    {
        $row = $this->newProductRegistry()->clear()
            ->where(ProductIdentityRegistry::schema_fields_UUID, $uuid)
            ->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function loadProductByRequestHash(string $hash): ?ProductIdentityRegistry
    {
        $row = $this->newProductRegistry()->clear()
            ->where(ProductIdentityRegistry::schema_fields_REQUEST_HASH, $hash)
            ->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function loadOfferByUuid(string $uuid): ?OfferIdentityRegistry
    {
        $row = $this->newOfferRegistry()->clear()
            ->where(OfferIdentityRegistry::schema_fields_UUID, $uuid)
            ->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function loadOfferByRequestHash(string $hash): ?OfferIdentityRegistry
    {
        $row = $this->newOfferRegistry()->clear()
            ->where(OfferIdentityRegistry::schema_fields_REQUEST_HASH, $hash)
            ->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function toProductIdentity(ProductIdentityRegistry $row): ProductIdentityV2
    {
        return new ProductIdentityV2(
            registryId: (int)$row->getId(),
            globalProductUuid: (string)$row->getData(ProductIdentityRegistry::schema_fields_UUID),
            productCode: (string)$row->getData(ProductIdentityRegistry::schema_fields_PRODUCT_CODE),
            ownerWebsiteId: (int)$row->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID),
            providerCode: (string)$row->getData(ProductIdentityRegistry::schema_fields_PROVIDER_CODE),
            productType: (string)$row->getData(ProductIdentityRegistry::schema_fields_PRODUCT_TYPE),
            lifecycleStatus: (string)$row->getData(ProductIdentityRegistry::schema_fields_LIFECYCLE_STATUS),
            version: (int)$row->getData(ProductIdentityRegistry::schema_fields_VERSION),
            sharePolicy: (string)$row->getData(ProductIdentityRegistry::schema_fields_SHARE_POLICY),
        );
    }

    private function toOfferIdentity(OfferIdentityRegistry $row): OfferIdentityV2
    {
        return $this->toOfferIdentityRow($row->getData());
    }

    /** @param array<string,mixed> $row */
    private function toOfferIdentityRow(array $row): OfferIdentityV2
    {
        return new OfferIdentityV2(
            registryId: (int)($row[OfferIdentityRegistry::schema_fields_ID] ?? 0),
            globalOfferUuid: (string)($row[OfferIdentityRegistry::schema_fields_UUID] ?? ''),
            globalProductUuid: (string)($row[OfferIdentityRegistry::schema_fields_PRODUCT_UUID] ?? ''),
            sku: (string)($row[OfferIdentityRegistry::schema_fields_SKU] ?? ''),
            status: (string)($row[OfferIdentityRegistry::schema_fields_STATUS] ?? ''),
            version: (int)($row[OfferIdentityRegistry::schema_fields_VERSION] ?? 0),
        );
    }

    private function normalizeWebsiteId(int $websiteId): int
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id_invalid');
        }
        return $websiteId;
    }

    private function normalizeCode(string $code, string $field): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code)) {
            throw new \InvalidArgumentException($field . '_invalid');
        }
        return $code;
    }

    private function normalizeSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '' || strlen($sku) > 128) {
            throw new \InvalidArgumentException('sku_invalid');
        }
        return $sku;
    }

    private function normalizeRequestHash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{32,128}$/', $hash)) {
            throw new \InvalidArgumentException('request_hash_invalid');
        }
        return $hash;
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
            throw new \InvalidArgumentException('uuid_invalid');
        }
        return $uuid;
    }

    private function productCode(string $uuid): string
    {
        return 'SPU-' . strtoupper(substr(str_replace('-', '', $uuid), 0, 16));
    }

    private function uuidFromRequestHash(string $requestHash, string $scope): string
    {
        $hex = substr(hash('sha256', strtolower(trim($scope)) . ':' . strtolower(trim($requestHash))), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
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

    private function newProductRegistry(): ProductIdentityRegistry
    {
        return $this->productRegistryFactory !== null
            ? ($this->productRegistryFactory)()
            : ObjectManager::make(ProductIdentityRegistry::class);
    }

    private function newOfferRegistry(): OfferIdentityRegistry
    {
        return $this->offerRegistryFactory !== null
            ? ($this->offerRegistryFactory)()
            : ObjectManager::make(OfferIdentityRegistry::class);
    }

    private function newOfferAlias(): OfferSkuAlias
    {
        return $this->offerAliasFactory !== null
            ? ($this->offerAliasFactory)()
            : ObjectManager::make(OfferSkuAlias::class);
    }

    private function newAudit(): ProductAuditLog
    {
        return $this->auditFactory !== null
            ? ($this->auditFactory)()
            : ObjectManager::make(ProductAuditLog::class);
    }
}
