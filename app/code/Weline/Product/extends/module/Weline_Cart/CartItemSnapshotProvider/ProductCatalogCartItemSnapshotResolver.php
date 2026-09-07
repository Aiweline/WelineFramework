<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider;

use Weline\Cart\Api\CartSelectionHash;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\ResolvedScopeValue;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductCurrentCustomerResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Resolves Product Cart snapshots from the durable Website shard.
 */
final class ProductCatalogCartItemSnapshotResolver
{
    /** @var (\Closure(): string)|null */
    private readonly ?\Closure $currencyResolver;

    /** @var (\Closure(): string)|null */
    private readonly ?\Closure $localeResolver;

    /** @var (\Closure(int, int, int): mixed)|null */
    private readonly ?\Closure $availabilityResolver;

    /** @var (\Closure(): int)|null */
    private readonly ?\Closure $customerResolver;

    /**
     * @param (callable(): string)|null $currencyResolver
     * @param (callable(): string)|null $localeResolver
     * @param (callable(int, int, int): mixed)|null $availabilityResolver
     */
    public function __construct(
        private readonly OfferRepository $offers,
        private readonly ProductRepository $products,
        private readonly AttributeValueRepository $attributes,
        private readonly PriceRepository $prices,
        private readonly MediaRepository $media,
        private readonly StoreOfferRepository $storeOffers,
        private readonly StoreCatalogInterface $stores,
        ?callable $currencyResolver = null,
        ?callable $localeResolver = null,
        ?callable $availabilityResolver = null,
        private readonly ?FileAssetManagerInterface $fileAssets = null,
        ?callable $customerResolver = null,
        private readonly ?StorefrontProductMediaUrlResolver $mediaUrls = null,
        private readonly ?StorefrontEavLabelResolver $variantLabels = null,
    ) {
        $this->currencyResolver = $currencyResolver === null
            ? null
            : \Closure::fromCallable($currencyResolver);
        $this->localeResolver = $localeResolver === null
            ? null
            : \Closure::fromCallable($localeResolver);
        $this->availabilityResolver = $availabilityResolver === null
            ? null
            : \Closure::fromCallable($availabilityResolver);
        $this->customerResolver = $customerResolver === null
            ? null
            : \Closure::fromCallable($customerResolver);
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    public function resolve(
        OfferIdentity $identity,
        ScopeIdentity $scope,
        array $selection = [],
    ): CartItemSnapshot {
        $selection = CartSelectionHash::normalizeSelection($selection);
        if ($scope->isGlobal() || $scope->websiteId === null) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('Global Scope 不支持商品加购'),
            );
        }

        $websiteId = $scope->websiteId;
        $store = $this->resolveStore($scope);
        if ($store['sellable'] === false) {
            return $this->unavailable($identity, $selection, $store['message']);
        }
        $storeId = $store['store_id'];

        $offer = $this->offers->findByGlobalUuid($websiteId, $identity->globalOfferUuid);
        if ($offer === null) {
            return $this->notFound($identity, $selection, (string)__('Offer 不存在'));
        }
        if (strtolower(trim((string)$offer->getData(Offer::schema_fields_STATUS))) !== 'published') {
            return $this->unavailable($identity, $selection, (string)__('Offer 未发布'));
        }

