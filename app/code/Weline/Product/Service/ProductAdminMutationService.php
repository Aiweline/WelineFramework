<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ProductIdentity;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Backend mutation facade. Controllers validate transport input here and all
 * catalog invariants remain owned by the existing identity/repository layers.
 */
final class ProductAdminMutationService
{
    public function __construct(
        private readonly SkuRegistryService $skuRegistry,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly CategoryRepository $categories,
        private readonly MediaRepository $media,
    ) {
    }

    public function registerSku(string $sku, string $requestHash): ProductIdentity
    {
        return $this->skuRegistry->claimLocked(
            $this->skuRegistry->normalizeSku($sku),
            strtolower(trim($requestHash)),
        );
    }

    public function createProduct(int $websiteId, string $sku): Product
    {
        $this->assertWebsiteId($websiteId);
        $identity = $this->requireIdentity($sku);
        $existing = $this->products->findByGlobalUuid($websiteId, $identity->globalProductUuid);
        if ($existing !== null) {
            return $existing;
        }

        $product = $this->products->create($websiteId, [
            Product::schema_fields_SKU => $identity->sku,
            Product::schema_fields_GLOBAL_PRODUCT_UUID => $identity->globalProductUuid,
        ]);
        $this->skuRegistry->incrementRefCount($identity->registryId);

        return $product;
    }

    public function createOffer(int $websiteId, string $sku): Offer
    {
        $this->assertWebsiteId($websiteId);
        $identity = $this->requireIdentity($sku);
        $existing = $this->offers->findByGlobalUuid($websiteId, $identity->globalOfferUuid);
        if ($existing !== null) {
            return $existing;
        }
        $product = $this->products->findByGlobalUuid($websiteId, $identity->globalProductUuid);
        if ($product === null) {
            throw new \InvalidArgumentException(__('请先创建 SKU 对应的商品'));
        }

        $offer = $this->offers->create($websiteId, [
            Offer::schema_fields_PRODUCT_ID => (int)$product->getId(),
            Offer::schema_fields_GLOBAL_OFFER_UUID => $identity->globalOfferUuid,
        ]);
        $this->skuRegistry->incrementRefCount($identity->registryId);

        return $offer;
    }

    public function createCategory(int $websiteId, string $path, int $parentId = 0): Category
    {
        $this->assertWebsiteId($websiteId);
        $path = trim($path);
        if ($path === '' || strlen($path) > 255 || !preg_match('#^/[a-z0-9/_-]+$#i', $path)) {
            throw new \InvalidArgumentException(__('分类 path 必须是以 / 开头的安全路径'));
        }
        if ($parentId < 0) {
            throw new \InvalidArgumentException(__('parent_id 不能为负'));
        }

        return $this->categories->create($websiteId, [
            Category::schema_fields_PARENT_ID => $parentId > 0 ? $parentId : null,
            Category::schema_fields_PATH => $path,
            Category::schema_fields_STATUS => 'active',
        ]);
    }

    public function createMedia(
        int $websiteId,
        string $sku,
        string $path,
        string $blobKey,
        int $position = 0,
    ): Media {
        $this->assertWebsiteId($websiteId);
        $identity = $this->requireIdentity($sku);
        $product = $this->products->findByGlobalUuid($websiteId, $identity->globalProductUuid);
        if ($product === null) {
            throw new \InvalidArgumentException(__('请先创建 SKU 对应的商品'));
        }
        $path = trim($path);
        $blobKey = trim($blobKey);
        if ($path === '' || strlen($path) > 255) {
            throw new \InvalidArgumentException(__('媒体 path 不能为空且最多 255 字符'));
        }
        if ($blobKey === '' || strlen($blobKey) > 255 || !preg_match('/^[a-z0-9._:-]+$/i', $blobKey)) {
            throw new \InvalidArgumentException(__('blob_key 仅允许字母、数字、点、下划线、冒号和横线'));
        }
        if ($position < 0) {
            throw new \InvalidArgumentException(__('position 不能为负'));
        }

        return $this->media->create($websiteId, [
            Media::schema_fields_PRODUCT_ID => (int)$product->getId(),
            Media::schema_fields_PATH => $path,
            Media::schema_fields_BLOB_KEY => $blobKey,
            Media::schema_fields_POSITION => $position,
        ]);
    }

    private function requireIdentity(string $sku): ProductIdentity
    {
        $sku = $this->skuRegistry->normalizeSku($sku);
        $identity = $this->skuRegistry->resolveBySku($sku);
        if ($identity === null) {
            throw new \InvalidArgumentException(__('SKU 尚未注册：%{1}', [$sku]));
        }

        return $identity;
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
    }
}
