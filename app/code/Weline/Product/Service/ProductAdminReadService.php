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
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ProductAdminReadService implements ProductAdminReadInterface
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
        ?InventoryCatalogCopyCapabilityInterface $inventory = null,
    ) {
        $this->resolvedInventory = $inventory;
        $this->inventoryResolved = $inventory !== null;
    }

    public function search(int $websiteId, array $filters = []): array
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

        $rows = [];
        foreach ($this->products->listAll($websiteId) as $product) {
            $productId = (int)($product['product_id'] ?? 0);
            if ($productId <= 0 || ($storeId !== null
                && !$this->storeProducts->isSelected($websiteId, $storeId, $productId))
            ) {
                continue;
            }
            $uuid = trim((string)($product['global_product_uuid'] ?? ''));
            $identity = $uuid === '' ? null : $this->identities->resolveProductByUuid($uuid);
            $offers = $this->offers->listByProductIds($websiteId, [$productId]);
            $offerIds = array_values(array_map(
                static fn(array $offer): int => (int)($offer['offer_id'] ?? 0),
                $offers,
            ));
            $attributes = $this->attributes->listExplicitRows(
                $websiteId,
                'product',
                [$productId],
                [0],
            );
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
            $productCode = $identity?->productCode ?? (string)($product['product_code'] ?? '');
            $productType = $identity?->productType ?? (string)($product['product_type'] ?? 'simple');
            $status = (string)($product['status'] ?? 'draft');
            $ownerWebsiteId = $identity?->ownerWebsiteId
                ?? (int)($product['owner_website_id'] ?? $websiteId);

            if (($nameFilter !== '' && !str_contains(strtolower($name), $nameFilter))
                || ($skuFilter !== '' && !$this->containsAny($skus, $skuFilter))
                || ($codeFilter !== '' && !str_contains(strtolower($productCode), $codeFilter))
                || ($typeFilter !== '' && strtolower($productType) !== $typeFilter)
                || ($statusFilter !== '' && strtolower($status) !== $statusFilter)
                || ($ownerFilter !== null && $ownerWebsiteId !== $ownerFilter)
            ) {
                continue;
            }

            $priceRows = $this->prices->listExplicitRows($websiteId, $offerIds, [0]);
            $mediaRows = $this->media->listByProductIds($websiteId, [$productId]);
            $activeStores = $this->activeStores($websiteId);
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
                'main_media' => $mediaRows[0] ?? null,
                'selected_store_ids' => $selectedStores,
                'updated_at' => (string)($product['updated_at'] ?? ''),
                'identity_version' => $identity?->version ?? 0,
                'local_version' => (int)($product['publish_version'] ?? 0),
            ];
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
            static fn(array $left, array $right): int => (string)$left['code']
                <=> (string)$right['code'],
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
            'default_store_ids' => array_values(array_map(
                static fn(array $store): int => (int)$store['store_id'],
                $stores,
            )),
        ];
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

        $mediaRows = array_map(
            fn(array $row): array => $this->adminMediaRow($row),
            $this->media->listByProductIds($websiteId, [$productId], $scopeStoreIds),
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
                && is_array($attribute['value'] ?? null)
            ) {
                $typeConfiguration = $attribute['value'];
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
            $identity->productType,
            $identity->productCode,
            $offers,
            $prices,
            $typeConfiguration,
            $currency,
            $isOwner,
        );
        $inventory = $this->inventorySnapshot(
            $websiteId,
            $storeRows,
            $offers,
            !empty($providerDefinition['capabilities']['inventory']),
        );

        return [
            'website_id' => $websiteId,
            'locale' => trim($locale),
            'currency' => strtoupper(trim($currency)) ?: 'CNY',
            'identity' => $identity->toArray(),
            'product' => $product,
            'offers' => $offers,
            'attributes' => $attributes,
            'attribute_catalog' => $this->attributeMetadata->editorCatalog(),
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
    private function adminMediaRow(array $row): array
    {
        return [
            'media_id' => (int)($row['media_id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'scope_state' => (string)($row['scope_state'] ?? 'explicit'),
            'hidden' => (int)($row['hidden'] ?? 0) === 1,
            'role' => (string)($row['role'] ?? 'gallery'),
            'asset_id' => trim((string)($row['asset_id'] ?? '')),
            'asset_visibility' => (string)($row['asset_visibility'] ?? 'public'),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'access_policy_json' => $row['access_policy_json'] ?? null,
            'position' => (int)($row['position'] ?? 0),
            'legacy' => trim((string)($row['asset_id'] ?? '')) === '',
        ];
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @param list<array<string,mixed>> $prices
     * @param array<string,mixed> $typeConfiguration
     * @return array<string,mixed>
     */
    private function offerMatrix(
        string $productType,
        string $productCode,
        array $offers,
        array $prices,
        array $typeConfiguration,
        string $currency,
        bool $canEditStructure,
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

        $rows = [];
        foreach ($offers as $offer) {
            $configuration = [];
            $rawConfiguration = trim((string)($offer['type_config_json'] ?? ''));
            if ($rawConfiguration !== '') {
                try {
                    $decoded = json_decode($rawConfiguration, true, 512, JSON_THROW_ON_ERROR);
                    $configuration = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $configuration = [];
                }
            }
            $combination = is_array($configuration['combination'] ?? null)
                ? $configuration['combination']
                : [];
            $price = $priceByOfferId[(int)($offer['offer_id'] ?? 0)] ?? null;
            $scopeState = $price === null
                ? 'cleared'
                : (string)($price['scope_state']
                    ?? (!empty($price['cleared']) ? 'cleared' : 'explicit'));
            $row = [
                'offer_id' => (int)($offer['offer_id'] ?? 0),
                'global_offer_uuid' => (string)($offer['global_offer_uuid'] ?? ''),
                'sku' => (string)($offer['sku'] ?? ''),
                'combination' => $combination,
                'combination_key' => (string)($offer['combination_key'] ?? ''),
                'offer_version' => (int)($offer['publish_version'] ?? 0),
                'identity_version' => (int)($offer['identity_version'] ?? 0),
                'status' => (string)($offer['status'] ?? 'draft'),
                'identity_status' => (string)($offer['identity_status'] ?? 'active'),
                'scope_state' => $scopeState,
                'currency' => $currency,
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

        $axes = is_array($typeConfiguration['axes'] ?? null)
            ? array_values($typeConfiguration['axes'])
            : [];
        $skuPrefix = trim((string)($typeConfiguration['sku_prefix'] ?? $productCode));
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
                if ($storeId <= 0 || $offerId <= 0 || !isset($selectedOffers[$offerId])) {
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
