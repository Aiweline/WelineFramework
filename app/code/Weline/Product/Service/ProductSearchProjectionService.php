<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ProductIdentityV2ResolverInterface;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
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
    /** @var list<string> */
    private const SEARCH_KEYWORD_ATTRIBUTES = [
        'brand',
        'model',
        'short_description',
    ];

    private const SEARCH_NAME_ATTRIBUTE = 'name';

    /** Keep a small per-process snapshot cache keyed by website + watermark. */
    private const SNAPSHOT_PROCESS_CACHE_MAX = 8;

    /**
     * @var array<string, array{watermark:int, snapshot:array<string,mixed>}>
     */
    private static array $snapshotProcessCache = [];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly StoreProductRepository $storeProducts,
        private readonly OfferRepository $offers,
        private readonly StoreOfferRepository $storeOffers,
        private readonly AttributeValueRepository $attributes,
        private readonly CategoryLinkRepository $categoryLinks,
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
     * Full-website snapshot (indexer / migration). Storefront direct reads must
     * use {@see snapshotScope()} so one product watermark bump cannot force a
     * multi-store/channel rebuild on the request path.
     *
     * @return array<string,mixed>
     */
    public function snapshotWebsite(int $websiteId): array
    {
        return $this->buildSnapshot($websiteId, null, null);
    }

    /**
     * Request-scoped published snapshot for Search direct/degrade reads.
     *
     * @return array<string,mixed>
     */
    public function snapshotScope(int $websiteId, int $storeId, int $channelId): array
    {
        if ($storeId < 0 || $channelId < 0) {
            throw new \InvalidArgumentException((string)__(
                'Product Search Scope 快照要求非负 store_id/channel_id',
            ));
        }

        return $this->buildSnapshot($websiteId, $storeId, $channelId);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(
        int $websiteId,
        ?int $onlyStoreId,
        ?int $onlyChannelId,
    ): array {
        $website = $this->website($websiteId);
        $watermark = $this->stream->current($websiteId);
        $cacheKey = $onlyStoreId === null
            ? (string)$websiteId
            : $websiteId . ':' . $onlyStoreId . ':' . (int)$onlyChannelId;
        $cached = self::$snapshotProcessCache[$cacheKey] ?? null;
        if (\is_array($cached)
            && (int)($cached['watermark'] ?? -1) === $watermark
            && \is_array($cached['snapshot'] ?? null)
        ) {
            return $cached['snapshot'];
        }

        $scopes = $this->activeScopes($websiteId, $onlyStoreId);
        if ($onlyChannelId !== null) {
            $scopes = \array_values(\array_filter(
                $scopes,
                static fn(array $scope): bool => $scope['channel']->id === $onlyChannelId,
            ));
            if ($scopes === []) {
                throw new \RuntimeException((string)__(
                    'Product Search 找不到可用 Store/Channel Scope：store_id=%{1} channel_id=%{2}',
                    [$onlyStoreId, $onlyChannelId],
                ));
            }
        }

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
        $allOfferIds = [];
        foreach ($this->offers->listByProductIds($websiteId, array_keys($publishedProducts)) as $offer) {
            if ((string)($offer[Offer::schema_fields_STATUS] ?? '') !== Offer::STATUS_PUBLISHED) {
                continue;
            }
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if (isset($publishedProducts[$productId])) {
                $offersByProduct[$productId][] = $offer;
                $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
                if ($offerId > 0) {
                    $allOfferIds[] = $offerId;
                }
            }
        }

        $productIds = array_keys($publishedProducts);
        $searchTexts = $this->buildProductSearchTexts(
            $websiteId,
            $productIds,
        );

        $offerUuids = [];
        $productUuids = [];
        foreach ($offersByProduct as $offers) {
            foreach ($offers as $offer) {
                $offerUuid = \trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
                if ($offerUuid !== '') {
                    $offerUuids[] = $offerUuid;
                }
            }
        }
        foreach ($publishedProducts as $product) {
            $productUuid = \trim((string)($product[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
            if ($productUuid !== '') {
                $productUuids[] = $productUuid;
            }
        }
        $offerIdentities = $this->identities->resolveOffersByUuids($offerUuids);
        $productIdentities = $this->identities->resolveProductsByUuids($productUuids);

        $documents = [];
        foreach ($scopes as $scope) {
            $storeId = $scope['store']->id;
            $productSelection = $this->storeProducts->selectionMap($websiteId, $storeId, $productIds);
            $offerSelection = $this->storeOffers->selectionMap($websiteId, $storeId, $allOfferIds);
            foreach ($publishedProducts as $productId => $product) {
                if (!($productSelection[$productId] ?? true)) {
                    continue;
                }
                foreach ($offersByProduct[$productId] ?? [] as $offer) {
                    $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
                    if ($offerId <= 0 || !($offerSelection[$offerId] ?? true)) {
                        continue;
                    }
                    $documents[] = $this->document(
                        $website,
                        $scope,
                        $product,
                        $offer,
                        $watermark,
                        $searchTexts[$productId] ?? ['title' => '', 'keywords' => '', 'localized_titles' => []],
                        $offerIdentities,
                        $productIdentities,
                    );
                }
            }
        }
        $this->sortDocuments($documents);

        $snapshot = [
            'contract' => 'product.search_projection_snapshot.v1',
            'identity_contract' => 'product.offer_identity.v2',
            'website_id' => $websiteId,
            'source_watermark' => $watermark,
            'scope_count' => \count($scopes),
            'document_count' => \count($documents),
            'documents' => $documents,
            'snapshot_hash' => $this->hashDocuments($documents),
        ];
        if ($onlyStoreId !== null) {
            $snapshot['store_id'] = $onlyStoreId;
            $snapshot['channel_id'] = (int)$onlyChannelId;
            $snapshot['scope_contract'] = 'product.search_projection_scope_snapshot.v1';
        }
        self::$snapshotProcessCache[$cacheKey] = [
            'watermark' => $watermark,
            'snapshot' => $snapshot,
        ];
        if (\count(self::$snapshotProcessCache) > self::SNAPSHOT_PROCESS_CACHE_MAX) {
            \array_shift(self::$snapshotProcessCache);
        }

        return $snapshot;
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
            $storeId = $this->requiredInt($change, 'store_id', 0);
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
            $searchTexts = $this->buildProductSearchTexts($websiteId, [$productId]);
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
                        $searchTexts[$productId] ?? ['title' => '', 'keywords' => '', 'localized_titles' => []],
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
     * @param array<string,mixed> $product
     * @param array<string,mixed> $offer
     * @param array{title:string,keywords:string,localized_titles:array<string,string>} $searchText
     * @param array<string, \Weline\Product\Api\Data\OfferIdentityV2> $offerIdentities
     * @param array<string, \Weline\Product\Api\Data\ProductIdentityV2> $productIdentities
     * @return array<string,mixed>
     */
    private function document(
        WebsiteSummary $website,
        array $scope,
        array $product,
        array $offer,
        int $documentVersion,
        array $searchText,
        array $offerIdentities = [],
        array $productIdentities = [],
    ): array {
        $productId = (int)($product[Product::schema_fields_ID] ?? 0);
        $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
        $offerUuid = \strtolower(\trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? '')));
        $offerIdentity = $offerUuid === ''
            ? null
            : ($offerIdentities[$offerUuid] ?? $this->identities->resolveOfferByUuid($offerUuid));
        $sku = \trim($offerIdentity?->sku
            ?? (string)($offer[Offer::schema_fields_SKU] ?? ''));
        if ($productId <= 0 || $offerId <= 0 || $offerUuid === '' || $sku === '') {
            throw new \RuntimeException((string)__(
                'Product Search published Offer 投影缺少 Product/Offer 身份或 SKU',
            ));
        }

        $productUuid = \strtolower(\trim($offerIdentity?->globalProductUuid
            ?? (string)($product[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? '')));
        $productIdentity = $productUuid === ''
            ? null
            : ($productIdentities[$productUuid] ?? $this->identities->resolveProductByUuid($productUuid));
        $identity = $this->documentIdentity($website, $scope, $offerUuid);

        $title = \trim($searchText['title'] ?? '');
        if ($title === '') {
            $title = $sku;
        }
        $keywordParts = [
            $sku,
            (string)($productIdentity?->productCode ?? ''),
            (string)($searchText['keywords'] ?? ''),
        ];

        return $identity + [
            'title' => $title,
            'keywords' => \trim(\implode(' ', \array_filter(
                $keywordParts,
                static fn(string $part): bool => $part !== '',
            ))),
            'localized_titles' => \is_array($searchText['localized_titles'] ?? null)
                ? $searchText['localized_titles']
                : [],
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

    /**
     * @param list<int> $productIds
     * @return array<int, array{title:string,keywords:string,localized_titles:array<string,string>}>
     */
    private function buildProductSearchTexts(int $websiteId, array $productIds): array
    {
        $productIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        $texts = [];
        foreach ($productIds as $productId) {
            $texts[$productId] = [
                'title' => '',
                'keywords' => '',
                'localized_titles' => [],
            ];
        }

        $productAttributes = $this->collectLocalizedAttributeTexts(
            $websiteId,
            'product',
            $productIds,
            \array_merge([self::SEARCH_NAME_ATTRIBUTE], self::SEARCH_KEYWORD_ATTRIBUTES),
        );
        $privateOptionLabels = $this->collectPrivateOptionLabelsByProduct($productIds);

        $categoryIds = [];
        $categoryLinksByProduct = [];
        foreach ($this->categoryLinks->listByProductIds($websiteId, $productIds, [0]) as $link) {
            if ((int)($link['selected'] ?? 0) !== 1) {
                continue;
            }
            $productId = (int)($link['product_id'] ?? 0);
            $categoryId = (int)($link['category_id'] ?? 0);
            if ($productId <= 0 || $categoryId <= 0) {
                continue;
            }
            $categoryIds[$categoryId] = $categoryId;
            $categoryLinksByProduct[$productId][] = $categoryId;
        }
        $categoryAttributes = $categoryIds === []
            ? []
            : $this->collectLocalizedAttributeTexts(
                $websiteId,
                'category',
                \array_values($categoryIds),
                [self::SEARCH_NAME_ATTRIBUTE],
            );

        foreach ($productIds as $productId) {
            $keywordParts = [];
            $namesByLocale = $productAttributes[$productId][self::SEARCH_NAME_ATTRIBUTE] ?? [];
            $texts[$productId]['localized_titles'] = $namesByLocale;
            $texts[$productId]['title'] = $this->pickPrimaryLocaleText($namesByLocale);

            foreach ($productAttributes[$productId] ?? [] as $attributeTexts) {
                foreach ($attributeTexts as $value) {
                    $keywordParts[] = $value;
                }
            }

            foreach ($privateOptionLabels[$productId] ?? [] as $optionLabel) {
                $keywordParts[] = $optionLabel;
            }

            foreach ($categoryLinksByProduct[$productId] ?? [] as $categoryId) {
                foreach ($categoryAttributes[$categoryId][self::SEARCH_NAME_ATTRIBUTE] ?? [] as $value) {
                    $keywordParts[] = $value;
                }
            }

            $texts[$productId]['keywords'] = \trim(\implode(' ', \array_values(\array_unique(\array_filter(
                $keywordParts,
                static fn(string $part): bool => $part !== '',
            )))));
        }

        return $texts;
    }

    /**
     * Instance-private option labels (scope_instance_id = product_id) are searchable
     * for that product only. Shared catalog options stay out of global facets.
     *
     * @param list<int> $productIds
     * @return array<int, list<string>>
     */
    private function collectPrivateOptionLabelsByProduct(array $productIds): array
    {
        $labels = [];
        if ($productIds === []) {
            return [];
        }

        try {
            /** @var \Weline\Eav\Model\EavAttribute\Option $model */
            $model = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Eav\Model\EavAttribute\Option::class,
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            try {
                $rows = (clone $model)
                    ->clearData()
                    ->clearQuery()
                    ->where(
                        \Weline\Eav\Model\EavAttribute\Option::schema_fields_scope_instance_id,
                        $productId,
                    )
                    ->select()
                    ->fetch();
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $value = '';
                if (\is_object($row) && \method_exists($row, 'getValue')) {
                    $value = \trim((string)$row->getValue());
                } elseif (\is_array($row)) {
                    $value = \trim((string)($row[
                        \Weline\Eav\Model\EavAttribute\Option::schema_fields_value
                    ] ?? ''));
                }
                if ($value !== '') {
                    $labels[$productId][] = $value;
                }
            }
        }

        return $labels;
    }

    /**
     * @param list<int> $entityIds
     * @param list<string> $attributeCodes
     * @return array<int, array<string, array<string, string>>>
     */
    private function collectLocalizedAttributeTexts(
        int $websiteId,
        string $entityType,
        array $entityIds,
        array $attributeCodes,
    ): array {
        $entityIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $entityIds),
            static fn(int $id): bool => $id > 0,
        )));
        $attributeCodes = \array_values(\array_unique(\array_filter(
            \array_map(
                static fn(string $code): string => \trim($code),
                $attributeCodes,
            ),
            static fn(string $code): bool => $code !== '',
        )));
        if ($entityIds === [] || $attributeCodes === []) {
            return [];
        }

        $attributeCodeSet = \array_fill_keys($attributeCodes, true);
        $texts = [];
        foreach ($this->attributes->listExplicitRows(
            $websiteId,
            $entityType,
            $entityIds,
            [AttributeValue::WEBSITE_STORE_ID],
            null,
            $attributeCodes,
        ) as $row) {
            if (!empty($row['cleared'])) {
                continue;
            }
            $attributeCode = \trim((string)($row['attribute_code'] ?? ''));
            // Defensive: SQL already filters attribute_codes; keep set check for safety.
            if ($attributeCode === '' || !isset($attributeCodeSet[$attributeCode])) {
                continue;
            }
            $entityId = (int)($row['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            $value = \trim((string)($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $locale = \trim((string)($row['locale'] ?? ''));
            $texts[$entityId][$attributeCode][$locale] = $value;
        }

        return $texts;
    }

    /**
     * @param array<string, string> $localeTexts
     */
    private function pickPrimaryLocaleText(array $localeTexts): string
    {
        if (isset($localeTexts['']) && $localeTexts[''] !== '') {
            return $localeTexts[''];
        }
        foreach ($localeTexts as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
