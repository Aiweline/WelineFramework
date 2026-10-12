<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Changed\UrlMatrixExpander;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\ProductRepository;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Atomically persists a Product mutation, advances its projection stream and
 * emits a durable ResourceChange event.
 *
 * TARGET_PRODUCT (website-level / shared catalog identity): impact URLs and
 * catalog namespaces cover every website that carries the same global_product_uuid.
 * TARGET_STORE_PRODUCT (子站站内覆写): stays scoped to that website + store.
 */
final class ProductSearchProjectionMutationCoordinator implements ProductSearchProjectionMutationCoordinatorInterface
{
    public const RESOURCE_TYPE = ProductSearchProjectionMutationCoordinatorInterface::RESOURCE_TYPE;
    public const CONTRACT = ProductSearchProjectionMutationCoordinatorInterface::CONTRACT;

    /** @var array<string,list<?int>> Active owner mutations; nested repositories share their event. */
    private array $activeTargets = [];
    private readonly \Closure $urlImpact;
    private readonly NamespacePath $namespacePath;

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ProductSearchProjectionStream $stream,
        private readonly ResourceChangeFactory $changes,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
        ?callable $urlImpact = null,
        ?NamespacePath $namespacePath = null,
    ) {
        // Resolve lazily: URL reads use repositories which themselves depend on this coordinator.
        $this->urlImpact = $urlImpact === null
            ? \Closure::fromCallable([$this, 'defaultUrlImpact'])
            : \Closure::fromCallable($urlImpact);
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
                /** @var array{urls:list<array<string,mixed>>,namespaces:list<string>,listing:list<string>,matrix_ready:bool,website_ids:list<int>} $previousImpact */
                $previousImpact = ($this->urlImpact)($websiteId, $targetType, $targetId, $storeId);
                $result = $mutation();
                /** @var array{urls:list<array<string,mixed>>,namespaces:list<string>,listing:list<string>,matrix_ready:bool,website_ids:list<int>} $currentImpact */
                $currentImpact = ($this->urlImpact)($websiteId, $targetType, $targetId, $storeId);
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
                        array_merge($previousImpact['urls'], $currentImpact['urls']),
                        'store_id',
                    )))),
                ];
                if ($store !== null) {
                    $after['store_id'] = $store->id;
                }
                $namespaces = array_values(array_unique(array_merge(
                    $previousImpact['namespaces'],
                    $currentImpact['namespaces'],
                )));
                if ($namespaces === []) {
                    $namespaces = [$this->namespacePath->website($website->code, ['catalog'])];
                }
                $matrixReady = ($previousImpact['matrix_ready'] ?? false) || ($currentImpact['matrix_ready'] ?? false);
                $urlLocs = array_values(array_unique(array_merge(
                    array_column($currentImpact['urls'], 'loc'),
                    $currentImpact['listing'],
                )));
                $previousLocs = array_values(array_unique(array_merge(
                    array_column($previousImpact['urls'], 'loc'),
                    $previousImpact['listing'],
                )));
                if ($matrixReady) {
                    $urlLocs = $this->expandMatrixByWebsiteRows($currentImpact['urls'], $currentImpact['listing']);
                    $previousLocs = $this->expandMatrixByWebsiteRows($previousImpact['urls'], $previousImpact['listing']);
                }
                $websiteIds = array_values(array_unique(array_map(
                    'intval',
                    array_merge(
                        [$websiteId],
                        $previousImpact['website_ids'] ?? [],
                        $currentImpact['website_ids'] ?? [],
                    ),
                )));
                $impact = [
                    'namespaces' => $namespaces,
                    'urls' => $urlLocs,
                    'previous_urls' => $previousLocs,
                    // CDN Capability loads domains for these sites (not only change.websiteId).
                    'website_ids' => $websiteIds,
                ];
                if ($matrixReady) {
                    // Already × locale×currency per member website; pipeline must not re-expand with mutation site defaults.
                    $impact['url_matrix_ready'] = true;
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
                    impact: $impact,
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

    /**
     * @return array{
     *     urls:list<array<string,mixed>>,
     *     namespaces:list<string>,
     *     listing:list<string>,
     *     matrix_ready:bool,
     *     website_ids:list<int>
     * }
     */
    private function defaultUrlImpact(
        int $websiteId,
        string $targetType,
        int $targetId,
        ?int $storeId,
    ): array {
        /** @var ProductSitemapUrlService $urls */
        $urls = ObjectManager::getInstance(ProductSitemapUrlService::class);
        /** @var ProductRepository $products */
        $products = ObjectManager::getInstance(ProductRepository::class);

        if ($targetType === self::TARGET_STORE_PRODUCT) {
            // 子站站内自己的产品 / Store 覆写：只清本站本店
            return [
                'urls' => $urls->getUrlsForProduct($websiteId, $targetId, $storeId),
                'namespaces' => [$this->namespacePath->website($this->website($websiteId)->code, ['catalog'])],
                'listing' => ['/', '/products'],
                'matrix_ready' => false,
                'website_ids' => [$websiteId],
            ];
        }

        $product = $products->findById($websiteId, $targetId);
        $globalUuid = trim((string)($product?->getData(Product::schema_fields_GLOBAL_PRODUCT_UUID) ?? ''));
        if ($globalUuid === '') {
            return [
                'urls' => $urls->getUrlsForProduct($websiteId, $targetId, null),
                'namespaces' => [$this->namespacePath->website($this->website($websiteId)->code, ['catalog'])],
                'listing' => ['/', '/products'],
                'matrix_ready' => false,
                'website_ids' => [$websiteId],
            ];
        }

        $memberIds = $urls->listWebsiteIdsForGlobalProduct($globalUuid);
        if ($memberIds === []) {
            $memberIds = [$websiteId];
        }
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $crossSite = count($memberIds) > 1
            || (count($memberIds) === 1 && (int)$memberIds[0] !== $websiteId);
        if (!$crossSite) {
            return [
                'urls' => $urls->getUrlsForProduct($websiteId, $targetId, null),
                'namespaces' => [$this->namespacePath->website($this->website($websiteId)->code, ['catalog'])],
                'listing' => ['/', '/products'],
                'matrix_ready' => false,
                'website_ids' => [$websiteId],
            ];
        }
        $namespaces = [];
        foreach ($memberIds as $memberId) {
            $namespaces[] = $this->namespacePath->website($this->website($memberId)->code, ['catalog']);
        }

        return [
            'urls' => $urls->getUrlsForGlobalProduct($globalUuid),
            'namespaces' => array_values(array_unique($namespaces)),
            'listing' => $urls->getCatalogListingUrlsForGlobalProduct($globalUuid),
            // Cross-site absolute locs must expand with each member website's locale/currency defaults.
            'matrix_ready' => true,
            'website_ids' => $memberIds,
        ];
    }

    /**
     * Expand product + listing locs grouped by website_id (path locale×currency per site).
     *
     * @param list<array<string,mixed>> $urlRows
     * @param list<string> $listing
     * @return list<string>
     */
    private function expandMatrixByWebsiteRows(array $urlRows, array $listing): array
    {
        /** @var UrlMatrixExpander $matrix */
        $matrix = ObjectManager::getInstance(UrlMatrixExpander::class);
        $byWebsite = [];
        foreach ($urlRows as $row) {
            $loc = trim((string)($row['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            $wid = (int)($row['website_id'] ?? -1);
            if ($wid < 0) {
                continue;
            }
            $byWebsite[$wid][$loc] = $loc;
        }
        foreach ($listing as $loc) {
            $loc = trim((string)$loc);
            if ($loc === '' || preg_match('#^https?://#i', $loc) !== 1) {
                continue;
            }
            $wid = $this->websiteIdForAbsoluteUrl($loc);
            if ($wid === null) {
                continue;
            }
            $byWebsite[$wid][$loc] = $loc;
        }
        $merged = [];
        foreach ($byWebsite as $wid => $locs) {
            $expanded = $matrix->expand(array_values($locs), (int)$wid);
            foreach ($expanded['urls'] as $url) {
                $merged[$url] = $url;
            }
        }

        return array_values($merged);
    }

    private function websiteIdForAbsoluteUrl(string $url): ?int
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $origin = $scheme . '://' . $host . ':' . $port;
        foreach ($this->websites->all() as $website) {
            $base = rtrim(trim((string)$website->url), '/');
            if ($base === '') {
                continue;
            }
            $wParts = parse_url($base);
            if (!is_array($wParts) || !isset($wParts['scheme'], $wParts['host'])) {
                continue;
            }
            $wScheme = strtolower((string)$wParts['scheme']);
            $wHost = strtolower((string)$wParts['host']);
            $wPort = (int)($wParts['port'] ?? ($wScheme === 'https' ? 443 : 80));
            if ($origin === $wScheme . '://' . $wHost . ':' . $wPort) {
                return $website->id;
            }
        }

        return null;
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
