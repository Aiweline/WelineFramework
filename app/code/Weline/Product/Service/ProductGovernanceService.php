<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductIdentityV2;
use Weline\Product\Model\ProductAuditLog;
use Weline\Product\Model\ProductIdentityRegistry;
use Weline\Product\Model\ProductOwnershipTransfer;
use Weline\Product\Model\ProductShareGrant;

/**
 * Owner-only sharing and two-party ownership transfer with optimistic versions.
 */
final class ProductGovernanceService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly ProductIdentityV2Service $identities,
    ) {
    }

    public function setShare(
        string $productUuid,
        int $ownerWebsiteId,
        int $targetWebsiteId,
        bool $allowed,
        int $expectedProductVersion,
        string $requestHash,
    ): ProductIdentityV2 {
        if ($targetWebsiteId < 0 || $targetWebsiteId === $ownerWebsiteId) {
            throw new \InvalidArgumentException('share_target_website_invalid');
        }

        return $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $productUuid,
                $ownerWebsiteId,
                $targetWebsiteId,
                $allowed,
                $expectedProductVersion,
                $requestHash,
            ): ProductIdentityV2 {
                $product = $this->requireProduct($productUuid);
                $this->assertOwnerAndVersion($product, $ownerWebsiteId, $expectedProductVersion);
                $grant = $this->newGrant()->clear()
                    ->where(ProductShareGrant::schema_fields_PRODUCT_UUID, $productUuid)
                    ->where(ProductShareGrant::schema_fields_TARGET_WEBSITE_ID, $targetWebsiteId)
                    ->find()->fetch();
                $now = date('Y-m-d H:i:s');
                $status = $allowed ? ProductShareGrant::STATUS_ACTIVE : ProductShareGrant::STATUS_REVOKED;
                if ($grant->getId()) {
                    $grant->setData(ProductShareGrant::schema_fields_STATUS, $status)
                        ->setData(
                            ProductShareGrant::schema_fields_VERSION,
                            (int)$grant->getData(ProductShareGrant::schema_fields_VERSION) + 1,
                        )
                        ->setData(ProductShareGrant::schema_fields_REQUEST_HASH, $requestHash)
                        ->setData(ProductShareGrant::schema_fields_UPDATED_AT, $now)
                        ->save();
                } else {
                    $this->newGrant()->clear()->setData([
                        ProductShareGrant::schema_fields_PRODUCT_UUID => $productUuid,
                        ProductShareGrant::schema_fields_TARGET_WEBSITE_ID => $targetWebsiteId,
                        ProductShareGrant::schema_fields_STATUS => $status,
                        ProductShareGrant::schema_fields_VERSION => 1,
                        ProductShareGrant::schema_fields_REQUEST_HASH => $requestHash,
                        ProductShareGrant::schema_fields_CREATED_AT => $now,
                        ProductShareGrant::schema_fields_UPDATED_AT => $now,
                    ])->save();
                }
                $this->bumpProduct(
                    $product,
                    $expectedProductVersion,
                    $requestHash,
                    'product.share.' . ($allowed ? 'granted' : 'revoked'),
                    ['target_website_id' => $targetWebsiteId],
                );
                return $this->identities->resolveProductByUuid($productUuid)
                    ?? throw new \RuntimeException('product_share_readback_failed');
            },
        );
    }

    public function canCopy(string $productUuid, int $targetWebsiteId): bool
    {
        $product = $this->requireProduct($productUuid);
        $owner = (int)$product->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID);
        if ($targetWebsiteId === $owner) {
            return true;
        }
        $policy = (string)$product->getData(ProductIdentityRegistry::schema_fields_SHARE_POLICY);
        if ($owner === 0 && $policy === ProductIdentityRegistry::SHARE_DEFAULT_SITE) {
            return true;
        }
        $grant = $this->newGrant()->clear()
            ->where(ProductShareGrant::schema_fields_PRODUCT_UUID, $productUuid)
            ->where(ProductShareGrant::schema_fields_TARGET_WEBSITE_ID, $targetWebsiteId)
            ->where(ProductShareGrant::schema_fields_STATUS, ProductShareGrant::STATUS_ACTIVE)
            ->find()->fetch();
        return (bool)$grant->getId();
    }

    public function initiateTransfer(
        string $productUuid,
        int $sourceWebsiteId,
        int $targetWebsiteId,
        int $expectedProductVersion,
        string $requestHash,
    ): string {
        if ($targetWebsiteId < 0 || $targetWebsiteId === $sourceWebsiteId) {
            throw new \InvalidArgumentException('transfer_target_website_invalid');
        }
        return $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $productUuid,
                $sourceWebsiteId,
                $targetWebsiteId,
                $expectedProductVersion,
                $requestHash,
            ): string {
                $product = $this->requireProduct($productUuid);
                $this->assertOwnerAndVersion($product, $sourceWebsiteId, $expectedProductVersion);
                $pending = $this->newTransfer()->clear()
                    ->where(ProductOwnershipTransfer::schema_fields_PRODUCT_UUID, $productUuid)
                    ->where(
                        ProductOwnershipTransfer::schema_fields_STATUS,
                        ProductOwnershipTransfer::STATUS_PENDING,
                    )->find()->fetch();
                if ($pending->getId()) {
                    if ((int)$pending->getData(ProductOwnershipTransfer::schema_fields_TARGET_WEBSITE_ID)
                        !== $targetWebsiteId
                    ) {
                        throw new ProductV2ConflictException(
                            'ownership_transfer_pending',
                            __('商品已有待确认的归属转让'),
                        );
                    }
                    return (string)$pending->getData(ProductOwnershipTransfer::schema_fields_UUID);
                }
                $transferUuid = $this->newUuid();
                $this->newTransfer()->clear()->setData([
                    ProductOwnershipTransfer::schema_fields_UUID => $transferUuid,
                    ProductOwnershipTransfer::schema_fields_PRODUCT_UUID => $productUuid,
                    ProductOwnershipTransfer::schema_fields_SOURCE_WEBSITE_ID => $sourceWebsiteId,
                    ProductOwnershipTransfer::schema_fields_TARGET_WEBSITE_ID => $targetWebsiteId,
                    ProductOwnershipTransfer::schema_fields_PRODUCT_VERSION => $expectedProductVersion,
                    ProductOwnershipTransfer::schema_fields_STATUS => ProductOwnershipTransfer::STATUS_PENDING,
                    ProductOwnershipTransfer::schema_fields_REQUEST_HASH => $requestHash,
                    ProductOwnershipTransfer::schema_fields_REQUESTED_AT => date('Y-m-d H:i:s'),
                ])->save();
                $this->audit(
                    $productUuid,
                    $sourceWebsiteId,
                    'product.ownership.transfer_requested',
                    $expectedProductVersion,
                    $expectedProductVersion,
                    $requestHash,
                    ['target_website_id' => $targetWebsiteId, 'transfer_uuid' => $transferUuid],
                );
                return $transferUuid;
            },
        );
    }

    public function confirmTransfer(
        string $transferUuid,
        int $targetWebsiteId,
        string $requestHash,
    ): ProductIdentityV2 {
        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($transferUuid, $targetWebsiteId, $requestHash): ProductIdentityV2 {
                $transfer = $this->newTransfer()->clear()
                    ->where(ProductOwnershipTransfer::schema_fields_UUID, $transferUuid)
                    ->find()->fetch();
                if (!$transfer->getId()) {
                    throw new \InvalidArgumentException('ownership_transfer_not_found');
                }
                if ((string)$transfer->getData(ProductOwnershipTransfer::schema_fields_STATUS)
                    !== ProductOwnershipTransfer::STATUS_PENDING
                    || (int)$transfer->getData(ProductOwnershipTransfer::schema_fields_TARGET_WEBSITE_ID)
                    !== $targetWebsiteId
                ) {
                    throw new ProductV2ConflictException(
                        'ownership_transfer_confirmation_forbidden',
                        __('只能由目标 Website 确认待处理转让'),
                    );
                }
                $productUuid = (string)$transfer->getData(ProductOwnershipTransfer::schema_fields_PRODUCT_UUID);
                $product = $this->requireProduct($productUuid);
                $expectedVersion = (int)$transfer->getData(
                    ProductOwnershipTransfer::schema_fields_PRODUCT_VERSION,
                );
                $sourceWebsiteId = (int)$transfer->getData(
                    ProductOwnershipTransfer::schema_fields_SOURCE_WEBSITE_ID,
                );
                $this->assertOwnerAndVersion($product, $sourceWebsiteId, $expectedVersion);
                $nextVersion = $expectedVersion + 1;
                $this->newProduct()->clear()->getQuery()
                    ->where(ProductIdentityRegistry::schema_fields_UUID, $productUuid)
                    ->where(ProductIdentityRegistry::schema_fields_VERSION, $expectedVersion)
                    ->update([
                        ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID => $targetWebsiteId,
                        ProductIdentityRegistry::schema_fields_VERSION => $nextVersion,
                        ProductIdentityRegistry::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                    ])->fetch();
                $transfer->setData(
                    ProductOwnershipTransfer::schema_fields_STATUS,
                    ProductOwnershipTransfer::STATUS_CONFIRMED,
                )->setData(
                    ProductOwnershipTransfer::schema_fields_RESOLVED_AT,
                    date('Y-m-d H:i:s'),
                )->save();
                $this->audit(
                    $productUuid,
                    $targetWebsiteId,
                    'product.ownership.transferred',
                    $expectedVersion,
                    $nextVersion,
                    $requestHash,
                    ['source_website_id' => $sourceWebsiteId],
                );
                return $this->identities->resolveProductByUuid($productUuid)
                    ?? throw new \RuntimeException('ownership_transfer_readback_failed');
            },
        );
    }

    private function bumpProduct(
        ProductIdentityRegistry $product,
        int $expectedVersion,
        string $requestHash,
        string $action,
        array $payload,
    ): void {
        $uuid = (string)$product->getData(ProductIdentityRegistry::schema_fields_UUID);
        $websiteId = (int)$product->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID);
        $next = $expectedVersion + 1;
        $this->newProduct()->clear()->getQuery()
            ->where(ProductIdentityRegistry::schema_fields_UUID, $uuid)
            ->where(ProductIdentityRegistry::schema_fields_VERSION, $expectedVersion)
            ->update([
                ProductIdentityRegistry::schema_fields_VERSION => $next,
                ProductIdentityRegistry::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])->fetch();
        $current = $this->requireProduct($uuid);
        if ((int)$current->getData(ProductIdentityRegistry::schema_fields_VERSION) !== $next) {
            throw new ProductV2ConflictException(
                'product_version_conflict',
                __('版本已变化，请刷新后重试'),
            );
        }
        $this->audit($uuid, $websiteId, $action, $expectedVersion, $next, $requestHash, $payload);
    }

    private function assertOwnerAndVersion(
        ProductIdentityRegistry $product,
        int $websiteId,
        int $version,
    ): void {
        $owner = (int)$product->getData(ProductIdentityRegistry::schema_fields_OWNER_WEBSITE_ID);
        $actualVersion = (int)$product->getData(ProductIdentityRegistry::schema_fields_VERSION);
        if ($owner !== $websiteId) {
            throw new ProductV2ConflictException(
                'product_owner_required',
                __('仅归属 Website 可执行该操作'),
                ['owner_website_id' => $owner],
            );
        }
        if ($actualVersion !== $version) {
            throw new ProductV2ConflictException(
                'product_version_conflict',
                __('版本已变化，请刷新后重试'),
                ['expected_version' => $version, 'actual_version' => $actualVersion],
            );
        }
    }

    private function requireProduct(string $uuid): ProductIdentityRegistry
    {
        $row = $this->newProduct()->clear()
            ->where(ProductIdentityRegistry::schema_fields_UUID, $uuid)
            ->find()->fetch();
        if (!$row->getId()) {
            throw new \InvalidArgumentException('product_v2_identity_not_found');
        }
        return $row;
    }

    private function audit(
        string $productUuid,
        int $websiteId,
        string $action,
        int $before,
        int $after,
        string $requestHash,
        array $payload,
    ): void {
        $this->newAudit()->clear()->setData([
            ProductAuditLog::schema_fields_PRODUCT_UUID => $productUuid,
            ProductAuditLog::schema_fields_WEBSITE_ID => $websiteId,
            ProductAuditLog::schema_fields_ACTION => $action,
            ProductAuditLog::schema_fields_BEFORE_VERSION => $before,
            ProductAuditLog::schema_fields_AFTER_VERSION => $after,
            ProductAuditLog::schema_fields_REQUEST_HASH => $requestHash,
            ProductAuditLog::schema_fields_PAYLOAD_JSON => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            ProductAuditLog::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    private function newProduct(): ProductIdentityRegistry
    {
        return ObjectManager::make(ProductIdentityRegistry::class);
    }

    private function newGrant(): ProductShareGrant
    {
        return ObjectManager::make(ProductShareGrant::class);
    }

    private function newTransfer(): ProductOwnershipTransfer
    {
        return ObjectManager::make(ProductOwnershipTransfer::class);
    }

    private function newAudit(): ProductAuditLog
    {
        return ObjectManager::make(ProductAuditLog::class);
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
}
