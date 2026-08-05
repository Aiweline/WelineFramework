<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Atomically persists a Product mutation, advances its projection stream and
 * emits a durable ResourceChange event.
 */
final class ProductSearchProjectionMutationCoordinator implements ProductSearchProjectionMutationCoordinatorInterface
{
    public const RESOURCE_TYPE = ProductSearchProjectionMutationCoordinatorInterface::RESOURCE_TYPE;
    public const CONTRACT = ProductSearchProjectionMutationCoordinatorInterface::CONTRACT;

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ProductSearchProjectionStream $stream,
        private readonly ResourceChangeFactory $changes,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
    ) {
    }

    public function execute(
        ConnectionFactory $connection,
        int $websiteId,
        string $targetType,
        int $targetId,
        ?int $storeId,
        callable $mutation,
    ): mixed {
        $this->assertTarget($websiteId, $targetType, $targetId, $storeId);
        $operation = function () use (
            $mutation,
            $websiteId,
            $targetType,
            $targetId,
            $storeId,
        ): mixed {
            $result = $mutation();
            $eventSeq = $this->stream->next($websiteId);
            $website = $this->website($websiteId);
            $store = $storeId === null ? null : $this->store($websiteId, $storeId);
            $after = [
                'contract' => self::CONTRACT,
                'event_seq' => $eventSeq,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'scope_kind' => $store === null ? 'website' : 'store',
            ];
            if ($store !== null) {
                $after['store_id'] = $store->id;
            }
            $change = $this->changes->create(
                resourceType: self::RESOURCE_TYPE,
                resourceId: $websiteId . ':' . $eventSeq,
                action: 'upsert',
                revision: $eventSeq,
                websiteId: $websiteId,
                websiteCode: $website->code,
                before: [],
                after: $after,
                changedFields: \array_keys($after),
                impact: [],
                origin: ['entry' => 'product.search_projection.' . $targetType],
                siteId: $websiteId,
            );
            \w_changed($change);

            return $result;
        };

        if ($this->transactions->isActive($connection)) {
            return $operation();
        }

        return $this->transactions->run($connection, $operation);
    }

    private function assertTarget(
        int $websiteId,
        string $targetType,
        int $targetId,
        ?int $storeId,
    ): void {
        if ($websiteId < 0 || $targetId <= 0) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 投影目标身份无效',
            ));
        }
        if (!\in_array($targetType, [self::TARGET_PRODUCT, self::TARGET_STORE_PRODUCT], true)) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 投影目标类型无效：%{1}',
                [$targetType],
            ));
        }
        if (($targetType === self::TARGET_STORE_PRODUCT) !== ($storeId !== null)) {
            throw new \InvalidArgumentException((string)__(
                'StoreProduct 投影事件必须且只能提供 store_id',
            ));
        }
        if ($storeId !== null && $storeId <= 0) {
            throw new \InvalidArgumentException((string)__(
                'StoreProduct 投影 store_id 必须为正整数',
            ));
        }
    }

    private function website(int $websiteId): WebsiteSummary
    {
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId) {
                if (\trim($website->code) === '') {
                    break;
                }

                return $website;
            }
        }

        throw new \RuntimeException((string)__(
            'Product Search 投影找不到 Website：%{1}',
            [$websiteId],
        ));
    }

    private function store(int $websiteId, int $storeId): StoreSummary
    {
        $store = $this->stores->byId($storeId);
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new \RuntimeException((string)__(
                'Product Search 投影 Store 不属于 Website：store_id=%{1} website_id=%{2}',
                [$storeId, $websiteId],
            ));
        }

        return $store;
    }
}