        $offerId = (int)$offer->getId();
        $productId = (int)$offer->getData(Offer::schema_fields_PRODUCT_ID);
        $product = $this->products->findById($websiteId, $productId);
        if ($product === null) {
            return $this->notFound($identity, $selection, (string)__('Offer 对应商品不存在'));
        }
        $productSku = trim((string)$product->getData(Product::schema_fields_SKU));
        $sku = trim((string)$offer->getData(Offer::schema_fields_SKU));
        if ($sku === '') {
            $sku = $productSku;
        }
        if (strtolower(trim((string)$product->getData(Product::schema_fields_STATUS)))
            !== Product::STATUS_PUBLISHED
        ) {
            return $this->unavailable($identity, $selection, (string)__('商品未发布'), $sku);
        }
        if ($scope->scopeKind !== ScopeIdentity::KIND_WEBSITE
            && !$this->storeOffers->isSelected($websiteId, $storeId, $offerId)
        ) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('该 Offer 未在当前 Store 上架'),
                $sku,
            );
        }

        $locale = $this->locale();
        $nameValue = $this->attributes->read(
            $websiteId,
            $storeId,
            'product',
            $productId,
            'name',
            $locale,
            [''],
        );
        if ($nameValue->isCleared()) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('商品名称在当前 Scope 已清除'),
                $sku,
            );
        }
        $name = trim((string)$nameValue->value);
        if ($name === '') {
            $name = $sku !== '' ? $sku : (string)__('商品');
        }

        $currency = $this->currency();
        $price = $this->prices->read($websiteId, $storeId, $offerId, $currency);
        if ($price->isCleared()) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('商品价格在当前 Scope 已清除'),
                $sku,
                $name,
                $currency,
            );
        }
        if ($price->isUnresolved()) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('商品价格未配置'),
                $sku,
                $name,
                $currency,
            );
        }

        $unitPriceMinor = max(0, (int)$price->value);
        if ($this->isQuoteOnly($websiteId, $storeId, $productId, $locale)) {
            return $this->unavailable(
                $identity,
                $selection,
                (string)__('仅询价，不可加入购物车'),
                $sku,
                $name,
                $currency,
            );
        }
        $dealPrice = $this->resolveActiveDealPrice($productId, $unitPriceMinor);
        $unitPriceMinor = $dealPrice['unit_price_minor'];
        $compareAtMinor = $dealPrice['compare_at_minor'];
        $campaignLabel = $dealPrice['campaign_label'];
        $campaignUrl = $dealPrice['campaign_url'];

        $productType = $this->productType($websiteId, $storeId, $productId, $locale);
        $fulfillmentMetadata = [];
        if ($productType === 'downloadable') {
            if ($this->currentCustomerId() <= 0) {
                return $this->unavailable(
                    $identity,
                    $selection,
                    (string)__('下载商品需要登录后购买'),
                    $sku,
                    $name,
                    $currency,
                );
            }
            try {
                $fulfillmentMetadata = $this->downloadFulfillmentMetadata(
                    $websiteId,
                    $productId,
                    $product,
                    $identity,
                );
            } catch (\Throwable) {
                return $this->unavailable(
                    $identity,
                    $selection,
                    (string)__('下载资产当前不可用，请联系管理员'),
                    $sku,
                    $name,
                    $currency,
                );
            }
        }

        $availability = $this->availability($websiteId, $storeId, $offerId);
        $stock = $availability['stock'];
        if ($availability['sellable'] === false) {
            return new CartItemSnapshot(
                offer: $identity,
                name: $name,
                sku: $sku,
                image: $this->image($websiteId, $productId, $scope, $locale),
                currency: $currency,
                unitPriceMinor: $unitPriceMinor,
                found: true,
                sellable: false,
                stock: $stock,
                message: (string)__('商品库存不足'),
                selection: $selection,
                productType: $productType,
                sourceModule: 'Weline_Product',
                sourceApp: 'Weline',
                offerId: $offerId,
                productId: $productId,
                fulfillmentMetadata: $fulfillmentMetadata,
                options: $this->buildOptions($websiteId, $productId, $selection, $scope, $locale),
                compareAtMinor: $compareAtMinor,
                campaignLabel: $campaignLabel,
                campaignUrl: $campaignUrl,
            );
        }

        return new CartItemSnapshot(
            offer: $identity,
            name: $name,
            sku: $sku,
            image: $this->image($websiteId, $productId, $scope, $locale),
            currency: $currency,
            unitPriceMinor: $unitPriceMinor,
            found: true,
            sellable: true,
            stock: $stock,
            selection: $selection,
            productType: $productType,
            sourceModule: 'Weline_Product',
            sourceApp: 'Weline',
            offerId: $offerId,
            productId: $productId,
            fulfillmentMetadata: $fulfillmentMetadata,
            options: $this->buildOptions($websiteId, $productId, $selection, $scope, $locale),
            compareAtMinor: $compareAtMinor,
            campaignLabel: $campaignLabel,
            campaignUrl: $campaignUrl,
        );
    }

    /**
     * Storefront catalog projection for already-loaded durable Offer rows.
     *
     * Product, attribute, price, inventory, media and Store-overlay facts are
     * resolved in batches so a configurable PDP does not repeat the complete
     * Cart snapshot query chain once per variant.
     *
     * @param list<array<string,mixed>> $offerRows
     * @param list<array<string,mixed>> $productRows
     * @param list<array<string,mixed>>|null $attributeRows
     * @param list<array<string,mixed>>|null $mediaRows
     * @return list<CartItemSnapshot>
     */
    public function resolveCatalogOffers(
        array $offerRows,
        ScopeIdentity $scope,
        array $productRows = [],
        ?array $attributeRows = null,
        ?array $mediaRows = null,
    ): array {
        $offerRows = array_values(array_filter($offerRows, 'is_array'));
        if ($offerRows === []) {
            return [];
        }

        $identities = array_map(
            static fn(array $row): OfferIdentity => new OfferIdentity(
                'product',
                trim((string)($row[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? '')),
                max(0, (int)($row[Offer::schema_fields_PRODUCT_ID] ?? 0)),
            ),
            $offerRows,
        );
        if ($scope->isGlobal() || $scope->websiteId === null) {
            return array_map(
                fn(OfferIdentity $identity): CartItemSnapshot => $this->unavailable(
                    $identity,
                    [],
                    (string)__('Global Scope 不支持商品加购'),
                ),
                $identities,
            );
        }

        $websiteId = $scope->websiteId;
        $store = $this->resolveStore($scope);
        if ($store['sellable'] === false) {
            return array_map(
                fn(OfferIdentity $identity): CartItemSnapshot => $this->unavailable(
                    $identity,
                    [],
                    $store['message'],
                ),
                $identities,
            );
        }
        $storeId = $store['store_id'];
        $offerIds = [];
        $productIds = [];
        foreach ($offerRows as $row) {
            $offerId = (int)($row[Offer::schema_fields_ID] ?? 0);
            $productId = (int)($row[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($offerId > 0) {
                $offerIds[$offerId] = $offerId;
            }
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }
        $offerIds = array_values($offerIds);
        $productIds = array_values($productIds);

        if ($productRows === []) {
            $productRows = $this->products->listAll($websiteId);
        }
        $wantedProductIds = array_fill_keys($productIds, true);
        $productsById = [];
        foreach ($productRows as $productRow) {
            if (!is_array($productRow)) {
                continue;
            }
            $productId = (int)($productRow[Product::schema_fields_ID] ?? 0);
            if ($productId > 0 && isset($wantedProductIds[$productId])) {
                $productsById[$productId] = $productRow;
            }
        }

        $locale = $this->locale();
        $currency = $this->currency();
        $storeIds = array_values(array_unique([0, $storeId]));
        $overlay = new CatalogOverlayResolver();

        $attributeRowsByProductAndCode = [];
        $attributeRows ??= \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'product.catalog.snapshot.attributes',
            fn() => $this->attributes->listExplicitRows($websiteId, 'product', $productIds, $storeIds),
        );
        foreach ($attributeRows as $attributeRow) {
            $productId = (int)($attributeRow['entity_id'] ?? 0);
            $code = strtolower(trim((string)($attributeRow['attribute_code'] ?? '')));
            if ($productId > 0 && ($code === 'name' || $code === 'product_type')) {
                $attributeRowsByProductAndCode[$productId][$code][] = $attributeRow;
            }
        }

        $productFacts = [];
        foreach ($productIds as $productId) {
            $productRow = $productsById[$productId] ?? null;
            if (!is_array($productRow)) {
                continue;
            }
            $name = $overlay->resolveAttribute(
                $attributeRowsByProductAndCode[$productId]['name'] ?? [],
                $storeId,
                $locale,
                [''],
            );
            $type = $overlay->resolveAttribute(
                $attributeRowsByProductAndCode[$productId]['product_type'] ?? [],
                $storeId,
                $locale,
                [''],
            );
            $productSku = trim((string)($productRow[Product::schema_fields_SKU] ?? ''));
            $nameValue = $name->isExplicit() ? trim((string)$name->value) : '';
            $typeValue = $type->isExplicit() ? strtolower(trim((string)$type->value)) : '';
            $productFacts[$productId] = [
                'row' => $productRow,
                'name' => $nameValue !== '' ? $nameValue : ($productSku !== '' ? $productSku : (string)__('商品')),
                'sku' => $productSku,
                'type' => $typeValue !== '' ? $typeValue : 'simple',
            ];
        }

        $priceRowsByOffer = [];
        foreach (\Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'product.catalog.snapshot.prices',
            fn() => $this->prices->listExplicitRows($websiteId, $offerIds, $storeIds),
        ) as $priceRow) {
            if (strcasecmp(trim((string)($priceRow['currency'] ?? '')), $currency) !== 0) {
                continue;
            }
            $offerId = (int)($priceRow['offer_id'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }
            $priceRowsByOffer[$offerId][] = [
                'store_id' => (int)($priceRow['store_id'] ?? 0),
                'cleared' => !empty($priceRow['cleared']),
                'value' => $priceRow['amount_minor'] ?? null,
            ];
        }
        $pricesByOffer = [];
        foreach ($offerIds as $offerId) {
            $pricesByOffer[$offerId] = $overlay->resolvePrice(
                $priceRowsByOffer[$offerId] ?? [],
                $storeId,
            );
        }

        $firstMediaByProduct = [];
        $mediaRows ??= \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'product.catalog.snapshot.media_rows',
            fn() => $this->media->listByProductIds($websiteId, $productIds),
        );
        foreach ($mediaRows as $mediaRow) {
            $productId = (int)($mediaRow[Media::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0 && !isset($firstMediaByProduct[$productId])) {
                $firstMediaByProduct[$productId] = trim((string)($mediaRow[Media::schema_fields_PATH] ?? ''));
            }
        }
        $imageReferences = [];
        foreach ($productIds as $productId) {
            $imageReferences[$productId] = $firstMediaByProduct[$productId] ?? '';
        }
        $imagesByProduct = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'product.catalog.snapshot.media_url',
            fn() => $this->resolveImageReferences($imageReferences, $scope, $locale),
        );

        $availabilityByOffer = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'product.catalog.snapshot.availability',
            fn() => $this->availabilities($websiteId, $storeId, $offerIds),
        );
        $selectionByOffer = $scope->scopeKind === ScopeIdentity::KIND_WEBSITE
            ? array_fill_keys($offerIds, true)
            : $this->storeOffers->selectionMap($websiteId, $storeId, $offerIds);

        $snapshots = [];
        foreach ($offerRows as $index => $offerRow) {
            $identity = $identities[$index];
            $offerId = (int)($offerRow[Offer::schema_fields_ID] ?? 0);
            $productId = (int)($offerRow[Offer::schema_fields_PRODUCT_ID] ?? 0);
            $facts = $productFacts[$productId] ?? null;
            if ($identity->globalOfferUuid === '' || $offerId <= 0 || $productId <= 0) {
                $snapshots[] = $this->notFound($identity, [], (string)__('Offer 不存在'));
                continue;
            }
            if (strtolower(trim((string)($offerRow[Offer::schema_fields_STATUS] ?? ''))) !== 'published') {
                $snapshots[] = $this->unavailable($identity, [], (string)__('Offer 未发布'));
                continue;
            }
            if (!is_array($facts)) {
                $snapshots[] = $this->notFound($identity, [], (string)__('Offer 对应商品不存在'));
                continue;
            }

            $sku = trim((string)($offerRow[Offer::schema_fields_SKU] ?? ''));
            if ($sku === '') {
                $sku = (string)$facts['sku'];
            }
            if (strtolower(trim((string)($facts['row'][Product::schema_fields_STATUS] ?? '')))
                !== Product::STATUS_PUBLISHED
            ) {
                $snapshots[] = $this->unavailable(
                    $identity,
                    [],
                    (string)__('商品未发布'),
                    $sku,
                );
                continue;
            }
            if (!($selectionByOffer[$offerId] ?? true)) {
                $snapshots[] = $this->unavailable(
                    $identity,
                    [],
                    (string)__('该 Offer 未在当前 Store 上架'),
                    $sku,
                );
                continue;
            }

            // Downloadable products have per-customer private asset checks; keep
            // the authoritative single-item path for this uncommon catalog type.
            if ((string)$facts['type'] === 'downloadable') {
                $snapshots[] = $this->resolve($identity, $scope);
                continue;
            }

            $price = $pricesByOffer[$offerId] ?? ResolvedScopeValue::unresolved();
            if ($price->isCleared()) {
                $snapshots[] = $this->unavailable(
                    $identity,
                    [],
                    (string)__('商品价格在当前 Scope 已清除'),
                    $sku,
                    (string)$facts['name'],
                    $currency,
                );
                continue;
            }
            if ($price->isUnresolved()) {
                $snapshots[] = $this->unavailable(
                    $identity,
                    [],
                    (string)__('商品价格未配置'),
                    $sku,
                    (string)$facts['name'],
                    $currency,
                );
                continue;
            }

            $availability = $availabilityByOffer[$offerId] ?? ['sellable' => null, 'stock' => null];
            $isQuoteOnly = $this->attributeFlagEnabled(
                $attributeRowsByProductAndCode[$productId]['quote_only'] ?? [],
                $storeId,
                $locale,
            );
            $isSellable = !$isQuoteOnly && $availability['sellable'] !== false;
            // Listing/PDP/shelf Assembler is the single deal applicator. Keep this
            // phase for timing, but emit raw catalog minor (cart add still deals).
            $unitPriceMinor = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                'product.catalog.snapshot.deals',
                fn() => max(0, (int)$price->value),
            );
            $snapshots[] = new CartItemSnapshot(
                offer: $identity,
                name: (string)$facts['name'],
                sku: $sku,
                image: $imagesByProduct[$productId] ?? '',
                currency: $currency,
                unitPriceMinor: $unitPriceMinor,
                found: true,
                sellable: $isSellable,
                stock: $availability['stock'],
                message: $isSellable
                    ? ''
                    : ($isQuoteOnly
                        ? (string)__('仅询价，不可加入购物车')
                        : (string)__('商品库存不足')),
                productType: (string)$facts['type'],
                sourceModule: 'Weline_Product',
                sourceApp: 'Weline',
                offerId: $offerId,
                productId: $productId,
                options: \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                    'product.catalog.snapshot.options',
                    fn() => $this->buildOptions($websiteId, $productId, [], $scope, $this->locale()),
                ),
            );
        }

        return $snapshots;
    }

    /**
     * @return array{store_id:int,sellable:bool,message:string}
     */
    private function resolveStore(ScopeIdentity $scope): array
    {
        if ($scope->scopeKind === ScopeIdentity::KIND_WEBSITE) {
            return ['store_id' => 0, 'sellable' => true, 'message' => ''];
        }
        $websiteId = (int)$scope->websiteId;
        $storeCode = trim((string)$scope->storeCode);
        $store = $storeCode === '' ? null : $this->stores->byCode($websiteId, $storeCode);
        if ($store === null) {
            return ['store_id' => 0, 'sellable' => false, 'message' => (string)__('Store 不存在')];
        }
        if (!$store->enabled
            || $store->lifecycleStatus !== 'active'
            || $store->tombstonedAt !== null
            || ($scope->storeMode !== null && $store->storeMode !== $scope->storeMode)
        ) {
            return [
                'store_id' => $store->id,
                'sellable' => false,
                'message' => (string)__('Store 当前不可售'),
            ];
        }
        return ['store_id' => $store->id, 'sellable' => true, 'message' => ''];
    }

    private function currency(): string
    {
        $currency = $this->currencyResolver === null
            ? RequestContext::getWelineUserCurrency()
            : ($this->currencyResolver)();
        $currency = strtoupper(trim((string)$currency));
        return $currency !== '' ? $currency : 'CNY';
    }

    private function locale(): string
    {
        $locale = $this->localeResolver === null
            ? RequestContext::getWelineUserLang()
            : ($this->localeResolver)();
        return trim((string)$locale);
    }

    /**
     * @return array{sellable:?bool,stock:?int}
     */
    private function availability(int $websiteId, int $storeId, int $offerId): array
    {
        $result = $this->availabilityResolver === null
            ? $this->runtimeAvailability($websiteId, $storeId, $offerId)
            : ($this->availabilityResolver)($websiteId, $storeId, $offerId);
        return $this->normalizeAvailability($result);
    }

    /**
     * @param list<int> $offerIds
     * @return array<int, array{sellable:?bool,stock:?int}>
     */
    private function availabilities(int $websiteId, int $storeId, array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        if ($this->availabilityResolver !== null) {
            $resolved = [];
            foreach ($offerIds as $offerId) {
                $resolved[$offerId] = $this->normalizeAvailability(
                    ($this->availabilityResolver)($websiteId, $storeId, $offerId),
                );
            }
            return $resolved;
        }

        $contract = 'Weline\\Inventory\\Api\\InventoryCapabilityInterface';
        if (!interface_exists($contract)) {
            return [];
        }
        try {
            $inventory = ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
            if (!$inventory instanceof $contract) {
                return [];
            }

            $results = method_exists($inventory, 'getAvailabilities')
                ? $inventory->getAvailabilities($websiteId, $storeId, $offerIds)
                : array_combine(
                    $offerIds,
                    array_map(
                        static fn(int $offerId): mixed => $inventory->getAvailability(
                            $websiteId,
                            $storeId,
                            $offerId,
                        ),
                        $offerIds,
                    ),
                );
            $resolved = [];
            foreach (is_array($results) ? $results : [] as $offerId => $result) {
                $offerId = (int)$offerId;
                if ($offerId > 0) {
                    $resolved[$offerId] = $this->normalizeAvailability($result);
                }
            }
            return $resolved;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{sellable:?bool,stock:?int} */
    private function normalizeAvailability(mixed $result): array
    {
        if ($result === null) {
            return ['sellable' => null, 'stock' => null];
        }
        if (is_array($result)) {
            $stock = array_key_exists('available_minor', $result)
                ? max(0, (int)$result['available_minor'])
                : (array_key_exists('stock', $result) ? max(0, (int)$result['stock']) : null);
            return [
                'sellable' => array_key_exists('sellable', $result)
                    ? (bool)$result['sellable']
                    : null,
                'stock' => $stock,
            ];
        }
        if (is_object($result)) {
            $stock = isset($result->availableMinor) ? max(0, (int)$result->availableMinor) : null;
            return [
                'sellable' => isset($result->sellable) ? (bool)$result->sellable : null,
                'stock' => $stock,
            ];
        }
        return ['sellable' => null, 'stock' => null];
    }

    private function runtimeAvailability(int $websiteId, int $storeId, int $offerId): mixed
    {
        $contract = 'Weline\\Inventory\\Api\\InventoryCapabilityInterface';
        if (!interface_exists($contract)) {
            return null;
        }
        try {
            $inventory = ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
            if (!$inventory instanceof $contract) {
                return null;
            }
            return $inventory->getAvailability($websiteId, $storeId, $offerId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function image(int $websiteId, int $productId, ScopeIdentity $scope, string $locale): string
    {
        $rows = $this->media->listByProductIds($websiteId, [$productId]);
        $reference = trim((string)($rows[0][Media::schema_fields_PATH] ?? ''));
        return $this->resolveImageReference($reference, $scope, $locale);
    }

    private function resolveImageReference(string $reference, ScopeIdentity $scope, string $locale): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        // Cart API / mini-cart set img.src from this field — never emit FileManager asset://.
        $resolver = $this->mediaUrls;
        if ($resolver === null) {
            try {
                $candidate = ObjectManager::getInstance(StorefrontProductMediaUrlResolver::class);
                $resolver = $candidate instanceof StorefrontProductMediaUrlResolver ? $candidate : null;
            } catch (\Throwable) {
                $resolver = null;
            }
        }
        if ($resolver === null) {
            return str_starts_with(strtolower($reference), 'asset://') ? '' : $reference;
        }

        $localeCode = trim($locale);
        if ($localeCode === '') {
            $localeCode = 'zh_Hans_CN';
        }

        return $resolver->resolveReference($reference, $scope, $localeCode);
    }

    /** @param array<int,string> $references @return array<int,string> */
    private function resolveImageReferences(array $references, ScopeIdentity $scope, string $locale): array
    {
        $resolver = $this->mediaUrls;
        if ($resolver === null) {
            try {
                $candidate = ObjectManager::getInstance(StorefrontProductMediaUrlResolver::class);
                $resolver = $candidate instanceof StorefrontProductMediaUrlResolver ? $candidate : null;
            } catch (\Throwable) {
                $resolver = null;
            }
        }
        if ($resolver === null) {
            return array_map(static function (string $reference): string {
                $reference = trim($reference);
                return str_starts_with(strtolower($reference), 'asset://') ? '' : $reference;
            }, $references);
        }
        $localeCode = trim($locale);
        return $resolver->resolveReferences($references, $scope, $localeCode === '' ? 'zh_Hans_CN' : $localeCode);
    }

    private function productType(int $websiteId, int $storeId, int $productId, string $locale): string
    {
        $type = $this->attributes->read(
            $websiteId,
            $storeId,
            'product',
            $productId,
            'product_type',
            $locale,
            [''],
        );
        $value = $type->isExplicit() ? strtolower(trim((string)$type->value)) : '';
        return $value !== '' ? $value : 'simple';
    }

    private function applyActiveDealMinor(int $productId, int $catalogMinor): int
    {
        return $this->resolveActiveDealPrice($productId, $catalogMinor)['unit_price_minor'];
    }

    /**
     * Same Assembler path as PDP/cards so cart lines carry compare-at + campaign chrome.
     *
     * @return array{
     *     unit_price_minor:int,
     *     compare_at_minor:int,
     *     campaign_label:string,
     *     campaign_url:string
     * }
     */
    private function resolveActiveDealPrice(int $productId, int $catalogMinor): array
    {
        $catalogMinor = max(0, $catalogMinor);
        $fallback = [
            'unit_price_minor' => $catalogMinor,
            'compare_at_minor' => 0,
            'campaign_label' => '',
            'campaign_url' => '',
        ];
        if ($productId <= 0 || $catalogMinor <= 0) {
            return $fallback;
        }
        try {
            $assembler = null;
            if (interface_exists(StorefrontOfferPriceAssemblerInterface::class)) {
                try {
                    $assembler = ObjectManager::getInstance(StorefrontOfferPriceAssemblerInterface::class);
                } catch (\Throwable) {
                    $assembler = null;
                }
            }
            if (!$assembler instanceof StorefrontOfferPriceAssemblerInterface
                && class_exists(\Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class)
            ) {
                $assembler = ObjectManager::getInstance(
                    \Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class,
                );
            }
            if (!$assembler instanceof StorefrontOfferPriceAssemblerInterface) {
                return $fallback;
            }
            $view = $assembler->assemble(StorefrontPriceContext::fromCatalogMinor($productId, $catalogMinor));
            $unit = max(0, $view->finalPriceMinor);
            $compareAt = max(0, $view->compareAtMinor);
            if (!$view->hasDeal || $compareAt <= $unit) {
                $compareAt = 0;
            }

            return [
                'unit_price_minor' => $unit,
                'compare_at_minor' => $compareAt,
                'campaign_label' => $compareAt > 0 ? $view->campaignLabel() : '',
                'campaign_url' => $compareAt > 0 ? $view->campaignUrl() : '',
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function isQuoteOnly(int $websiteId, int $storeId, int $productId, string $locale): bool
    {
        $value = $this->attributes->read(
            $websiteId,
            $storeId,
            'product',
            $productId,
            'quote_only',
            $locale,
            [''],
        );
        if (!$value->isExplicit()) {
            return false;
        }
        $raw = strtolower(trim((string)$value->value));

        return $raw === '1' || $raw === 'true' || $raw === 'yes';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function attributeFlagEnabled(array $rows, int $storeId, string $locale): bool
    {
        if ($rows === []) {
            return false;
        }
        $preferred = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowStore = (int)($row['store_id'] ?? 0);
            $rowLocale = trim((string)($row['locale'] ?? ''));
            if ($rowStore === $storeId && ($rowLocale === $locale || $rowLocale === '')) {
                $preferred = $row;
                break;
            }
            if ($preferred === null && $rowStore === 0) {
                $preferred = $row;
            }
        }
        if ($preferred === null) {
            $preferred = is_array($rows[0] ?? null) ? $rows[0] : null;
        }
        if ($preferred === null) {
            return false;
        }
        $raw = strtolower(trim((string)($preferred['value'] ?? $preferred['value_text'] ?? '')));

        return $raw === '1' || $raw === 'true' || $raw === 'yes';
    }

    private function currentCustomerId(): int
    {
        if ($this->customerResolver !== null) {
            return max(0, (int)($this->customerResolver)());
        }
        try {
            $resolver = ObjectManager::getInstance(ProductCurrentCustomerResolver::class);
            return $resolver instanceof ProductCurrentCustomerResolver
                ? $resolver->currentCustomerId()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    private function downloadFulfillmentMetadata(
        int $websiteId,
        int $productId,
        Product $product,
        OfferIdentity $identity,
    ): array {
        $resolved = $this->attributes->read(
            $websiteId,
            0,
            'product',
            $productId,
            'type_configuration',
            '',
            [''],
        );
        $configuration = is_array($resolved->value) ? $resolved->value : [];
        $rows = $configuration['download_assets'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException('download_assets_missing');
        }

        $manager = $this->fileAssets;
        if ($manager === null) {
            $candidate = ObjectManager::getInstance(FileAssetManagerInterface::class);
            $manager = $candidate instanceof FileAssetManagerInterface ? $candidate : null;
        }
        if ($manager === null) {
            throw new \RuntimeException('download_file_manager_unavailable');
        }

        $assets = [];
        foreach ($rows as $row) {
            $assetId = is_array($row) ? trim((string)($row['asset_id'] ?? '')) : '';
            if ($assetId === '') {
                throw new \RuntimeException('download_asset_invalid');
            }
            $asset = $manager->get($assetId);
            if ($asset->getAssetId() === ''
                || $asset->isDeleted()
                || !$asset->isReady()
                || $asset->getVisibility() !== FileAsset::VISIBILITY_PRIVATE
            ) {
                throw new \RuntimeException('download_asset_unavailable');
            }
            $policy = $this->downloadAssetPolicy($asset);
            if ($policy === null) {
                throw new \RuntimeException('download_asset_policy_invalid');
            }
            $assets[] = [
                'asset_id' => $asset->getAssetId(),
                'asset_revision' => max(
                    1,
                    (int)$asset->getData(FileAsset::schema_fields_ASSET_REVISION),
                ),
                'name' => trim((string)$asset->getData(FileAsset::schema_fields_ORIGINAL_NAME)),
                'policy_revision' => $policy['policy_revision'],
            ];
        }

        $policy = is_array($configuration['entitlement_policy'] ?? null)
            ? $configuration['entitlement_policy']
            : [];
        $limit = $this->positiveOrNull($policy['download_limit'] ?? null, 1000000);
        $days = $this->positiveOrNull($policy['expires_after_days'] ?? null, 36500);

        return [
            'digital_download' => [
                'schema_version' => 'product-download.v1',
                'global_product_uuid' => trim((string)$product->getData(
                    Product::schema_fields_GLOBAL_PRODUCT_UUID,
                )),
                'global_offer_uuid' => $identity->globalOfferUuid,
                'assets' => $assets,
                'entitlement_policy' => [
                    'download_limit' => $limit,
                    'expires_after_days' => $days,
                ],
            ],
        ];
    }

    /** @return array{policy_revision:int}|null */
    private function downloadAssetPolicy(FileAsset $asset): ?array
    {
        try {
            $metadata = json_decode(
                trim((string)$asset->getData(FileAsset::schema_fields_METADATA)),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($metadata)) {
            return null;
        }
        $policy = $metadata['access_policy'] ?? $metadata;
        if (!is_array($policy) || !is_array($policy['allowed_roles'] ?? null)) {
            return null;
        }
        $roles = array_values(array_filter(array_map(
            static fn(mixed $role): string => is_scalar($role) ? trim((string)$role) : '',
            $policy['allowed_roles'],
        )));
        $revision = (int)($policy['policy_revision'] ?? 1);
        return in_array('product_download', $roles, true) && $revision > 0
            ? ['policy_revision' => $revision]
            : null;
    }

    private function positiveOrNull(mixed $value, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int)$value < 1
            || (int)$value > $maximum
        ) {
            throw new \RuntimeException('download_entitlement_policy_invalid');
        }
        return (int)$value;
    }

    /**
     * @param array<string, scalar|null> $selection
     * @return list<array{code:string,label:string,value:string,value_label:string,swatch_image?:string,swatch_color?:string}>
     */
    private function buildOptions(
        int $websiteId,
        int $productId,
        array $selection,
        ScopeIdentity $scope,
        string $locale,
    ): array {
        $selection = CartSelectionHash::normalizeSelection($selection);
        if ($selection === []) {
            return [];
        }

        $labels = $this->variantLabels;
        if ($labels === null) {
            try {
                $resolved = ObjectManager::getInstance(StorefrontEavLabelResolver::class);
                $labels = $resolved instanceof StorefrontEavLabelResolver ? $resolved : null;
            } catch (\Throwable) {
                $labels = null;
            }
        }
        if ($labels !== null && $productId > 0) {
            $labels = $labels->forProduct($productId);
        }

        $swatches = $productId > 0
            ? $this->variantSwatchesByAxis($websiteId, $productId)
            : [];
        $eavSwatches = $this->eavOptionSwatches(array_keys($selection), $productId);

        $options = [];
        foreach ($selection as $code => $value) {
            $code = trim((string)$code);
            $value = trim((string)$value);
            if ($code === '' || $value === '') {
                continue;
            }
            $axisLabel = $labels !== null ? trim($labels->attributeLabel($code)) : '';
            $valueLabel = $labels !== null ? trim($labels->resolve($code, $value)) : $value;
            $aliases = [$value];
            if ($labels !== null) {
                foreach ([
                    $labels->canonicalOptionId($code, $value),
                    $labels->publicOptionCode($code, $value),
                    $valueLabel,
                ] as $alias) {
                    $alias = trim((string)$alias);
                    if ($alias !== '' && !in_array($alias, $aliases, true)) {
                        $aliases[] = $alias;
                    }
                }
            }

            $swatchImage = '';
            foreach ($aliases as $alias) {
                $candidate = trim((string)($swatches[$code][$alias] ?? ''));
                if ($candidate !== '') {
                    $swatchImage = $this->resolveImageReference($candidate, $scope, $locale);
                    if ($swatchImage !== '') {
                        break;
                    }
                }
            }
            if ($swatchImage === '') {
                foreach ($aliases as $alias) {
                    $candidate = trim((string)($eavSwatches['images'][$code][$alias] ?? ''));
                    if ($candidate !== '') {
                        $swatchImage = $this->resolveImageReference($candidate, $scope, $locale);
                        if ($swatchImage !== '') {
                            break;
                        }
                    }
                }
            }

            $swatchColor = '';
            foreach ($aliases as $alias) {
                $candidate = trim((string)($eavSwatches['colors'][$code][$alias] ?? ''));
                if ($candidate !== '') {
                    $swatchColor = $candidate;
                    break;
                }
            }

            $option = [
                'code' => $code,
                'label' => $axisLabel !== '' ? $axisLabel : $code,
                'value' => $value,
                'value_label' => $valueLabel !== '' ? $valueLabel : $value,
            ];
            if ($swatchImage !== '') {
                $option['swatch_image'] = $swatchImage;
            }
            if ($swatchColor !== '') {
                $option['swatch_color'] = $swatchColor;
            }
            $options[] = $option;
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>> axis => optionValue => media path
     */
    private function variantSwatchesByAxis(int $websiteId, int $productId): array
    {
        $parser = new StorefrontVariantSelectionService();
        $swatches = [];
        foreach ($this->media->listByProductIds($websiteId, [$productId]) as $mediaRow) {
            if (!is_array($mediaRow)) {
                continue;
            }
            if (strtolower(trim((string)($mediaRow[Media::schema_fields_ROLE] ?? ''))) !== 'variant') {
                continue;
            }
            $path = trim((string)($mediaRow[Media::schema_fields_PATH] ?? ''));
            if ($path === '') {
                continue;
            }
            $combination = $parser->parseCombinationKey(
                (string)($mediaRow[Media::schema_fields_COMBINATION_KEY] ?? ''),
            );
            foreach ($combination as $axis => $value) {
                $axis = strtolower(trim((string)$axis));
                $value = trim((string)$value);
                // Match PDP: combination media previews color (and similar) axes, not size chips.
                if ($axis === '' || $value === '' || $axis === 'size') {
                    continue;
                }
                if (!isset($swatches[$axis][$value])) {
                    $swatches[$axis][$value] = $path;
                }
            }
        }

        return $swatches;
    }

    /**
     * Product-private options (scope_instance_id = product_id) carry the same
     * swatch_image / swatch_color shown on the PDP; shared catalog alone misses them.
     *
     * @param list<string|int> $axisCodes
     * @return array{
     *   images: array<string, array<string, string>>,
     *   colors: array<string, array<string, string>>
     * }
     */
    private function eavOptionSwatches(array $axisCodes, int $productId = 0): array
    {
        $empty = ['images' => [], 'colors' => []];
        $wanted = [];
        foreach ($axisCodes as $code) {
            $code = strtolower(trim((string)$code));
            if ($code !== '') {
                $wanted[$code] = true;
            }
        }
        if ($wanted === []) {
            return $empty;
        }

        try {
            $metadata = ObjectManager::getInstance(AttributeMetadataCatalogInterface::class);
            $entity = ObjectManager::getInstance(ProductCatalogAttributeEntity::class);
            if (!$metadata instanceof AttributeMetadataCatalogInterface
                || !$entity instanceof ProductCatalogAttributeEntity
            ) {
                return $empty;
            }
        } catch (\Throwable) {
            return $empty;
        }

        $images = [];
        $colors = [];
        try {
            $sets = $productId > 0
                ? $metadata->catalogForProduct($entity, $productId)
                : $metadata->catalog($entity);
            foreach ($sets as $set) {
                if (!$set instanceof AttributeSetMetadata) {
                    continue;
                }
                foreach ($set->toArray()['groups'] ?? [] as $group) {
                    if (!is_array($group)) {
                        continue;
                    }
                    foreach ($group['attributes'] ?? [] as $attribute) {
                        if ($attribute instanceof AttributeMetadata) {
                            $attribute = $attribute->toArray();
                        }
                        if (!is_array($attribute)) {
                            continue;
                        }
                        $code = strtolower(trim((string)($attribute['code'] ?? '')));
                        if ($code === '' || !isset($wanted[$code])) {
                            continue;
                        }
                        foreach ($attribute['options'] ?? [] as $option) {
                            if ($option instanceof AttributeOptionMetadata) {
                                $option = $option->toArray();
                            }
                            if (!is_array($option)) {
                                continue;
                            }
                            $image = trim((string)($option['swatch_image'] ?? ''));
                            $color = trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''));
                            if ($image === '' && $color === '') {
                                continue;
                            }
                            foreach (['id', 'code', 'value', 'label'] as $key) {
                                $token = trim((string)($option[$key] ?? ''));
                                if ($token === '') {
                                    continue;
                                }
                                if ($image !== '' && !isset($images[$code][$token])) {
                                    $images[$code][$token] = $image;
                                }
                                if ($color !== '' && !isset($colors[$code][$token])) {
                                    $colors[$code][$token] = $color;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return $empty;
        }

        return ['images' => $images, 'colors' => $colors];
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    private function notFound(
        OfferIdentity $identity,
        array $selection,
        string $message,
    ): CartItemSnapshot {
        return new CartItemSnapshot(
            offer: $identity,
            name: '',
            found: false,
            sellable: false,
            message: $message,
            selection: $selection,
            sourceModule: 'Weline_Product',
            sourceApp: 'Weline',
        );
    }

    /**
     * @param array<string, scalar|null> $selection
     */
    private function unavailable(
        OfferIdentity $identity,
        array $selection,
        string $message,
        string $sku = '',
        string $name = '',
        string $currency = 'CNY',
    ): CartItemSnapshot {
        return new CartItemSnapshot(
            offer: $identity,
            name: $name,
            sku: $sku,
            currency: $currency,
            found: true,
            sellable: false,
            message: $message,
            selection: $selection,
            sourceModule: 'Weline_Product',
            sourceApp: 'Weline',
        );
    }
}
