<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2;

use Weline\Cart\Api\CartSelectionHash;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Service\ProductCurrentCustomerResolver;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Resolves Product Cart V2 snapshots from the durable Website shard.
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
        $sku = trim((string)$product->getData(Product::schema_fields_SKU));
        if (strtolower(trim((string)$product->getData(Product::schema_fields_STATUS)))
            !== Product::STATUS_PUBLISHED
        ) {
            return $this->unavailable($identity, $selection, (string)__('商品未发布'), $sku);
        }
        if ($storeId > 0 && !$this->storeOffers->isSelected($websiteId, $storeId, $offerId)) {
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
                image: $this->image($websiteId, $productId),
                currency: $currency,
                unitPriceMinor: max(0, (int)$price->value),
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
            );
        }

        return new CartItemSnapshot(
            offer: $identity,
            name: $name,
            sku: $sku,
            image: $this->image($websiteId, $productId),
            currency: $currency,
            unitPriceMinor: max(0, (int)$price->value),
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
        );
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

    private function image(int $websiteId, int $productId): string
    {
        $rows = $this->media->listByProductIds($websiteId, [$productId]);
        return trim((string)($rows[0][Media::schema_fields_PATH] ?? ''));
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
