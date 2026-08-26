<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ProductIdentityV2ResolverInterface;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Product-owned public current source for Search projection consumers.
 */
final class ProductSearchProjectionService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly StoreProductRepository $storeProducts,
        private readonly OfferRepository $offers,
        private readonly StoreOfferRepository $storeOffers,
        private readonly ProductIdentityV2ResolverInterface $identities,
        private readonly ProductSearchProjectionStream $stream,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
    ) {
    }

    public function currentWatermark(int $websiteId): int
    {
        $this->website($websiteId);

        return $this->stream->current($websiteId);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshotWebsite(int $websiteId): array
    {
        $website = $this->website($websiteId);
        $watermark = $this->stream->current($websiteId);
        $scopes = $this->activeScopes($websiteId);
        $publishedProducts = [];
        foreach ($this->products->listAll($websiteId) as $row) {
            if ((string)($row[Product::schema_fields_STATUS] ?? '') !== Product::STATUS_PUBLISHED) {
                continue;
            }
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                throw new \RuntimeException((string)__(
                    'Product Search 快照包含非法 product_id',
                ));
            }
            $publishedProducts[$productId] = $row;
        }

        $offersByProduct = [];
        foreach ($this->offers->listByProductIds($websiteId, array_keys($publishedProducts)) as $offer) {
            if ((string)($offer[Offer::schema_fields_STATUS] ?? '') !== Offer::STATUS_PUBLISHED) {
                continue;
            }
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if (isset($publishedProducts[$productId])) {
                $offersByProduct[$productId][] = $offer;
            }
        }

        $documents = [];
        foreach ($publishedProducts as $productId => $product) {
            foreach ($scopes as $scope) {
                $storeId = $scope['store']->id;
                if (!$this->storeProducts->isSelected($websiteId, $storeId, $productId)) {
                    continue;
                }
                foreach ($offersByProduct[$productId] ?? [] as $offer) {
                    $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
                    if ($offerId <= 0 || !$this->storeOffers->isSelected($websiteId, $storeId, $offerId)) {
                        continue;
                    }
                    $documents[] = $this->document(
                        $website,
                        $scope,
                        $product,
                        $offer,
                        $watermark,
                    );
                }
            }
        }
        $this->sortDocuments($documents);

        return [
            'contract' => 'product.search_projection_snapshot.v1',
            'identity_contract' => 'product.offer_identity.v2',
            'website_id' => $websiteId,
            'source_watermark' => $watermark,
            'scope_count' => \count($scopes),
            'document_count' => \count($documents),
            'documents' => $documents,
            'snapshot_hash' => $this->hashDocuments($documents),
        ];
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    public function projectChange(array $change): array
    {
        $websiteId = $this->requiredInt($change, 'website_id', 0);
        $eventSeq = $this->requiredInt($change, 'event_seq', 1);
        $targetType = \trim((string)($change['target_type'] ?? ''));
        if (!\in_array($targetType, [
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT,
            ProductSearchProjectionMutationCoordinatorInterface::TARGET_STORE_PRODUCT,
        ], true)) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 增量目标类型无效：%{1}',
                [$targetType],
            ));
        }
        $productId = $this->requiredInt($change, 'target_id', 1);
        $website = $this->website($websiteId);
        $currentWatermark = $this->stream->current($websiteId);
        if ($eventSeq > $currentWatermark) {
            throw new \RuntimeException((string)__(
                'Product Search 事件水位超前：event=%{1} current=%{2}',
                [$eventSeq, $currentWatermark],
            ));
        }

        $storeId = null;
        if ($targetType === ProductSearchProjectionMutationCoordinatorInterface::TARGET_STORE_PRODUCT) {
            $storeId = $this->requiredInt($change, 'store_id', 1);
        }
        $scopes = $this->activeScopes($websiteId, $storeId);
        $productOffers = $this->offers->listByProductIds($websiteId, [$productId]);
        $deleteKeys = [];
        foreach ($scopes as $scope) {
            $deleteKeys[] = $this->legacyDocumentIdentity($website, $scope, $productId);
            foreach ($productOffers as $offer) {
                $offerUuid = \trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
                if ($offerUuid !== '') {
                    $deleteKeys[] = $this->documentIdentity($website, $scope, $offerUuid);
                }
            }
        }
        $this->sortDocuments($deleteKeys);

        $documents = [];
        $product = $this->products->findById($websiteId, $productId);
        if ($product !== null
            && (string)$product->getData(Product::schema_fields_STATUS) === Product::STATUS_PUBLISHED
        ) {
            $productRow = $product->getData();
            foreach ($scopes as $scope) {
                $scopeStoreId = $scope['store']->id;
                if (!$this->storeProducts->isSelected($websiteId, $scopeStoreId, $productId)) {
                    continue;
                }
                foreach ($productOffers as $offer) {
                    if ((string)($offer[Offer::schema_fields_STATUS] ?? '') !== Offer::STATUS_PUBLISHED) {
                        continue;
                    }
                    $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
                    if ($offerId <= 0
                        || !$this->storeOffers->isSelected($websiteId, $scopeStoreId, $offerId)
                    ) {
                        continue;
                    }
                    $documents[] = $this->document(
                        $website,
                        $scope,
                        $productRow,
                        $offer,
                        $currentWatermark,
                    );
                }
            }
        }
        $this->sortDocuments($documents);

        return [
            'contract' => 'product.search_projection_change.v1',
            'identity_contract' => 'product.offer_identity.v2',
            'website_id' => $websiteId,
            'event_seq' => $eventSeq,
            'source_watermark' => $currentWatermark,
            'documents' => $documents,
            'delete_keys' => $deleteKeys,
        ];
    }

    /**
     * @return list<array{store:StoreSummary,channel:\Weline\Websites\Api\Catalog\Data\SalesChannelSummary}>
     */
    private function activeScopes(int $websiteId, ?int $onlyStoreId = null): array
    {
        $scopes = [];
        foreach ($this->stores->byWebsite($websiteId) as $store) {
            if ($onlyStoreId !== null && $store->id !== $onlyStoreId) {
                continue;
            }
            if (!$store->enabled || $store->lifecycleStatus !== 'active') {
                continue;
            }
            foreach ($this->channels->byStore($store->id) as $channel) {
                if (!$channel->effectiveEnabled || $channel->websiteId !== $websiteId) {
                    continue;
                }
                $scopes[] = ['store' => $store, 'channel' => $channel];
            }
        }
        if ($onlyStoreId !== null && $scopes === []) {
            throw new \RuntimeException((string)__(
                'Product Search 找不到可用 Store/Channel Scope：store_id=%{1}',
                [$onlyStoreId],
            ));
        }
        if ($onlyStoreId === null && $scopes === []) {
            throw new \RuntimeException((string)__(
                'Product Search Website 没有可用 Store/Channel Scope：website_id=%{1}',
                [$websiteId],
            ));
        }

        return $scopes;
    }

    /**
     * @param array{store:StoreSummary,channel:\Weline\Websites\Api\Catalog\Data\SalesChannelSummary} $scope
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function document(
        WebsiteSummary $website,
        array $scope,
        array $product,
        array $offer,
        int $documentVersion,
    ): array {
        $productId = (int)($product[Product::schema_fields_ID] ?? 0);
        $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
        $offerUuid = \trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
        $offerIdentity = $offerUuid === ''
            ? null
            : $this->identities->resolveOfferByUuid($offerUuid);
        $sku = \trim($offerIdentity?->sku
            ?? (string)($offer[Offer::schema_fields_SKU] ?? ''));
        if ($productId <= 0 || $offerId <= 0 || $offerUuid === '' || $sku === '') {
            throw new \RuntimeException((string)__(
                'Product Search published Offer 投影缺少 Product/Offer 身份或 SKU',
            ));
        }

        $productUuid = \trim($offerIdentity?->globalProductUuid
            ?? (string)($product[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
        $productIdentity = $productUuid === ''
            ? null
            : $this->identities->resolveProductByUuid($productUuid);
        $identity = $this->documentIdentity($website, $scope, $offerUuid);

        return $identity + [
            'title' => $sku,
            'keywords' => \trim($sku . ' ' . ($productIdentity?->productCode ?? '')),
            'url' => 'product/' . $productId,
            'product_id' => $productId,
            'offer_id' => $offerId,
            'global_product_uuid' => $productUuid,
            'global_offer_uuid' => $offerUuid,
            'product_code' => $productIdentity?->productCode ?? '',
            'owner_website_id' => $productIdentity?->ownerWebsiteId ?? $website->id,
            'provider_code' => $productIdentity?->providerCode ?? 'default',
            'product_type' => $productIdentity?->productType ?? 'simple',
            'product_identity_version' => $productIdentity?->version ?? 0,
            'offer_identity_version' => $offerIdentity?->version
                ?? (int)($offer[Offer::schema_fields_IDENTITY_VERSION] ?? 0),
            'offer_identity_status' => $offerIdentity?->status
                ?? (string)($offer[Offer::schema_fields_STATUS] ?? ''),
            'sku' => $sku,
            'status' => Product::STATUS_PUBLISHED,
            'document_version' => $documentVersion,
        ];
    }

    /**
     * @param array{store:StoreSummary,channel:\Weline\Websites\Api\Catalog\Data\SalesChannelSummary} $scope
     * @return array<string,mixed>
     */
    private function documentIdentity(
        WebsiteSummary $website,
        array $scope,
        string $globalOfferUuid,
    ): array {
        return [
            'entity_type' => 'product_offer',
            'entity_id' => $globalOfferUuid,
            'website_id' => $website->id,
            'website_code' => $website->code,
            'store_id' => $scope['store']->id,
            'store_code' => $scope['store']->code,
            'channel_id' => $scope['channel']->id,
            'channel_code' => $scope['channel']->code,
            'locale' => '',
            'currency' => '',
        ];
    }

    private function legacyDocumentIdentity(
        WebsiteSummary $website,
        array $scope,
        int $productId,
    ): array {
        return [
            'entity_type' => 'product',
            'entity_id' => (string)$productId,
            'website_id' => $website->id,
            'website_code' => $website->code,
            'store_id' => $scope['store']->id,
            'store_code' => $scope['store']->code,
            'channel_id' => $scope['channel']->id,
            'channel_code' => $scope['channel']->code,
            'locale' => '',
            'currency' => '',
        ];
    }

    private function website(int $websiteId): WebsiteSummary
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__(
                'website_id 不能为负数：%{1}',
                [$websiteId],
            ));
        }
        foreach ($this->websites->all() as $website) {
            if ($website->id === $websiteId && \trim($website->code) !== '') {
                return $website;
            }
        }

        throw new \RuntimeException((string)__(
            'Product Search 找不到 Website：%{1}',
            [$websiteId],
        ));
    }

    /**
     * @param list<array<string,mixed>> $documents
     */
    private function sortDocuments(array &$documents): void
    {
        \usort(
            $documents,
            static fn(array $left, array $right): int => [
                (int)$left['website_id'],
                (int)$left['store_id'],
                (int)$left['channel_id'],
                (string)$left['entity_type'],
                (string)$left['entity_id'],
            ] <=> [
                (int)$right['website_id'],
                (int)$right['store_id'],
                (int)$right['channel_id'],
                (string)$right['entity_type'],
                (string)$right['entity_id'],
            ],
        );
    }

    /**
     * @param list<array<string,mixed>> $documents
     */
    private function hashDocuments(array $documents): string
    {
        return \hash('sha256', (string)\json_encode(
            $documents,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function requiredInt(array $data, string $key, int $minimum): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)
            && !(\is_string($value) && \preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1)
        ) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 参数 %{1} 必须是规范整数',
                [$key],
            ));
        }
        $value = (int)$value;
        if ($value < $minimum) {
            throw new \InvalidArgumentException((string)__(
                'Product Search 参数 %{1} 不能小于 %{2}',
                [$key, $minimum],
            ));
        }

        return $value;
    }
}
