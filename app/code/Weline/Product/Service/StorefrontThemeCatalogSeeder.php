<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Seeds published storefront demo products for Theme recommendation widgets.
 */
final class StorefrontThemeCatalogSeeder
{
    /** @var list<array{sku:string,name:string,slug:string,price_minor:int}> */
    private const ITEMS = [
        ['sku' => 'THEME-STORE-001', 'name' => '极简陶瓷马克杯', 'slug' => 'theme-mug', 'price_minor' => 18000],
        ['sku' => 'THEME-STORE-002', 'name' => '无线降噪耳机 Pro', 'slug' => 'theme-headphones', 'price_minor' => 22700],
        ['sku' => 'THEME-STORE-003', 'name' => '轻量缓震跑鞋', 'slug' => 'theme-shoes', 'price_minor' => 32900],
        ['sku' => 'THEME-STORE-004', 'name' => '天然棉质圆领 T 恤', 'slug' => 'theme-tshirt', 'price_minor' => 8900],
        ['sku' => 'THEME-STORE-005', 'name' => '便携蓝牙音箱', 'slug' => 'theme-speaker', 'price_minor' => 15600],
        ['sku' => 'THEME-STORE-006', 'name' => '实木桌面收纳盒', 'slug' => 'theme-box', 'price_minor' => 9800],
        ['sku' => 'THEME-STORE-007', 'name' => '智能温显保温杯', 'slug' => 'theme-cup', 'price_minor' => 11900],
        ['sku' => 'THEME-STORE-008', 'name' => '经典真皮双肩包', 'slug' => 'theme-bag', 'price_minor' => 26800],
    ];

    public function __construct(
        private readonly ProductAdminMutationService $mutations,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly AttributeValueRepository $attributes,
        private readonly PriceRepository $prices,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly StoreCatalogInterface $storeCatalog,
    ) {
    }

    /**
     * @return array{created:int,updated:int,published:int,items:list<array{sku:string,product_id:int,offer_id:int}>}
     */
    public function seed(int $websiteId = 0, string $currency = 'CNY', int $stock = 50): array
    {
        $websiteId = max(0, $websiteId);
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $created = 0;
        $updated = 0;
        $published = 0;
        $items = [];
        $storeIds = $this->resolveStoreIds($websiteId);

        foreach (self::ITEMS as $index => $item) {
            $sku = $item['sku'];
            $requestHash = hash('sha256', 'storefront-theme-catalog:' . $sku);
            $identity = $this->mutations->registerSku($sku, $requestHash, $websiteId);

            $existing = $this->products->findByGlobalUuid(
                $websiteId,
                $identity->globalProductUuid,
            );
            $wasExisting = $existing !== null;

            $product = $this->mutations->createProduct($websiteId, $sku);
            $offer = $this->mutations->createOffer($websiteId, $sku);
            $productId = (int)$product->getId();
            $offerId = (int)$offer->getId();

            if (!$wasExisting) {
                ++$created;
            } else {
                ++$updated;
            }

            $this->attributes->writeExplicit(
                $websiteId,
                0,
                'product',
                $productId,
                'name',
                '',
                $item['name'],
                true,
            );
            $this->attributes->writeExplicit(
                $websiteId,
                0,
                'product',
                $productId,
                'slug',
                '',
                $item['slug'],
                true,
            );
            $this->attributes->writeExplicit(
                $websiteId,
                0,
                'product',
                $productId,
                'product_type',
                '',
                'simple',
                true,
            );
            $this->prices->writeExplicit($websiteId, 0, $offerId, $currency, $item['price_minor']);
            $this->ensureMedia($websiteId, $sku, $productId, $index);

            foreach ($storeIds as $storeId) {
                $this->storeProducts->select($websiteId, $storeId, $productId, true);
                $this->storeOffers->select($websiteId, $storeId, $offerId, true);
                $this->seedInventory($websiteId, $storeId, $offerId, $stock, $sku);
            }

            $productStatus = strtolower(trim((string)$product->getData(Product::schema_fields_STATUS)));
            if ($productStatus !== Product::STATUS_PUBLISHED) {
                $this->products->publish(
                    $websiteId,
                    $productId,
                    (int)$product->getData(Product::schema_fields_PUBLISH_VERSION),
                );
            }
            $offerStatus = strtolower(trim((string)$offer->getData('status')));
            if ($offerStatus !== 'published') {
                $this->offers->publish(
                    $websiteId,
                    $offerId,
                    (int)$offer->getData('publish_version'),
                );
                ++$published;
            }

            $items[] = [
                'sku' => $sku,
                'product_id' => $productId,
                'offer_id' => $offerId,
            ];
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'published' => $published,
            'items' => $items,
        ];
    }

    /** @return list<int> */
    private function resolveStoreIds(int $websiteId): array
    {
        $storeIds = [];
        foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
            if ($store->id > 0) {
                $storeIds[] = $store->id;
            }
        }

        return $storeIds !== [] ? array_values(array_unique($storeIds)) : [1];
    }

    private function ensureMedia(int $websiteId, string $sku, int $productId, int $index): void
    {
        $skuKey = strtolower($sku);
        $relative = 'storefront-theme/' . $skuKey . '.svg';
        $path = '/media/' . $relative;
        $blobKey = 'storefront-theme-' . $skuKey;
        $dir = rtrim((string)PUB, '/\\') . '/media/storefront-theme';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $skuKey . '.svg';
        if (!is_file($file)) {
            $hue = (int)(($index * 47) % 360);
            $label = htmlspecialchars($sku, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640" viewBox="0 0 640 640">'
                . '<rect width="640" height="640" fill="hsl(' . $hue . ' 42% 42%)"/>'
                . '<text x="320" y="330" text-anchor="middle" fill="#fff" font-size="36" font-family="sans-serif">'
                . $label . '</text></svg>';
            @file_put_contents($file, $svg);
        }
        try {
            $this->mutations->createMedia($websiteId, $sku, $path, $blobKey, $index + 1);
        } catch (\Throwable) {
            // Media is optional for widget cards; placeholder covers missing image.
        }
    }


    private function seedInventory(int $websiteId, int $storeId, int $offerId, int $stock, string $sku): void
    {
        if (!class_exists(InventoryService::class)) {
            return;
        }

        try {
            ObjectManager::getInstance(InventoryService::class)->setOnHand(
                $websiteId,
                $storeId,
                $offerId,
                max(0, $stock),
                'theme-seed-' . strtolower($sku),
                hash('sha256', 'theme-seed-' . strtolower($sku)),
            );
        } catch (\Throwable) {
            // Inventory module may be unavailable in some environments.
        }
    }
}
