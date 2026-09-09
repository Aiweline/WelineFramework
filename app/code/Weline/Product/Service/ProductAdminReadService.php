<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCatalogCopyCapability;
use Weline\Inventory\Api\InventoryCatalogCopyCapabilityInterface;
use Weline\Product\Api\Data\ProductAdminSnapshot;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Theme\Helper\StorefrontImagePlaceholder;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ProductAdminReadService implements ProductAdminReadInterface, \Weline\Product\Api\ProductSelectionReadInterface
{
    private ?InventoryCatalogCopyCapabilityInterface $resolvedInventory = null;
    private bool $inventoryResolved = false;

    public function __construct(
        private readonly ProductIdentityV2Service $identities,
        private readonly ProductProviderRegistry $providers,
        private readonly ProductPublishValidator $publishValidator,
        private readonly ProductAttributeMetadataCatalog $attributeMetadata,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly AttributeValueRepository $attributes,
        private readonly PriceRepository $prices,
        private readonly ProductCatalogQueryConsumer $catalogConsumer,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly MediaRepository $media,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly StoreCatalogInterface $storeCatalog,
        private readonly ProductAdminMediaPresenter $mediaPresenter,
        private readonly ProductBrandAdminService $brandAdmin,
        private readonly ProductSupplierAdminService $supplierAdmin,
        ?InventoryCatalogCopyCapabilityInterface $inventory = null,
    ) {
        $this->resolvedInventory = $inventory;
        $this->inventoryResolved = $inventory !== null;
    }

    public function search(int $websiteId, array $filters = []): array
    {
        return $this->searchRows($websiteId, $filters, true);
    }

    public function searchSelectionRows(int $websiteId, array $filters = []): array
    {
        return $this->searchRows($websiteId, $filters, false);
    }

    private function searchRows(int $websiteId, array $filters, bool $includeDetails): array
    {
        $websiteId = $this->websiteId($websiteId);
        $storeId = isset($filters['store_id']) ? (int)$filters['store_id'] : null;
        $nameFilter = strtolower(trim((string)($filters['name'] ?? '')));
        $skuFilter = strtolower(trim((string)($filters['sku'] ?? '')));
        $codeFilter = strtolower(trim((string)($filters['product_code'] ?? '')));
        $typeFilter = strtolower(trim((string)($filters['product_type'] ?? $filters['type'] ?? '')));
        $statusFilter = strtolower(trim((string)($filters['status'] ?? '')));
        $ownerFilter = array_key_exists('owner_website_id', $filters)
            ? (int)$filters['owner_website_id']
            : null;
        // Selection projections only return the durable product id and update
        // marker.  Identity is authoritative for identity-dependent filters,
        // but resolving it for every published product adds one DB read per
        // row to the common promotion-selection path.
        $selectionNeedsIdentity = $includeDetails
            || $codeFilter !== ''
            || $typeFilter !== ''
            || $ownerFilter !== null;

        $products = [];
        foreach ($this->products->listAll($websiteId) as $product) {
            $productId = (int)($product['product_id'] ?? 0);
            if ($productId <= 0 || ($includeDetails && $storeId !== null
                && !$this->storeProducts->isSelected($websiteId, $storeId, $productId))
            ) {
                continue;
            }
            $products[] = $product;
        }

        $rows = [];
        $activeStores = null;
        // Bound the temporary read maps while reusing the repositories' bulk APIs.
        foreach (array_chunk($products, 200) as $productBatch) {
            $productIds = array_values(array_unique(array_map(
                static fn(array $product): int => (int)$product['product_id'],
                $productBatch,
            )));
            if (!$includeDetails && $storeId !== null) {
                $selected = $this->storeProducts->selectionMap($websiteId, $storeId, $productIds);
                $productBatch = array_values(array_filter(
                    $productBatch,
                    static fn(array $product): bool => $selected[(int)$product['product_id']] ?? true,
                ));
                $productIds = array_column($productBatch, 'product_id');
            }
            if ($productBatch === []) {
                continue;
            }
            $offersByProduct = [];
            $offerRows = $includeDetails || $skuFilter !== ''
                ? $this->offers->listByProductIds($websiteId, $productIds) : [];
            foreach ($offerRows as $offer) {
                $offersByProduct[(int)($offer['product_id'] ?? 0)][] = $offer;
            }
            $attributesByProduct = [];
            $attributeRows = $includeDetails || $nameFilter !== ''
                ? $this->attributes->listExplicitRows($websiteId, 'product', $productIds, [0]) : [];
            foreach ($attributeRows as $attribute) {
                $attributesByProduct[(int)($attribute['entity_id'] ?? 0)][] = $attribute;
            }

            $matchedProducts = [];
            $baseMatches = [];
            foreach ($productBatch as $product) {
                $productId = (int)$product['product_id'];
                $offers = $offersByProduct[$productId] ?? [];
                $offerIds = array_values(array_map(
                    static fn(array $offer): int => (int)($offer['offer_id'] ?? 0),
                    $offers,
                ));
                $attributes = $attributesByProduct[$productId] ?? [];
                $name = '';
                foreach ($attributes as $attribute) {
                    if ((string)($attribute['attribute_code'] ?? '') === 'name'
                        && !($attribute['cleared'] ?? false)
                    ) {
                        $name = trim((string)($attribute['value'] ?? ''));
                        break;
                    }
                }
                $skus = array_values(array_filter(array_map(
                    static fn(array $offer): string => trim((string)($offer['sku'] ?? '')),
                    $offers,
                )));
                if ($skus === []) {
                    $skus[] = trim((string)($product['sku'] ?? ''));
                }
                $status = (string)($product['status'] ?? 'draft');

                if (($nameFilter !== '' && !str_contains(strtolower($name), $nameFilter))
                    || ($skuFilter !== '' && !$this->containsAny($skus, $skuFilter))
                    || ($statusFilter !== '' && strtolower($status) !== $statusFilter)
                ) {
                    continue;
                }

                if (!$selectionNeedsIdentity) {
                    $rows[] = [
                        'product_id' => (int)$product['product_id'],
                        'updated_at' => (string)($product['updated_at'] ?? ''),
                    ];
                    continue;
                }

                $baseMatches[] = compact('product', 'offers', 'offerIds', 'name', 'skus', 'status');
            }

            // Selection filters use authoritative identity fields without a query per product.
            // Base-filter rejections still never reach the identity reader.
            $identities = [];
            if (!$includeDetails && $baseMatches !== []) {
                $uuids = array_values(array_filter(array_map(
                    static fn(array $match): string => trim((string)($match['product']['global_product_uuid'] ?? '')),
                    $baseMatches,
                )));
                if ($uuids !== []) {
                    $identities = $this->identities->resolveProductsByUuids($uuids, strict: true);
                }
            }
            foreach ($baseMatches as ['product' => $product, 'offers' => $offers, 'offerIds' => $offerIds,
                'name' => $name, 'skus' => $skus, 'status' => $status]) {
                $uuid = trim((string)($product['global_product_uuid'] ?? ''));
                $identity = $uuid === '' ? null : ($includeDetails
                    ? $this->identities->resolveProductByUuid($uuid)
                    : ($identities[strtolower($uuid)] ?? null));
                $productCode = $identity?->productCode ?? (string)($product['product_code'] ?? '');
                $productType = $identity?->productType ?? (string)($product['product_type'] ?? 'simple');
                $ownerWebsiteId = $identity?->ownerWebsiteId
                    ?? (int)($product['owner_website_id'] ?? $websiteId);

                if (($codeFilter !== '' && !str_contains(strtolower($productCode), $codeFilter))
                    || ($typeFilter !== '' && strtolower($productType) !== $typeFilter)
                    || ($ownerFilter !== null && $ownerWebsiteId !== $ownerFilter)
                ) {
                    continue;
                }

                $matchedProducts[] = [
                    'product' => $product,
                    'identity' => $identity,
                    'uuid' => $uuid,
                    'offers' => $offers,
                    'offer_ids' => $offerIds,
                    'name' => $name,
                    'skus' => $skus,
                    'product_code' => $productCode,
                    'product_type' => $productType,
                    'status' => $status,
                    'owner_website_id' => $ownerWebsiteId,
                ];
            }
            if ($matchedProducts === []) {
                continue;
            }
            if (!$includeDetails) {
                foreach ($matchedProducts as $matched) {
                    $rows[] = [
                        'product_id' => (int)$matched['product']['product_id'],
                        'updated_at' => (string)($matched['product']['updated_at'] ?? ''),
                    ];
                }
                continue;
            }

            $matchedProductIds = [];
            $offerProductIds = [];
            foreach ($matchedProducts as $matched) {
                $productId = (int)$matched['product']['product_id'];
                $matchedProductIds[] = $productId;
                foreach ($matched['offer_ids'] as $offerId) {
                    $offerProductIds[$offerId] = $productId;
                }
            }
            $pricesByProduct = [];
            foreach ($this->prices->listExplicitRows($websiteId, array_keys($offerProductIds), [0]) as $priceRow) {
                $ownerProductId = $offerProductIds[(int)($priceRow['offer_id'] ?? 0)] ?? null;
                if ($ownerProductId !== null) {
                    $pricesByProduct[$ownerProductId][] = $priceRow;
                }
            }
            $mediaByProduct = [];
            foreach ($this->media->listByProductIds($websiteId, $matchedProductIds) as $mediaRow) {
                $mediaByProduct[(int)($mediaRow['product_id'] ?? 0)][] = $mediaRow;
            }
            $activeStores ??= $this->activeStores($websiteId);

            foreach ($matchedProducts as [
                'product' => $product,
                'identity' => $identity,
                'uuid' => $uuid,
                'offers' => $offers,
                'offer_ids' => $offerIds,
                'name' => $name,
                'skus' => $skus,
                'product_code' => $productCode,
                'product_type' => $productType,
                'status' => $status,
                'owner_website_id' => $ownerWebsiteId,
            ]) {
                $productId = (int)$product['product_id'];
                $priceRows = $pricesByProduct[$productId] ?? [];
                $mediaRows = $mediaByProduct[$productId] ?? [];
                $selectedStores = array_values(array_filter(
                    array_map(
                        fn(array $store): ?int => $this->storeProducts->isSelected(
                            $websiteId,
                            (int)$store['store_id'],
                            $productId,
                        ) ? (int)$store['store_id'] : null,
                        $activeStores,
                    ),
                    static fn(?int $id): bool => $id !== null,
                ));
                $rows[] = [
                    'website_id' => $websiteId,
                    'product_id' => $productId,
                    'global_product_uuid' => $uuid,
                    'product_code' => $productCode,
                    'owner_website_id' => $ownerWebsiteId,
                    'product_type' => $productType,
                    'status' => $status,
                    'name' => $name,
                    'skus' => $skus,
                    'offer_count' => count($offers),
                    'prices' => $priceRows,
                    'main_media' => $this->mediaPresenter->presentMainMedia($mediaRows[0] ?? null, $websiteId),
                    'selected_store_ids' => $selectedStores,
                    'updated_at' => (string)($product['updated_at'] ?? ''),
                    'identity_version' => $identity?->version ?? 0,
                    'local_version' => (int)($product['publish_version'] ?? 0),
                ];
            }
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => [
                (string)($right['updated_at'] ?? ''),
                (int)($right['product_id'] ?? 0),
            ] <=> [
                (string)($left['updated_at'] ?? ''),
                (int)($left['product_id'] ?? 0),
            ],
        );
        return $rows;
    }

    public function creationContext(int $websiteId): array
    {
        $websiteId = $this->websiteId($websiteId);
        $types = [];
        foreach ($this->providers->all(true) as $provider) {
            $v2 = $provider instanceof ProductProviderV2Interface
                ? $provider
                : new ProductProviderV1Adapter($provider);
            $definition = $v2->getDefinition()->toArray();
            $definition['provider_code'] = $provider->getCode();
            $types[] = $definition;
        }
        usort(
            $types,
            static function (array $left, array $right): int {
                $rank = static function (array $row): int {
                    return (string)($row['code'] ?? '') === 'configurable' ? 0 : 1;
                };
                $leftRank = $rank($left);
                $rightRank = $rank($right);
                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                return (string)($left['code'] ?? '') <=> (string)($right['code'] ?? '');
            },
        );
        $stores = $this->activeStores($websiteId);
        foreach ($stores as &$store) {
            $store['selected'] = true;
        }
        unset($store);

        return [
            'website_id' => $websiteId,
            'product_types' => $types,
            'attribute_catalog' => $this->attributeMetadata->editorCatalog(),
            'stores' => $stores,
            'categories' => $this->categoryCatalog($websiteId, ''),
            'brands' => $this->brandAdmin->catalogOptions($websiteId),
            'suppliers' => $this->supplierAdmin->catalogOptions($websiteId),
            'shipping_profiles' => $this->shippingProfileOptions(),
            'default_store_ids' => array_values(array_map(
                static fn(array $store): int => (int)$store['store_id'],
                $stores,
            )),
        ];
    }

    /**
     * @return list<array{code:string,label:string,is_free_shipping:bool}>
     */
    private function shippingProfileOptions(): array
    {
        try {
            /** @var \Weline\Product\Service\Storefront\StorefrontShippingProfileCatalogProviderRegistry $registry */
            $registry = ObjectManager::getInstance(
                \Weline\Product\Service\Storefront\StorefrontShippingProfileCatalogProviderRegistry::class,
            );
            $provider = $registry->primary();
            if ($provider === null) {
                return [];
            }
            $out = [];
            foreach ($provider->listActiveProfiles() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $out[] = [
                    'code' => $code,
                    'label' => (string)($row['label'] ?? $code),
                    'is_free_shipping' => !empty($row['is_free_shipping']),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public function snapshot(
        int $websiteId,
        string $globalProductUuid,
        ?int $storeId = null,
        string $locale = '',
        string $currency = 'CNY',
    ): ProductAdminSnapshot {
        $data = $this->collect($websiteId, $globalProductUuid, $storeId, $locale, $currency);
        $context = $this->contextFrom($data);
        $diagnostics = $this->publishDiagnostics($context);

        return new ProductAdminSnapshot(
            websiteId: $data['website_id'],
            identity: $data['identity'],
            product: $data['product'],
            offers: $data['offers'],
            attributes: $data['attributes'],
            attributeCatalog: $data['attribute_catalog'],
            prices: $data['prices'],
            categories: $data['categories'],
            media: $data['media'],
            stores: $data['stores'],
            provider: $data['provider'],
            diagnostics: $diagnostics,
            permissions: $data['permissions'],
            offerMatrix: $data['offer_matrix'],
            audit: $this->identities->listAudit($globalProductUuid),
            categoryAssignments: $data['category_assignments'],
            mediaAssignments: $data['media_assignments'],
            storeCategoryOverrides: $data['store_category_overrides'],
            storeMediaOverrides: $data['store_media_overrides'],
            inventory: $data['inventory'],
        );
    }

    public function attributeCatalog(int $websiteId, string $globalProductUuid): array
    {
        $data = $this->collect($websiteId, $globalProductUuid, null, '', 'CNY');
        $productId = (int)($data['product']['id'] ?? $data['product']['product_id'] ?? 0);
        $catalog = is_array($data['attribute_catalog'] ?? null) ? $data['attribute_catalog'] : [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        return [
            'attribute_catalog' => $catalog,
            'attributes' => $attributes,
            'selected_attribute_set_id' => $this->resolveSelectedAttributeSetId($catalog, $attributes),
            'lock_attribute_set' => $productId > 0,
            'product_id' => $productId,
        ];
    }

    public function slugAvailability(int $websiteId, string $slug, int $excludeProductId = 0): array
    {
        $websiteId = $this->websiteId($websiteId);
        $slug = strtolower(trim($slug));
        $excludeProductId = max(0, $excludeProductId);
        if ($slug === '') {
            return [
                'slug' => '',
                'available' => true,
                'reason' => 'empty',
                'conflict_product_id' => 0,
            ];
        }
        if (preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return [
                'slug' => $slug,
                'available' => false,
                'reason' => 'invalid',
                'conflict_product_id' => 0,
            ];
        }

        $conflictIds = $this->attributes->findEntityIdsByAttributeValue(
            $websiteId,
            'product',
            'slug',
            $slug,
            0,
        );
        $conflictIds = array_values(array_filter(
            $conflictIds,
            static fn(int $id): bool => $id > 0 && $id !== $excludeProductId,
        ));
        $conflictId = $conflictIds[0] ?? 0;

        return [
            'slug' => $slug,
            'available' => $conflictId <= 0,
            'reason' => $conflictId > 0 ? 'taken' : 'ok',
            'conflict_product_id' => $conflictId,
        ];
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $catalog
     * @param list<array<string, mixed>> $attributes
     */
    private function resolveSelectedAttributeSetId(array $catalog, array $attributes): string
    {
        $sets = array_is_list($catalog)
            ? $catalog
            : ($catalog['sets'] ?? $catalog['attribute_sets'] ?? []);
        if (!is_array($sets) || $sets === []) {
            return '0';
        }
        $currentSetCode = '';
        foreach ($attributes as $attributeRow) {
            if (!is_array($attributeRow)
                || (string)($attributeRow['entity_type'] ?? 'product') !== 'product'
                || (int)($attributeRow['store_id'] ?? 0) !== 0
                || (string)($attributeRow['locale'] ?? '') !== ''
                || (string)($attributeRow['attribute_code'] ?? '') !== 'attribute_set'
            ) {
                continue;
            }
            $currentSetCode = trim((string)($attributeRow['value'] ?? ''));
            break;
        }
        if ($currentSetCode !== '') {
            foreach ($sets as $attributeSet) {
                if (!is_array($attributeSet)) {
                    continue;
                }
                $candidateId = (string)(
                    $attributeSet['attribute_set_id'] ?? $attributeSet['set_id'] ?? $attributeSet['id'] ?? ''
                );
                $candidateCode = (string)($attributeSet['code'] ?? '');
                if ($candidateCode === $currentSetCode || $candidateId === $currentSetCode) {
                    return $candidateId !== '' ? $candidateId : $candidateCode;
                }
            }
        }
        $firstSet = reset($sets);

        return is_array($firstSet)
            ? (string)(
                $firstSet['attribute_set_id']
                ?? $firstSet['set_id']
                ?? $firstSet['id']
                ?? $firstSet['code']
                ?? '0'
            )
            : '0';
    }

    public function validationContext(
        int $websiteId,
        string $globalProductUuid,
        ?int $storeId = null,
        string $locale = '',
        string $currency = 'CNY',
    ): ProductValidationContext {
        return $this->contextFrom(
            $this->collect($websiteId, $globalProductUuid, $storeId, $locale, $currency),
        );
    }

    /** @return array<string, mixed> */
    private function collect(
        int $websiteId,
        string $globalProductUuid,
        ?int $storeId,
        string $locale,
        string $currency,
    ): array {
        $websiteId = $this->websiteId($websiteId);
        $globalProductUuid = trim($globalProductUuid);
        $identity = $this->identities->resolveProductByUuid($globalProductUuid)
            ?? throw new \InvalidArgumentException('product_v2_identity_not_found');
        $productModel = $this->products->findByGlobalUuid($websiteId, $globalProductUuid)
            ?? throw new \InvalidArgumentException('product_website_projection_not_found');
        $product = $productModel->getData();
        $productId = (int)$productModel->getId();
        $offers = $this->offers->listByProductIds($websiteId, [$productId]);
        $offerIds = array_values(array_map(
            static fn(array $offer): int => (int)($offer['offer_id'] ?? 0),
            $offers,
        ));
        $identityOffers = [];
        foreach ($this->identities->listOffers($globalProductUuid, false) as $offerIdentity) {
            $identityOffers[$offerIdentity->globalOfferUuid] = $offerIdentity;
        }
        foreach ($offers as &$offer) {
            $uuid = (string)($offer['global_offer_uuid'] ?? '');
            $offerIdentity = $identityOffers[$uuid] ?? null;
            if ($offerIdentity !== null) {
                $offer['sku'] = $offerIdentity->sku;
                $offer['identity_version'] = $offerIdentity->version;
                $offer['identity_status'] = $offerIdentity->status;
            }
            $offer['combination'] = $this->offerCombination($offer);
        }
        unset($offer);

        $activeStores = $this->activeStores($websiteId);
        if ($storeId !== null) {
            $activeStores = array_values(array_filter(
                $activeStores,
                static fn(array $store): bool => (int)$store['store_id'] === $storeId,
            ));
            if ($activeStores === []) {
                throw new \InvalidArgumentException('product_admin_store_not_active');
            }
        }
        $scopeStoreIds = array_merge([0], array_map(
            static fn(array $store): int => (int)$store['store_id'],
            $activeStores,
        ));
        $attributes = array_merge(
            $this->attributes->listExplicitRows($websiteId, 'product', [$productId], $scopeStoreIds),
            $this->attributes->listExplicitRows($websiteId, 'offer', $offerIds, $scopeStoreIds),
        );
        $prices = $this->prices->listExplicitRows($websiteId, $offerIds, $scopeStoreIds);
        $offerUuidById = [];
        foreach ($offers as $offer) {
            $offerUuidById[(int)($offer['offer_id'] ?? 0)] = (string)($offer['global_offer_uuid'] ?? '');
        }
        foreach ($prices as &$price) {
            $price['global_offer_uuid'] = $offerUuidById[(int)$price['offer_id']] ?? '';
        }
        unset($price);

        $categoryLinks = $this->categoryLinks->listByProductIds(
            $websiteId,
            [$productId],
            $scopeStoreIds,
        );
        $categoryAssignments = array_values(array_filter(
            $categoryLinks,
            static fn(array $row): bool => (int)($row['store_id'] ?? 0) === 0,
        ));
        $storeCategoryOverrides = array_values(array_filter(
            $categoryLinks,
            static fn(array $row): bool => (int)($row['store_id'] ?? 0) > 0,
        ));

        $rawMediaRows = $this->media->listByProductIds($websiteId, [$productId], $scopeStoreIds);
        $mediaRows = array_map(
            fn(array $row): array => $this->adminMediaRow($row, $websiteId),
            $rawMediaRows,
        );
        $mediaAssignments = array_values(array_filter(
            $mediaRows,
            static fn(array $row): bool => (int)($row['store_id'] ?? 0) === 0,
        ));
        $storeMediaOverrides = array_values(array_filter(
            $mediaRows,
            static fn(array $row): bool => (int)($row['store_id'] ?? 0) > 0,
        ));

        $selectedStoreIds = [];
        $storeRows = [];
        foreach ($activeStores as $store) {
            $currentStoreId = (int)$store['store_id'];
            $productSelected = $this->storeProducts->isSelected(
                $websiteId,
                $currentStoreId,
                $productId,
            );
            $selectedOfferIds = [];
            foreach ($offers as $offer) {
                $offerId = (int)($offer['offer_id'] ?? 0);
                if ($productSelected
                    && $this->storeOffers->isSelected($websiteId, $currentStoreId, $offerId)
                ) {
                    $selectedOfferIds[] = $offerId;
                }
            }
            if ($productSelected && $selectedOfferIds !== []) {
                $selectedStoreIds[] = $currentStoreId;
            }
            $store['product_selected'] = $productSelected;
            $store['selected_offer_ids'] = $selectedOfferIds;
            $storeRows[] = $store;
        }

        $provider = $this->providers->getByType($identity->productType, false);
        $providerDefinition = [];
        if ($provider !== null) {
            $v2 = $provider instanceof ProductProviderV2Interface
                ? $provider
                : new ProductProviderV1Adapter($provider);
            $providerDefinition = $v2->getDefinition()->toArray();
            $providerDefinition['provider_code'] = $provider->getCode();
            $providerDefinition['enabled'] = $provider->isEnabled();
        }

        $typeConfiguration = [];
        foreach ($attributes as $attribute) {
            if ((string)($attribute['entity_type'] ?? '') === 'product'
                && (int)($attribute['entity_id'] ?? 0) === $productId
                && (int)($attribute['store_id'] ?? 0) === 0
                && (string)($attribute['attribute_code'] ?? '') === 'type_configuration'
            ) {
                $raw = $attribute['value'] ?? null;
                if (is_array($raw)) {
                    $typeConfiguration = $raw;
                } elseif (is_string($raw) && trim($raw) !== '') {
                    try {
                        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $typeConfiguration = $decoded;
                        }
                    } catch (\JsonException) {
                        $typeConfiguration = [];
                    }
                }
                break;
            }
        }

        $providerForm = $this->providerFormFields(
            is_array($providerDefinition['form_schema'] ?? null)
                ? $providerDefinition['form_schema']
                : [],
            $typeConfiguration,
        );
        $providerFields = $providerForm['fields'];
        $providerUnknownFields = $providerForm['unknown'];

        $isOwner = $identity->ownerWebsiteId === $websiteId;
        $isArchived = (string)($product['status'] ?? '') === Product::STATUS_ARCHIVED;
        $permissions = [
            'is_owner' => $isOwner,
            'edit_structure' => $isOwner && !$isArchived,
            'edit_business' => !$isArchived,
            'share' => $isOwner && !$isArchived,
            'transfer' => $isOwner && !$isArchived,
        ];
        $offerMatrix = $this->offerMatrix(
            $productId,
            $identity->productType,
            $identity->productCode,
            $offers,
            $prices,
            $typeConfiguration,
            $currency,
            $isOwner,
            $rawMediaRows,
            $websiteId,
        );
        $inventory = $this->inventorySnapshot(
            $websiteId,
            $storeRows,
            $offers,
            !empty($providerDefinition['capabilities']['inventory']),
        );
        $inventory = $this->withInventoryOfferImages(
            $inventory,
            is_array($offerMatrix['rows'] ?? null) ? $offerMatrix['rows'] : [],
        );

        return [
            'website_id' => $websiteId,
            'locale' => trim($locale),
            'currency' => strtoupper(trim($currency)) ?: 'CNY',
            'identity' => $identity->toArray(),
            'product' => $product,
            'offers' => $offers,
            'attributes' => $attributes,
            'attribute_catalog' => $this->attributeMetadata->editorCatalog($productId),
            'prices' => $prices,
            'categories' => $this->categoryCatalog($websiteId, $locale),
            'category_assignments' => $categoryAssignments,
            'store_category_overrides' => $storeCategoryOverrides,
            'media' => $mediaAssignments,
            'media_assignments' => $mediaAssignments,
            'store_media_overrides' => $storeMediaOverrides,
            'stores' => $storeRows,
            'selected_store_ids' => $selectedStoreIds,
            'provider' => $providerDefinition,
            'type_configuration' => $typeConfiguration,
            'provider_fields' => $providerFields,
            'provider_unknown_fields' => $providerUnknownFields,
            'offer_matrix' => $offerMatrix,
            'permissions' => $permissions,
            'inventory' => $inventory,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function categoryCatalog(int $websiteId, string $locale): array
    {
        $rows = $this->catalogConsumer->flatRows($websiteId, $locale);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int)($row['is_active'] ?? 1) === 0
                || strtolower(trim((string)($row['status'] ?? 'active'))) === 'inactive') {
                continue;
            }
            $categoryId = max(0, (int)($row['category_id'] ?? $row['id'] ?? 0));
            if ($categoryId <= 0) {
                continue;
            }
            $path = trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/');
            $name = trim((string)($row['name'] ?? ''));
            $normalized[] = [
                'category_id' => $categoryId,
                'id' => $categoryId,
                'parent_id' => max(0, (int)($row['parent_id'] ?? $row['pid'] ?? 0)),
                'path' => $path !== '' ? '/' . ltrim($path, '/') : '',
                'name' => $name !== '' ? $name : ($path !== '' ? $path : '#' . $categoryId),
            ];
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function adminMediaRow(array $row, int $websiteId = 0): array
    {
        return $this->mediaPresenter->presentAssignment([
            'media_id' => (int)($row['media_id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'scope_state' => (string)($row['scope_state'] ?? 'explicit'),
            'hidden' => (int)($row['hidden'] ?? 0) === 1,
            'role' => (string)($row['role'] ?? 'gallery'),
            'combination_key' => trim((string)($row['combination_key'] ?? '')),
            'asset_id' => trim((string)($row['asset_id'] ?? '')),
            'asset_visibility' => (string)($row['asset_visibility'] ?? 'public'),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'access_policy_json' => $row['access_policy_json'] ?? null,
            'path' => trim((string)($row['path'] ?? '')),
            'position' => (int)($row['position'] ?? 0),
            'legacy' => trim((string)($row['asset_id'] ?? '')) === '',
        ], $websiteId);
    }

    /**
     * Decode Offer type_config_json / type_config into an array.
     *
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function offerTypeConfiguration(array $offer): array
    {
        $raw = $offer['type_config_json'] ?? $offer['type_config'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Resolve Offer combination for validation and matrix rows.
     *
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function offerCombination(array $offer): array
    {
        if (is_array($offer['combination'] ?? null)) {
            return $offer['combination'];
        }
        $key = trim((string)($offer['combination_key'] ?? ''));
        if ($key === '') {
            return [];
        }
        $combination = [];
        foreach (explode('|', $key) as $segment) {
            if (!str_contains($segment, '=')) {
                return [];
            }
            [$code, $value] = explode('=', $segment, 2);
            $code = strtolower(trim(rawurldecode($code)));
            $value = trim(rawurldecode($value));
            if ($code === '' || $value === '') {
                return [];
            }
            $combination[$code] = $value;
        }
        ksort($combination, SORT_STRING);
        return $combination;
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @param list<array<string,mixed>> $prices
     * @param array<string,mixed> $typeConfiguration
     * @param list<array<string,mixed>> $mediaRows
     * @return array<string,mixed>
     */
    private function offerMatrix(
        int $productId,
        string $productType,
        string $productCode,
        array $offers,
        array $prices,
        array $typeConfiguration,
        string $currency,
        bool $canEditStructure,
        array $mediaRows = [],
        int $websiteId = 0,
    ): array {
        if ($productType !== 'configurable') {
            return [
                'enabled' => false,
                'axes' => [],
                'sku_prefix' => '',
                'currency' => strtoupper(trim($currency)) ?: 'CNY',
                'rows' => [],
                'can_edit_structure' => false,
            ];
        }

        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $priceByOfferId = [];
        foreach ($prices as $price) {
            if ((int)($price['store_id'] ?? 0) !== 0
                || strtoupper((string)($price['currency'] ?? '')) !== $currency
            ) {
                continue;
            }
            $priceByOfferId[(int)($price['offer_id'] ?? 0)] = $price;
        }

        $variantImageByKey = [];
        $variantSwatchByAxis = [];
        foreach ($mediaRows as $media) {
            if (!is_array($media)) {
                continue;
            }
            if ((int)($media['store_id'] ?? 0) !== 0) {
                continue;
            }
            $path = trim((string)($media['path'] ?? ''));
            if ($path === '') {
                $assetId = trim((string)($media['asset_id'] ?? ''));
                if ($assetId !== '') {
                    $path = 'asset://' . $assetId;
                }
            }
            if ($path === '') {
                continue;
            }
            $role = strtolower(trim((string)($media['role'] ?? '')));
            if ($role !== 'variant') {
                continue;
            }
            $mediaKey = trim((string)($media['combination_key'] ?? ''));
            if ($mediaKey === '') {
                continue;
            }
            if (!isset($variantImageByKey[$mediaKey])) {
                $variantImageByKey[$mediaKey] = $path;
            }
            foreach ($this->offerCombination(['combination_key' => $mediaKey]) as $axis => $value) {
                $axisCode = strtolower(trim((string)$axis));
                $optionValue = trim((string)$value);
                if ($axisCode === '' || $axisCode === 'size' || $optionValue === '') {
                    continue;
                }
                if (!isset($variantSwatchByAxis[$axisCode][$optionValue])) {
                    $variantSwatchByAxis[$axisCode][$optionValue] = $path;
                }
            }
        }

        $axisValues = [];
        $rows = [];
        foreach ($offers as $offer) {
            $combination = $this->offerCombination($offer);
            foreach ($combination as $code => $value) {
                $axisValues[(string)$code][(string)$value] = true;
            }
            $price = $priceByOfferId[(int)($offer['offer_id'] ?? 0)] ?? null;
            $scopeState = $price === null
                ? 'cleared'
                : (string)($price['scope_state']
                    ?? (!empty($price['cleared']) ? 'cleared' : 'explicit'));
            $combinationKey = (string)($offer['combination_key'] ?? '');
            if ($combinationKey === '' && $combination !== []) {
                $segments = [];
                foreach ($combination as $axis => $value) {
                    $segments[] = rawurlencode((string)$axis) . '=' . rawurlencode((string)$value);
                }
                $combinationKey = implode('|', $segments);
            }
            $imagePath = $variantImageByKey[$combinationKey] ?? '';
            if ($imagePath === '') {
                foreach ($combination as $axis => $value) {
                    $axisCode = strtolower(trim((string)$axis));
                    $optionValue = trim((string)$value);
                    if ($axisCode === '' || $axisCode === 'size' || $optionValue === '') {
                        continue;
                    }
                    $imagePath = trim((string)($variantSwatchByAxis[$axisCode][$optionValue] ?? ''));
                    if ($imagePath !== '') {
                        break;
                    }
                }
            }
            $imageIsPlaceholder = false;
            if ($imagePath === '') {
                $imageUrl = StorefrontImagePlaceholder::url();
                $imageIsPlaceholder = true;
            } else {
                $imageUrl = $this->mediaPresenter->displayableImageUrl($imagePath, $websiteId);
            }
            $row = [
                'offer_id' => (int)($offer['offer_id'] ?? 0),
                'global_offer_uuid' => (string)($offer['global_offer_uuid'] ?? ''),
                'sku' => (string)($offer['sku'] ?? ''),
                'combination' => $combination,
                'combination_key' => $combinationKey,
                'offer_version' => (int)($offer['publish_version'] ?? 0),
                'identity_version' => (int)($offer['identity_version'] ?? 0),
                'status' => (string)($offer['status'] ?? 'draft'),
                'identity_status' => (string)($offer['identity_status'] ?? 'active'),
                'scope_state' => $scopeState,
                'currency' => $currency,
                'image' => $imagePath,
                'image_url' => $imageUrl,
                'image_is_placeholder' => $imageIsPlaceholder,
                'image_asset_id' => str_starts_with(strtolower($imagePath), 'asset://')
                    ? substr($imagePath, strlen('asset://'))
                    : '',
            ];
            if ($price !== null
                && $scopeState === 'explicit'
                && array_key_exists('amount_minor', $price)
                && $price['amount_minor'] !== null
            ) {
                $row['amount_minor'] = (int)$price['amount_minor'];
            }
            $rows[] = $row;
        }

        $metadata = [];
        foreach ($this->attributeMetadata->editorCatalog($productId) as $set) {
            foreach (is_array($set['groups'] ?? null) ? $set['groups'] : [] as $group) {
                foreach (is_array($group['attributes'] ?? null) ? $group['attributes'] : [] as $attribute) {
                    $code = strtolower(trim((string)($attribute['code'] ?? '')));
                    if ($code !== '') {
                        $metadata[$code] = $attribute;
                    }
                }
            }
        }
        $axes = [];
        foreach ($axisValues as $code => $values) {
            $attribute = is_array($metadata[$code] ?? null) ? $metadata[$code] : [];
            $optionMetadata = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
            $options = [];
            foreach (array_keys($values) as $value) {
                $entry = ['value' => $value, 'label' => $value];
                foreach ($optionMetadata as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    if ($value === (string)($option['value'] ?? '')
                        || $value === (string)($option['code'] ?? '')
                        || $value === (string)($option['option_id'] ?? '')
                    ) {
                        $label = trim((string)($option['label'] ?? $option['name'] ?? $value));
                        $entry = ['value' => $value, 'label' => $label !== '' ? $label : $value];
                        $swatchColor = trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''));
                        $swatchImage = trim((string)($option['swatch_image'] ?? ''));
                        if ($swatchColor !== '') {
                            $entry['swatch_color'] = $swatchColor;
                            $entry['swatch'] = $swatchColor;
                        }
                        if ($swatchImage !== '') {
                            $entry['swatch_image'] = $swatchImage;
                        }
                        break;
                    }
                }
                $axisCode = strtolower(trim((string)$code));
                if ($axisCode !== 'size') {
                    $variantSwatch = trim((string)($variantSwatchByAxis[$axisCode][(string)$value] ?? ''));
                    if ($variantSwatch !== '') {
                        $resolved = $this->mediaPresenter->displayableImageUrl($variantSwatch, $websiteId);
                        if ($resolved !== '') {
                            $entry['swatch_image'] = $resolved;
                        } elseif (trim((string)($entry['swatch_image'] ?? '')) === '') {
                            $entry['swatch_image'] = $variantSwatch;
                        }
                    } elseif (trim((string)($entry['swatch_image'] ?? '')) !== '') {
                        $resolved = $this->mediaPresenter->displayableImageUrl(
                            (string)$entry['swatch_image'],
                            $websiteId,
                        );
                        if ($resolved !== '') {
                            $entry['swatch_image'] = $resolved;
                        }
                    }
                }
                $options[] = $entry;
            }
            $axes[] = [
                'code' => $code,
                'label' => (string)($attribute['name'] ?? match ($code) {
                    'color' => '颜色',
                    'size' => '尺码',
                    'style_type' => '类型',
                    'character' => '角色',
                    'look_ref' => '图款',
                    'prop' => '配件',
                    default => $code,
                }),
                'options' => $options,
            ];
        }

        $skuPrefix = '';
        $firstRow = $rows[0] ?? null;
        if (is_array($firstRow)) {
            $firstSku = trim((string)($firstRow['sku'] ?? ''));
            $suffix = [];
            foreach ((array)($firstRow['combination'] ?? []) as $value) {
                $part = strtoupper(trim((string)preg_replace('/[^A-Z0-9]+/i', '-', (string)$value), '-'));
                if ($part !== '') {
                    $suffix[] = $part;
                }
            }
            $suffixText = $suffix === [] ? '' : '-' . implode('-', $suffix);
            if ($suffixText !== ''
                && strlen($firstSku) > strlen($suffixText)
                && strcasecmp(substr($firstSku, -strlen($suffixText)), $suffixText) === 0
            ) {
                $skuPrefix = substr($firstSku, 0, -strlen($suffixText));
            }
        }
        if ($skuPrefix === '') {
            $skuPrefix = trim($productCode);
        }
        if ($skuPrefix === '') {
            $skuPrefix = 'PRODUCT';
        }

        return [
            'enabled' => true,
            'axes' => $axes,
            'sku_prefix' => $skuPrefix,
            'currency' => $currency,
            'rows' => $rows,
            'can_edit_structure' => $canEditStructure,
        ];
    }

    /**
     * Normalize extension-owned form metadata before the admin template consumes it.
     *
     * @param array<string,mixed> $formSchema
     * @param array<string,mixed> $configuration
     * @return array{fields:list<array<string,mixed>>,unknown:array<string,mixed>}
     */
    private function providerFormFields(array $formSchema, array $configuration): array
    {
        $definitions = $formSchema['fields'] ?? [];
        if (!is_array($definitions)) {
            return ['fields' => [], 'unknown' => $configuration];
        }

        $fields = [];
        $known = [];
        $allowedTypes = [
            'string',
            'text',
            'integer',
            'decimal',
            'boolean',
            'select',
            'multiselect',
            'json',
        ];
        foreach ($definitions as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $code = trim((string)($definition['code'] ?? (is_string($key) ? $key : '')));
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $code) || isset($known[$code])) {
                continue;
            }
            $known[$code] = true;
            $type = strtolower(trim((string)($definition['type'] ?? 'string')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'string';
            }
            $label = trim((string)($definition['label'] ?? ''));
            $field = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'type' => $type,
                'required' => (bool)($definition['required'] ?? false),
                'readonly' => (bool)($definition['readonly'] ?? $definition['read_only'] ?? false),
                'value' => array_key_exists($code, $configuration)
                    ? $configuration[$code]
                    : ($definition['default'] ?? null),
                'options' => $this->providerFieldOptions($definition['options'] ?? []),
            ];
            foreach (['help', 'placeholder'] as $textKey) {
                $text = trim((string)($definition[$textKey] ?? ''));
                if ($text !== '') {
                    $field[$textKey] = $text;
                }
            }
            foreach (['min', 'max', 'step', 'rows'] as $numberKey) {
                if (isset($definition[$numberKey]) && is_numeric($definition[$numberKey])) {
                    $field[$numberKey] = (float)$definition[$numberKey];
                }
            }
            $fields[] = $field;
        }

        $unknown = array_diff_key($configuration, $known);
        ksort($unknown);
        return ['fields' => $fields, 'unknown' => $unknown];
    }

    /** @return list<array{value:string,label:string}> */
    private function providerFieldOptions(mixed $definitions): array
    {
        if (!is_array($definitions)) {
            return [];
        }
        $options = [];
        $seen = [];
        foreach ($definitions as $key => $definition) {
            if (is_array($definition)) {
                $value = trim((string)($definition['value'] ?? (is_string($key) ? $key : '')));
                $label = trim((string)($definition['label'] ?? $value));
            } elseif (is_string($key)) {
                $value = trim($key);
                $label = trim((string)$definition);
            } else {
                $value = trim((string)$definition);
                $label = $value;
            }
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }
        return $options;
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    private function publishDiagnostics(ProductValidationContext $context): array
    {
        $validation = $this->publishValidator->validate($context);
        if ($context->storeIds === []) {
            $validation = $validation->merge(new ProductValidationResult(errors: [[
                'code' => 'product_publish_store_required',
                'message' => (string)__('至少选择一个活动 Store 才能发布'),
                'path' => 'stores',
            ]]));
        }
        return $validation->toArray($context);
    }

    private function contextFrom(array $data): ProductValidationContext
    {
        return new ProductValidationContext(
            productType: (string)$data['identity']['product_type'],
            product: $data['product'],
            offers: $data['offers'],
            attributes: $data['attributes'],
            prices: $data['prices'],
            media: $data['media'],
            storeIds: $data['selected_store_ids'],
            typeConfiguration: $data['type_configuration'],
            locale: (string)($data['locale'] ?? ''),
            currency: (string)($data['currency'] ?? 'CNY'),
            inventory: is_array($data['inventory'] ?? null) ? $data['inventory'] : [],
            stores: is_array($data['stores'] ?? null) ? $data['stores'] : [],
        );
    }

    /**
     * @param list<array<string,mixed>> $stores
     * @param list<array<string,mixed>> $offers
     * @return array<string,mixed>
     */
    private function inventorySnapshot(
        int $websiteId,
        array $stores,
        array $offers,
        bool $tracksInventory,
    ): array {
        if (!$tracksInventory) {
            return [
                'enabled' => false,
                'capability_available' => false,
                'editable' => false,
                'rows' => [],
                'errors' => [],
            ];
        }

        $inventory = $this->inventory();
        if ($inventory === null) {
            return [
                'enabled' => true,
                'capability_available' => false,
                'editable' => false,
                'rows' => [],
                'errors' => [[
                    'code' => 'product_inventory_capability_unavailable',
                    'message' => (string)__('库存模块当前不可用，商品其他信息仍可编辑'),
                ]],
            ];
        }

        $rows = [];
        $errors = [];
        foreach ($stores as $store) {
            if (empty($store['product_selected'])) {
                continue;
            }
            $storeId = (int)($store['store_id'] ?? 0);
            $selectedOffers = array_fill_keys(
                array_map('intval', (array)($store['selected_offer_ids'] ?? [])),
                true,
            );
            foreach ($offers as $offer) {
                $offerId = (int)($offer['offer_id'] ?? 0);
                if ($storeId < 0 || $offerId <= 0 || !isset($selectedOffers[$offerId])) {
                    continue;
                }
                try {
                    $availability = $inventory->getAvailability($websiteId, $storeId, $offerId);
                } catch (Throwable) {
                    $errors[] = [
                        'code' => 'product_inventory_read_failed',
                        'message' => (string)__('库存读取失败，请稍后重试'),
                        'store_id' => $storeId,
                        'offer_id' => $offerId,
                    ];
                    continue;
                }
                $rows[] = array_merge($availability->toArray(), [
                    'global_offer_uuid' => (string)($offer['global_offer_uuid'] ?? ''),
                    'sku' => (string)($offer['sku'] ?? ''),
                    'store_name' => (string)($store['name'] ?? ''),
                    'store_code' => (string)($store['code'] ?? ''),
                ]);
            }
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [
                (int)$left['store_id'],
                (string)$left['sku'],
                (int)$left['offer_id'],
            ] <=> [
                (int)$right['store_id'],
                (string)$right['sku'],
                (int)$right['offer_id'],
            ],
        );

        return [
            'enabled' => true,
            'capability_available' => true,
            'editable' => $errors === [],
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * Attach Offer variant thumbnails onto inventory matrix rows for admin recognition.
     *
     * @param array<string,mixed> $inventory
     * @param list<array<string,mixed>> $matrixRows
     * @return array<string,mixed>
     */
    private function withInventoryOfferImages(array $inventory, array $matrixRows): array
    {
        $rows = is_array($inventory['rows'] ?? null) ? $inventory['rows'] : [];
        if ($rows === []) {
            return $inventory;
        }

        $imagesByOfferId = [];
        foreach ($matrixRows as $matrixRow) {
            if (!is_array($matrixRow)) {
                continue;
            }
            $offerId = (int)($matrixRow['offer_id'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }
            $imagesByOfferId[$offerId] = [
                'image_url' => (string)($matrixRow['image_url'] ?? ''),
                'image_is_placeholder' => !empty($matrixRow['image_is_placeholder']),
            ];
        }

        $placeholder = StorefrontImagePlaceholder::url();
        foreach ($rows as &$row) {
            $offerId = (int)($row['offer_id'] ?? 0);
            $image = $imagesByOfferId[$offerId] ?? null;
            $imageUrl = is_array($image) ? trim((string)($image['image_url'] ?? '')) : '';
            $isPlaceholder = is_array($image) ? !empty($image['image_is_placeholder']) : true;
            if ($imageUrl === '') {
                $imageUrl = $placeholder;
                $isPlaceholder = true;
            }
            $row['image_url'] = $imageUrl;
            $row['image_is_placeholder'] = $isPlaceholder;
        }
        unset($row);
        $inventory['rows'] = $rows;

        return $inventory;
    }

    private function inventory(): ?InventoryCatalogCopyCapabilityInterface
    {
        if ($this->inventoryResolved) {
            return $this->resolvedInventory;
        }
        $this->inventoryResolved = true;
        if (!class_exists(InventoryCatalogCopyCapability::class)) {
            return null;
        }
        try {
            $resolved = ObjectManager::getInstance(InventoryCatalogCopyCapability::class);
            $this->resolvedInventory = $resolved instanceof InventoryCatalogCopyCapabilityInterface
                ? $resolved
                : null;
        } catch (Throwable) {
            $this->resolvedInventory = null;
        }
        return $this->resolvedInventory;
    }

    /** @return list<array<string, mixed>> */
    private function activeStores(int $websiteId): array
    {
        $rows = [];
        foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
            if ($store->websiteId !== $websiteId
                || !$store->enabled
                || $store->lifecycleStatus !== 'active'
                || $store->tombstonedAt !== null
            ) {
                continue;
            }
            $rows[] = $store->toArray();
        }
        return $rows;
    }

    /** @param list<string> $values */
    private function containsAny(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (str_contains(strtolower($value), $needle)) {
                return true;
            }
        }
        return false;
    }

    private function websiteId(int $websiteId): int
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('product_admin_website_invalid');
        }
        return $websiteId;
    }
}
