<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
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

    /** @var array<string,list<?int>> Active owner mutations; nested repositories share their event. */
    private array $activeTargets = [];
    private readonly \Closure $urlSnapshot;
    private readonly NamespacePath $namespacePath;

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ProductSearchProjectionStream $stream,
        private readonly ResourceChangeFactory $changes,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
        ?callable $urlSnapshot = null,
        ?NamespacePath $namespacePath = null,
    ) {
        // Resolve lazily: URL reads use repositories which themselves depend on this coordinator.
        $this->urlSnapshot = $urlSnapshot === null
            ? static fn(int $websiteId, int $productId, ?int $storeId): array => ObjectManager::getInstance(
                ProductSitemapUrlService::class,
            )->getUrlsForProduct($websiteId, $productId, $storeId)
            : \Closure::fromCallable($urlSnapshot);
        $this->namespacePath = $namespacePath ?? new NamespacePath();
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
        $targetKey = spl_object_id($connection) . ':' . $websiteId . ':' . $targetId;
        foreach ($this->activeTargets[$targetKey] ?? [] as $activeStoreId) {
            if ($activeStoreId === null || $activeStoreId === $storeId) {
                return $mutation();
            }
        }
        $operation = function () use (
            $mutation,
            $websiteId,
            $targetType,
            $targetId,
            $storeId,
            $targetKey,
            $connection,
        ): mixed {
            $this->activeTargets[$targetKey][] = $storeId;
            try {
                $previousUrls = ($this->urlSnapshot)($websiteId, $targetId, $storeId);
                $result = $mutation();
                $currentUrls = ($this->urlSnapshot)($websiteId, $targetId, $storeId);
                $eventSeq = $this->stream->next($websiteId);
                $website = $this->website($websiteId);
                $store = $storeId === null ? null : $this->store($websiteId, $storeId);
                $after = [
                    'contract' => self::CONTRACT,
                    'event_seq' => $eventSeq,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'scope_kind' => $store === null ? 'website' : 'store',
                    'store_ids' => array_values(array_unique(array_map('intval', array_column(
                        array_merge($previousUrls, $currentUrls),
                        'store_id',
                    )))),
                ];
                if ($store !== null) {
                    $after['store_id'] = $store->id;
                }
                $catalogNamespace = $this->namespacePath->website($website->code, ['catalog']);
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
                    impact: [
                        'namespaces' => [$catalogNamespace],
                        'urls' => array_column($currentUrls, 'loc'),
                        'previous_urls' => array_column($previousUrls, 'loc'),
                    ],
                    origin: ['entry' => 'product.search_projection.' . $targetType],
                    siteId: $websiteId,
                );
                \w_changed($change);
                // FPC/CDN 失效由 Changed Extends Capability 承接；禁止业务旁路整池清

                return $result;
            } finally {
                array_pop($this->activeTargets[$targetKey]);
                if ($this->activeTargets[$targetKey] === []) {
                    unset($this->activeTargets[$targetKey]);
                }
            }
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
        if ($storeId !== null && $storeId < 0) {
            throw new \InvalidArgumentException((string)__(
                'StoreProduct 投影 store_id 不能为负数：%{1}',
                [$storeId],
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
