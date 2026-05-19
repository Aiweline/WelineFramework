<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Service;

use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use WeShop\Wishlist\Model\Wishlist;
use Weline\Framework\Manager\ObjectManager;

class WishlistService
{
    public function __construct(
        private readonly ?ProductService $productService = null
    ) {
    }

    public function addToWishlist(int $customerId, int $productId): Wishlist
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);

        $existing = $wishlist->clear()
            ->where(Wishlist::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Wishlist::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            return $existing;
        }

        $wishlist->clearData()
            ->setData(Wishlist::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Wishlist::schema_fields_PRODUCT_ID, $productId)
            ->save();

        return $wishlist;
    }

    public function removeFromWishlist(int $wishlistId, int $customerId): bool
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);
        $wishlist->load($wishlistId);

        if (!$wishlist->getId()) {
            return false;
        }

        if ((int) $wishlist->getData(Wishlist::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception((string) __('您无权移除此心愿单条目。'));
        }

        return (bool) $wishlist->delete()->fetch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerWishlist(int $customerId, int $limit = 0): array
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);

        $query = $wishlist->clear()
            ->where(Wishlist::schema_fields_CUSTOMER_ID, $customerId)
            ->order(Wishlist::schema_fields_CREATED_AT, 'DESC');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $items = $query->select()->fetchArray();
        foreach ($items as &$item) {
            $productId = (int) ($item[Wishlist::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0) {
                $item['product'] = $this->loadProductData($productId);
            }
        }

        return $items;
    }

    public function getCustomerWishlistCount(int $customerId): int
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);

        return count($wishlist->clear()
            ->where(Wishlist::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray());
    }

    public function isInWishlist(int $customerId, int $productId): bool
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);

        $item = $wishlist->clear()
            ->where(Wishlist::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Wishlist::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        return $item && $item->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProductData(int $productId): array
    {
        $product = $this->getProductService()->getProduct($productId);
        if (!$product || !$product->getId()) {
            return [];
        }

        return [
            'product_id' => (int) $product->getId(),
            'name' => (string) ($product->getData(Product::schema_fields_name) ?? ''),
            'image' => (string) ($product->getData(Product::schema_fields_image) ?? ''),
            'price' => (float) ($product->getData(Product::schema_fields_price) ?? 0),
            'sku' => (string) ($product->getData(Product::schema_fields_sku) ?? ''),
        ];
    }

    private function getProductService(): ProductService
    {
        return $this->productService ?? ObjectManager::getInstance(ProductService::class);
    }
}
