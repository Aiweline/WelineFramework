<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_FakeData\Provider;

use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Extends\Module\Weline_FakeData\Provider\CatalogProvider;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\Product\Model\Product\OptionId as ProductOptionId;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;
use Weline\Framework\Manager\ObjectManager;

class ProductProvider implements FakeDataProviderInterface
{
    private const CODE = 'weshop_product';
    private const ENTITY_PRODUCT = 'product';

    public function __construct(
        private readonly Product $product,
        private readonly Category $category,
        private readonly ProductCategory $productCategory,
        private readonly EavEntity $eavEntity,
        private readonly Set $attributeSet,
        private readonly ?ProductOptionId $productOptionId = null,
        private readonly ?EavAttribute $eavAttribute = null,
        private readonly ?Option $eavAttributeOption = null,
        private readonly ?Group $attributeGroup = null,
        private readonly ?Type $attributeType = null,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getModuleName(): string
    {
        return 'WeShop_Product';
    }

    public function getLabel(): string
    {
        return 'WeShop demo products';
    }

    public function getSortOrder(): int
    {
        return 200;
    }

    public function getDependencies(): array
    {
        return ['weshop_catalog'];
    }

    public function describe(): array
    {
        return [
            'entities' => [self::ENTITY_PRODUCT, 'product_category'],
            'count' => count($this->getProducts()),
        ];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $setId = $this->getDefaultProductSetId();
        if ($setId === 0) {
            return $result->addError((string)__('Default product attribute set is missing. Run setup:upgrade first.'));
        }

        $products = $this->applyLimit($this->getProducts(), $context->getLimit());
        foreach ($products as $productData) {
            $productData = $this->withGalleryImages($productData);
            $existingId = $this->getProductIdBySku((string)$productData['sku']);
            $productModel = clone $this->product;
            $productModel->reset()->clearData();
            if ($existingId > 0) {
                $productModel->load($existingId);
            }
            $productModel
                ->setName((string)$productData['name'])
                ->setSku((string)$productData['sku'])
                ->setSpu((string)$productData['spu'])
                ->setData(Product::schema_fields_HANDLE, (string)$productData['handle'])
                ->setShortDescription((string)$productData['short_description'])
                ->setDescription((string)$productData['description'])
                ->setPrice((float)$productData['price'])
                ->setCost((float)$productData['cost'])
                ->setStock((int)$productData['stock'])
                ->setWeight((float)$productData['weight'])
                ->setImage((string)$productData['image'])
                ->setImages((string)$productData['images'])
                ->setStatus(1)
                ->setParentId(0)
                ->setSetId($setId)
                ->setMetaName((string)$productData['name'])
                ->setMetaDescription((string)$productData['short_description'])
                ->setMetaKeywords((string)$productData['meta_keywords'])
                ->save();

            $productId = $this->getProductIdBySku((string)$productData['sku']);
            if ($productId === 0) {
                $result->addError((string)__('Failed to resolve product after save: %{1}', [$productData['sku']]));
                continue;
            }

            $this->syncCategories($productId, $productData);
            $this->saveProductAttributes($productId, $productData, 0);
            $variantResult = $this->syncVariants($productId, $productData, $setId, $context);
            $result->merge($variantResult);
            $context->record(
                self::CODE,
                self::ENTITY_PRODUCT,
                $productId,
                'product:' . (string)$productData['sku'],
                ['sku' => (string)$productData['sku'], 'handle' => (string)$productData['handle']]
            );

            $existingId > 0 ? $result->addUpdated() : $result->addCreated();
        }
        $this->backfillMissingProductImages();
        $this->repairStaleDemoFoodHandleCollisions();
        $this->repairDuplicateSkus();
        $this->cleanupInvalidManagedAttributeOptions();

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $records = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_PRODUCT);

        foreach ($records as $record) {
            $productId = (int)($record['entity_id'] ?? 0);
            $stableKey = (string)($record['stable_key'] ?? '');
            if ($productId > 0) {
                $this->productCategory->clear()
                    ->getQuery()
                    ->where(ProductCategory::schema_fields_product_id, $productId)
                    ->delete()
                    ->fetch();
                $this->product->clear()
                    ->getQuery()
                    ->where(Product::schema_fields_ID, $productId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }
            if ($stableKey !== '') {
                $context->getRecordService()->removeRecord(self::CODE, $stableKey);
            }
        }

        return $result;
    }

    private function syncCategories(int $productId, array $handles): void
    {
        $categoryIds = [];
        foreach ($handles['category_ids'] ?? [] as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }
        $handles = $handles['category_handles'] ?? $handles;
        foreach ($handles as $handle) {
            $categoryId = $this->getCategoryIdByHandle((string)$handle);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }
        if ($categoryIds === []) {
            return;
        }
        $this->productCategory->setProductCategories($productId, array_values(array_unique($categoryIds)));
    }

    private function getDefaultProductSetId(): int
    {
        $entity = $this->eavEntity->clear()->loadByCode(Product::entity_code);
        $entityId = (int)$entity->getId();
        if ($entityId === 0) {
            return 0;
        }
        $row = $this->attributeSet->clear()
            ->where(Set::schema_fields_code, 'default')
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->find()
            ->fetch();
        return (int)($row[Set::schema_fields_ID] ?? $row['set_id'] ?? 0);
    }

    private function getProductIdBySku(string $sku): int
    {
        if ($sku === '') {
            return 0;
        }
        $row = $this->product->clear()
            ->where(Product::schema_fields_sku, $sku)
            ->find()
            ->fetch();
        return (int)($row[Product::schema_fields_ID] ?? 0);
    }

    private function getCategoryIdByHandle(string $handle): int
    {
        if ($handle === '') {
            return 0;
        }
        $row = $this->category->clear()
            ->where(Category::schema_fields_HANDLE, $handle)
            ->find()
            ->fetch();
        return (int)($row[Category::schema_fields_ID] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProducts(): array
    {
        $products = $this->getBaseProducts();
        return array_merge($products, $this->getCategoryCoverageProducts($products));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBaseProducts(): array
    {
        return array_merge(
            $this->getElectronicsProducts(),
            [
            [
                'sku' => 'FAKE-SMART-BAND-001',
                'spu' => 'PULSE-FIT-BAND',
                'handle' => 'pulse-fit-band',
                'name' => 'Pulse Fit Band',
                'short_description' => 'AMOLED fitness band with heart-rate tracking and 10-day battery life.',
                'description' => 'Lightweight fitness band with workout modes, sleep tracking, notification alerts, and water resistance for daily use.',
                'price' => 129.00,
                'cost' => 59.00,
                'stock' => 120,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1575311373937-040b8e1fd5b6?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'fitness band,wearable,smart device',
                'category_handles' => ['fake-electronics', 'fake-smart-devices', 'electronics'],
            ],
            [
                'sku' => 'FAKE-NOISE-BUDS-001',
                'spu' => 'AERO-NOISE-BUDS',
                'handle' => 'aero-noise-buds',
                'name' => 'Aero Noise-Cancelling Earbuds',
                'short_description' => 'Wireless earbuds with active noise cancellation and compact charging case.',
                'description' => 'Bluetooth earbuds tuned for commuting, calls, and everyday listening, with touch controls and a pocket-size case.',
                'price' => 79.00,
                'cost' => 31.00,
                'stock' => 150,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1606220945770-b5b6c2c55bf1?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'wireless earbuds,noise cancelling,bluetooth',
                'category_handles' => ['fake-electronics', 'fake-smart-devices', 'electronics'],
            ],
            [
                'sku' => 'FAKE-DESK-SPEAKER-001',
                'spu' => 'STUDIO-DESK-SPEAKER',
                'handle' => 'studio-desk-speaker',
                'name' => 'Studio Desk Bluetooth Speaker',
                'short_description' => 'Compact desktop speaker with warm stereo sound and USB-C charging.',
                'description' => 'A small-format speaker for desks, bedrooms, and shelf displays, with clean controls and room-filling audio.',
                'price' => 149.00,
                'cost' => 64.00,
                'stock' => 75,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'bluetooth speaker,desktop audio',
                'category_handles' => ['fake-electronics', 'electronics'],
            ],
            [
                'sku' => 'FAKE-WIRELESS-LAMP-001',
                'spu' => 'AURA-WIRELESS-LAMP',
                'handle' => 'aura-wireless-lamp',
                'name' => 'Aura Wireless Table Lamp',
                'short_description' => 'Rechargeable table lamp with dimmable warm light.',
                'description' => 'Cord-free table lamp for bedside tables, reading corners, and dining shelves, with touch dimming and soft ambient light.',
                'price' => 89.00,
                'cost' => 35.00,
                'stock' => 80,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'wireless lamp,home lighting,table lamp',
                'category_handles' => ['fake-home'],
            ],
            [
                'sku' => 'FAKE-CLOUD-SOFA-001',
                'spu' => 'CLOUD-LINEN-SOFA',
                'handle' => 'cloud-linen-sofa',
                'name' => 'Cloud Linen Three-Seat Sofa',
                'short_description' => 'Soft linen sofa with deep seats and removable cushion covers.',
                'description' => 'Three-seat living room sofa with a low profile, relaxed cushions, and a neutral fabric finish for modern homes.',
                'price' => 599.00,
                'cost' => 320.00,
                'stock' => 24,
                'weight' => 18,
                'image' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'linen sofa,living room,furniture',
                'category_handles' => ['fake-home', 'fake-living-space'],
            ],
            [
                'sku' => 'FAKE-OAK-SIDE-TABLE-001',
                'spu' => 'OAK-SIDE-TABLE',
                'handle' => 'oak-side-table',
                'name' => 'Oak Round Side Table',
                'short_description' => 'Compact oak side table for sofas, reading chairs, and bedrooms.',
                'description' => 'Round side table with a natural oak finish, stable base, and practical surface for lamps, books, and coffee.',
                'price' => 129.00,
                'cost' => 58.00,
                'stock' => 45,
                'weight' => 6,
                'image' => 'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'oak side table,living room table',
                'category_handles' => ['fake-home', 'fake-living-space'],
            ],
            [
                'sku' => 'FAKE-DAILY-HOODIE-001',
                'spu' => 'DAILY-FLEECE-HOODIE',
                'handle' => 'daily-fleece-hoodie',
                'name' => 'Daily Fleece Hoodie',
                'short_description' => 'Midweight cotton-blend hoodie with a relaxed everyday fit.',
                'description' => 'Soft fleece hoodie with ribbed cuffs, kangaroo pocket, and durable stitching for daily casual wear.',
                'price' => 199.00,
                'cost' => 82.00,
                'stock' => 60,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'hoodie,fleece,daily wear',
                'category_handles' => ['fake-apparel', 'fake-daily-wear'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'uniqlo', 'material' => 'cotton_blend'],
                'variants' => [
                    ['sku' => 'FAKE-DAILY-HOODIE-NAVY-M', 'handle' => 'daily-fleece-hoodie-navy-m', 'name' => 'Daily Fleece Hoodie Navy M', 'price' => 199.00, 'cost' => 82.00, 'stock' => 18, 'color' => 'navy', 'size' => 'm'],
                    ['sku' => 'FAKE-DAILY-HOODIE-NAVY-L', 'handle' => 'daily-fleece-hoodie-navy-l', 'name' => 'Daily Fleece Hoodie Navy L', 'price' => 199.00, 'cost' => 82.00, 'stock' => 22, 'color' => 'navy', 'size' => 'l'],
                    ['sku' => 'FAKE-DAILY-HOODIE-GRAY-M', 'handle' => 'daily-fleece-hoodie-gray-m', 'name' => 'Daily Fleece Hoodie Gray M', 'price' => 199.00, 'cost' => 82.00, 'stock' => 20, 'color' => 'gray', 'size' => 'm'],
                    ['sku' => 'FAKE-DAILY-HOODIE-BLK-XL', 'handle' => 'daily-fleece-hoodie-black-xl', 'name' => 'Daily Fleece Hoodie Black XL', 'price' => 209.00, 'cost' => 88.00, 'stock' => 15, 'color' => 'black', 'size' => 'xl'],
                ],
            ],
            [
                'sku' => 'FAKE-CITY-TEE-001',
                'spu' => 'CITY-COTTON-TEE',
                'handle' => 'city-cotton-tee',
                'name' => 'City Cotton T-Shirt',
                'short_description' => 'Breathable cotton T-shirt with a clean regular fit.',
                'description' => 'Everyday cotton tee designed for layering or wearing on its own, with a soft hand feel and stable collar.',
                'price' => 69.00,
                'cost' => 24.00,
                'stock' => 200,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'cotton t-shirt,casual wear',
                'category_handles' => ['fake-apparel', 'fake-daily-wear'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'uniqlo', 'material' => 'cotton'],
                'variants' => [
                    ['sku' => 'FAKE-CITY-TEE-WHT-S', 'handle' => 'city-cotton-tee-white-s', 'name' => 'City Cotton T-Shirt White S', 'price' => 69.00, 'cost' => 24.00, 'stock' => 36, 'color' => 'white', 'size' => 's'],
                    ['sku' => 'FAKE-CITY-TEE-WHT-M', 'handle' => 'city-cotton-tee-white-m', 'name' => 'City Cotton T-Shirt White M', 'price' => 69.00, 'cost' => 24.00, 'stock' => 42, 'color' => 'white', 'size' => 'm'],
                    ['sku' => 'FAKE-CITY-TEE-BLK-M', 'handle' => 'city-cotton-tee-black-m', 'name' => 'City Cotton T-Shirt Black M', 'price' => 69.00, 'cost' => 24.00, 'stock' => 38, 'color' => 'black', 'size' => 'm'],
                    ['sku' => 'FAKE-CITY-TEE-BLUE-L', 'handle' => 'city-cotton-tee-blue-l', 'name' => 'City Cotton T-Shirt Blue L', 'price' => 69.00, 'cost' => 24.00, 'stock' => 31, 'color' => 'blue', 'size' => 'l'],
                ],
            ],
            [
                'sku' => 'FAKE-STREET-SNEAKERS-001',
                'spu' => 'STREET-KNIT-SNEAKERS',
                'handle' => 'street-knit-sneakers',
                'name' => 'Street Knit Sneakers',
                'short_description' => 'Lightweight knit sneakers with cushioned midsoles.',
                'description' => 'Breathable lace-up sneakers for city walking and casual outfits, with flexible knit uppers and grippy soles.',
                'price' => 159.00,
                'cost' => 66.00,
                'stock' => 90,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'knit sneakers,casual shoes',
                'category_handles' => ['fake-apparel'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'adidas', 'material' => 'knit'],
                'variants' => [
                    ['sku' => 'FAKE-STREET-SNEAKERS-BLK-41', 'handle' => 'street-knit-sneakers-black-41', 'name' => 'Street Knit Sneakers Black EU 41', 'price' => 159.00, 'cost' => 66.00, 'stock' => 12, 'color' => 'black', 'size' => '41'],
                    ['sku' => 'FAKE-STREET-SNEAKERS-BLK-42', 'handle' => 'street-knit-sneakers-black-42', 'name' => 'Street Knit Sneakers Black EU 42', 'price' => 159.00, 'cost' => 66.00, 'stock' => 15, 'color' => 'black', 'size' => '42'],
                    ['sku' => 'FAKE-STREET-SNEAKERS-WHT-42', 'handle' => 'street-knit-sneakers-white-42', 'name' => 'Street Knit Sneakers White EU 42', 'price' => 159.00, 'cost' => 66.00, 'stock' => 18, 'color' => 'white', 'size' => '42'],
                    ['sku' => 'FAKE-STREET-SNEAKERS-GREEN-43', 'handle' => 'street-knit-sneakers-green-43', 'name' => 'Street Knit Sneakers Green EU 43', 'price' => 169.00, 'cost' => 72.00, 'stock' => 10, 'color' => 'green', 'size' => '43'],
                ],
            ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getElectronicsProducts(): array
    {
        return [
            [
                'sku' => 'ELEC-IPHONE-15-PRO-MASTER',
                'spu' => 'IPHONE-15-PRO',
                'handle' => 'iphone-15-pro-configurable',
                'name' => 'iPhone 15 Pro',
                'short_description' => 'Configurable flagship phone with multiple finishes and storage options.',
                'description' => 'Demo configurable smartphone with multiple color and storage variants for storefront option selection and pricing tests.',
                'price' => 7999.00,
                'cost' => 6200.00,
                'stock' => 0,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'iphone,smartphone,configurable,storage',
                'category_handles' => ['electronics', 'phones', 'smartphones', 'fake-electronics'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'apple', 'material' => 'titanium'],
                'variants' => [
                    ['sku' => 'ELEC-IPHONE-15-PRO-BLK-256', 'handle' => 'iphone-15-pro-black-256', 'name' => 'iPhone 15 Pro Black 256GB', 'price' => 7999.00, 'cost' => 6200.00, 'stock' => 22, 'color' => 'black', 'size' => '256gb', 'image' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?auto=format&fit=crop&w=900&q=80'],
                    ['sku' => 'ELEC-IPHONE-15-PRO-BLK-512', 'handle' => 'iphone-15-pro-black-512', 'name' => 'iPhone 15 Pro Black 512GB', 'price' => 9499.00, 'cost' => 7200.00, 'stock' => 16, 'color' => 'black', 'size' => '512gb', 'image' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?auto=format&fit=crop&w=900&q=80'],
                    ['sku' => 'ELEC-IPHONE-15-PRO-WHT-256', 'handle' => 'iphone-15-pro-white-256', 'name' => 'iPhone 15 Pro White 256GB', 'price' => 7999.00, 'cost' => 6200.00, 'stock' => 19, 'color' => 'white', 'size' => '256gb', 'image' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            [
                'sku' => 'ELEC-GALAXY-S24-ULTRA-MASTER',
                'spu' => 'GALAXY-S24-ULTRA',
                'handle' => 'galaxy-s24-ultra-configurable',
                'name' => 'Samsung Galaxy S24 Ultra',
                'short_description' => 'Configurable Android flagship with two memory tiers and titanium finishes.',
                'description' => 'Demo configurable Android product used to validate variation cards, stock handling, and high-ticket pricing.',
                'price' => 7299.00,
                'cost' => 5600.00,
                'stock' => 0,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'samsung,smartphone,configurable,android',
                'category_handles' => ['electronics', 'phones', 'smartphones', 'fake-electronics'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'samsung', 'material' => 'titanium'],
                'variants' => [
                    ['sku' => 'ELEC-GALAXY-S24-TITANIUM-256', 'handle' => 'galaxy-s24-ultra-titanium-256', 'name' => 'Samsung Galaxy S24 Ultra Titanium 256GB', 'price' => 7299.00, 'cost' => 5600.00, 'stock' => 14, 'color' => 'silver', 'size' => '256gb', 'image' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?auto=format&fit=crop&w=900&q=80'],
                    ['sku' => 'ELEC-GALAXY-S24-TITANIUM-512', 'handle' => 'galaxy-s24-ultra-titanium-512', 'name' => 'Samsung Galaxy S24 Ultra Titanium 512GB', 'price' => 8299.00, 'cost' => 6300.00, 'stock' => 11, 'color' => 'silver', 'size' => '512gb', 'image' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?auto=format&fit=crop&w=900&q=80'],
                    ['sku' => 'ELEC-GALAXY-S24-GRAY-256', 'handle' => 'galaxy-s24-ultra-gray-256', 'name' => 'Samsung Galaxy S24 Ultra Gray 256GB', 'price' => 7299.00, 'cost' => 5600.00, 'stock' => 13, 'color' => 'gray', 'size' => '256gb', 'image' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            [
                'sku' => 'ELEC-AIRPODS-PRO-MASTER',
                'spu' => 'AIRPODS-PRO',
                'handle' => 'airpods-pro-configurable',
                'name' => 'AirPods Pro',
                'short_description' => 'Configurable earbuds set with USB-C and MagSafe case options.',
                'description' => 'Demo configurable earbuds product with case-bundle differences for accessory variation handling.',
                'price' => 1699.00,
                'cost' => 1200.00,
                'stock' => 0,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'airpods,earbuds,configurable,audio',
                'category_handles' => ['electronics', 'fake-electronics', 'fake-smart-devices'],
                'configurable_attributes' => ['size'],
                'attributes' => ['brand' => 'apple', 'material' => 'plastic', 'color' => 'white'],
                'variants' => [
                    ['sku' => 'ELEC-AIRPODS-PRO-USBC', 'handle' => 'airpods-pro-usbc', 'name' => 'AirPods Pro USB-C Case', 'price' => 1699.00, 'cost' => 1200.00, 'stock' => 31, 'size' => 'usb-c', 'image' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=900&q=80'],
                    ['sku' => 'ELEC-AIRPODS-PRO-MAGSAFE', 'handle' => 'airpods-pro-magsafe', 'name' => 'AirPods Pro MagSafe Case', 'price' => 1799.00, 'cost' => 1280.00, 'stock' => 24, 'size' => 'magsafe', 'image' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            [
                'sku' => 'ELEC-MACBOOK-AIR-15',
                'spu' => 'MACBOOK-AIR-15',
                'handle' => 'macbook-air-15-m3',
                'name' => 'MacBook Air 15-inch M3',
                'short_description' => 'Thin-and-light notebook for creators and business users.',
                'description' => '15-inch notebook with M3 chip, long battery life, and a bright display for work, travel, and remote collaboration.',
                'price' => 10999.00,
                'cost' => 8600.00,
                'stock' => 18,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'macbook,laptop,notebook',
                'category_handles' => ['electronics', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-IPAD-AIR-13',
                'spu' => 'IPAD-AIR-13',
                'handle' => 'ipad-air-13',
                'name' => 'iPad Air 13-inch',
                'short_description' => 'Large-screen tablet for note-taking, design, and streaming.',
                'description' => '13-inch tablet with a lightweight chassis and fast chip, positioned as a do-everything portable screen.',
                'price' => 5299.00,
                'cost' => 3900.00,
                'stock' => 23,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1544244015-0df4b3ffc6b0?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'tablet,ipad,portable screen',
                'category_handles' => ['electronics', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-SONY-XM5',
                'spu' => 'SONY-XM5',
                'handle' => 'sony-wh1000xm5',
                'name' => 'Sony WH-1000XM5',
                'short_description' => 'Premium over-ear noise-cancelling headphones.',
                'description' => 'Wireless headphones tuned for flights, offices, and long listening sessions with strong ANC and call quality.',
                'price' => 2399.00,
                'cost' => 1680.00,
                'stock' => 27,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1618366712010-f4ae9c647dcb?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'sony,headphones,noise cancelling',
                'category_handles' => ['electronics', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-BOSE-ULTRA',
                'spu' => 'BOSE-ULTRA',
                'handle' => 'bose-quietcomfort-ultra',
                'name' => 'Bose QuietComfort Ultra',
                'short_description' => 'Comfort-first wireless headphones with immersive audio.',
                'description' => 'Wireless over-ear model for commuters and hybrid teams, focused on comfort and stable noise reduction.',
                'price' => 2599.00,
                'cost' => 1810.00,
                'stock' => 15,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'bose,wireless headphones,audio',
                'category_handles' => ['electronics', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-LOGI-MX-MASTER',
                'spu' => 'LOGI-MX-MASTER',
                'handle' => 'logitech-mx-master-3s',
                'name' => 'Logitech MX Master 3S',
                'short_description' => 'Productivity mouse for multi-device desktop workflows.',
                'description' => 'Ergonomic wireless mouse with quiet clicks, gesture support, and high-precision tracking.',
                'price' => 699.00,
                'cost' => 420.00,
                'stock' => 46,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'logitech,mouse,office gear',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-KEYBOARD-MECH-84',
                'spu' => 'KEYBOARD-MECH-84',
                'handle' => 'mechanical-keyboard-84',
                'name' => 'Mechanical Keyboard 84-Key',
                'short_description' => 'Compact mechanical keyboard for coding and content work.',
                'description' => '84-key wireless keyboard with hot-swap switches, multi-host pairing, and long battery life.',
                'price' => 899.00,
                'cost' => 520.00,
                'stock' => 34,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1511467687858-23d96c32e4ae?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'mechanical keyboard,desk setup,input device',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-4K-MONITOR-27',
                'spu' => '4K-MONITOR-27',
                'handle' => 'creative-4k-monitor-27',
                'name' => 'Creative 4K Monitor 27-inch',
                'short_description' => '27-inch 4K monitor for design, editing, and office setups.',
                'description' => 'Color-accurate 4K desktop monitor with USB-C input and adjustable stand for modern workstations.',
                'price' => 2899.00,
                'cost' => 2050.00,
                'stock' => 12,
                'weight' => 5,
                'image' => 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'monitor,4k,display',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-CANON-VLOG',
                'spu' => 'CANON-VLOG',
                'handle' => 'canon-vlog-camera',
                'name' => 'Canon Vlog Camera Kit',
                'short_description' => 'Compact camera bundle for creators and livestreamers.',
                'description' => 'Mirrorless creator kit with microphone, battery pack, and fast autofocus for short-form content.',
                'price' => 4599.00,
                'cost' => 3400.00,
                'stock' => 9,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'camera,creator,vlog',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-ROUTER-MESH-2PK',
                'spu' => 'ROUTER-MESH-2PK',
                'handle' => 'mesh-router-2-pack',
                'name' => 'Wi-Fi 6 Mesh Router 2-Pack',
                'short_description' => 'Whole-home Wi-Fi kit for apartments and duplexes.',
                'description' => 'Dual-unit mesh networking system for stable streaming, work calls, and smart-home coverage.',
                'price' => 1399.00,
                'cost' => 910.00,
                'stock' => 21,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1647427060118-4911c9821b82?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'router,mesh,wifi 6',
                'category_handles' => ['electronics', 'fake-smart-devices'],
            ],
            [
                'sku' => 'ELEC-SMARTHOME-HUB',
                'spu' => 'SMARTHOME-HUB',
                'handle' => 'smarthome-hub-pro',
                'name' => 'SmartHome Hub Pro',
                'short_description' => 'Voice-ready smart-home bridge for sensors and lighting.',
                'description' => 'Central hub for smart scenes, connected plugs, door sensors, and lighting automation.',
                'price' => 599.00,
                'cost' => 350.00,
                'stock' => 26,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'smart home,hub,automation',
                'category_handles' => ['electronics', 'fake-smart-devices', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-GAMING-HANDHELD',
                'spu' => 'GAMING-HANDHELD',
                'handle' => 'gaming-handheld-console',
                'name' => 'Gaming Handheld Console',
                'short_description' => 'Portable gaming system with 7-inch screen and SSD storage.',
                'description' => 'Portable gaming hardware for indie and AAA cloud-friendly sessions at home or on trips.',
                'price' => 3299.00,
                'cost' => 2550.00,
                'stock' => 10,
                'weight' => 2,
                'image' => 'https://images.unsplash.com/photo-1606144042614-b2417e99c4e3?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'gaming handheld,console,portable',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-POWERBANK-20K',
                'spu' => 'POWERBANK-20K',
                'handle' => 'powerbank-20000mah',
                'name' => 'Power Bank 20000mAh',
                'short_description' => 'Fast-charging battery pack for phones, tablets, and travel bags.',
                'description' => 'Large-capacity USB-C power bank with airline-friendly sizing and dual-device charging.',
                'price' => 299.00,
                'cost' => 155.00,
                'stock' => 64,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1585336261022-680e295ce3fe?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'power bank,portable charger,usb-c',
                'category_handles' => ['electronics', 'phones', 'fake-electronics'],
            ],
            [
                'sku' => 'ELEC-USB-C-DOCK',
                'spu' => 'USB-C-DOCK',
                'handle' => 'usb-c-dock-12-in-1',
                'name' => 'USB-C Dock 12-in-1',
                'short_description' => 'Desk dock for laptop charging, displays, and ethernet.',
                'description' => 'Single-cable desktop dock with HDMI, DisplayPort, ethernet, and multiple USB ports.',
                'price' => 799.00,
                'cost' => 470.00,
                'stock' => 29,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1587202372775-e229f172b9d7?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'usb-c dock,laptop accessory,office',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-EBOOK-KINDLE',
                'spu' => 'EBOOK-KINDLE',
                'handle' => 'ebook-reader-paper-display',
                'name' => 'E-Ink Reader Paper Display',
                'short_description' => 'Distraction-free e-reader with adjustable warm light.',
                'description' => 'Portable e-ink reading device for books, manuals, and offline documentation.',
                'price' => 1199.00,
                'cost' => 790.00,
                'stock' => 17,
                'weight' => 1,
                'image' => 'https://images.unsplash.com/photo-1512820790803-83ca734da794?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'e-reader,ebook,digital reading',
                'category_handles' => ['electronics'],
            ],
            [
                'sku' => 'ELEC-WATCHFACE-BUNDLE',
                'spu' => 'WATCHFACE-BUNDLE',
                'handle' => 'smartwatch-watchface-bundle',
                'name' => 'Smartwatch Watch Face Bundle',
                'short_description' => 'Downloadable design pack with 50 premium watch faces.',
                'description' => 'Digital downloadable asset pack intended as a virtual product demo for wearable customization storefronts.',
                'price' => 49.00,
                'cost' => 5.00,
                'stock' => 9999,
                'weight' => 0,
                'image' => 'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'downloadable,digital asset,watch face bundle',
                'category_handles' => ['electronics', 'fake-smart-devices'],
                'attributes' => ['delivery_type' => 'download', 'download_format' => 'zip', 'license_term' => 'lifetime', 'brand' => 'apple'],
            ],
            [
                'sku' => 'ELEC-SAMPLE-PRESET-PACK',
                'spu' => 'SAMPLE-PRESET-PACK',
                'handle' => 'creator-audio-preset-pack',
                'name' => 'Creator Audio Preset Pack',
                'short_description' => 'Virtual downloadable preset pack for streamers and podcasters.',
                'description' => 'Downloadable audio preset bundle used as a demo digital product with zero shipping requirements.',
                'price' => 79.00,
                'cost' => 8.00,
                'stock' => 9999,
                'weight' => 0,
                'image' => 'https://images.unsplash.com/photo-1516280440614-37939bbacd81?auto=format&fit=crop&w=900&q=80',
                'images' => '[]',
                'meta_keywords' => 'virtual product,digital download,audio preset',
                'category_handles' => ['electronics'],
                'attributes' => ['delivery_type' => 'download', 'download_format' => 'zip', 'license_term' => 'commercial'],
            ],
        ];
    }

    private function syncVariants(int $parentId, array $productData, int $setId, FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $variants = $productData['variants'] ?? [];
        if (!is_array($variants) || $variants === []) {
            return $result;
        }

        $this->product->clear()
            ->getQuery()
            ->where(Product::schema_fields_parent_id, $parentId)
            ->delete()
            ->fetch();

        foreach ($variants as $variant) {
            $variant = $this->withGalleryImages($this->mergeVariantProductData($productData, $variant));
            $variantModel = clone $this->product;
            $variantModel->reset()->clearData();
            $variantModel
                ->setName((string)($variant['name'] ?? $productData['name']))
                ->setSku((string)$variant['sku'])
                ->setSpu((string)($productData['spu'] ?? $variant['sku']))
                ->setData(Product::schema_fields_HANDLE, (string)($variant['handle'] ?? strtolower((string)$variant['sku'])))
                ->setShortDescription((string)($variant['short_description'] ?? $productData['short_description']))
                ->setDescription((string)($variant['description'] ?? $productData['description']))
                ->setPrice((float)($variant['price'] ?? $productData['price']))
                ->setCost((float)($variant['cost'] ?? $productData['cost']))
                ->setStock((int)($variant['stock'] ?? 0))
                ->setWeight((float)($variant['weight'] ?? $productData['weight']))
                ->setImage((string)($variant['image'] ?? $productData['image']))
                ->setImages((string)($variant['images'] ?? $productData['images']))
                ->setStatus(1)
                ->setParentId($parentId)
                ->setSetId($setId)
                ->setMetaName((string)($variant['name'] ?? $productData['name']))
                ->setMetaDescription((string)($variant['short_description'] ?? $productData['short_description']))
                ->setMetaKeywords((string)($productData['meta_keywords'] ?? ''))
                ->save();

            $variantId = $this->getProductIdBySku((string)$variant['sku']);
            if ($variantId <= 0) {
                $result->addError((string)__('Failed to resolve variant after save: %{1}', [$variant['sku'] ?? '']));
                continue;
            }

            $this->syncCategories($variantId, $productData);
            $this->saveProductAttributes(
                $variantId,
                $variant,
                $parentId,
                $productData['configurable_attributes'] ?? []
            );

            $context->record(
                self::CODE,
                self::ENTITY_PRODUCT,
                $variantId,
                'product:' . (string)$variant['sku'],
                ['sku' => (string)$variant['sku'], 'parent_id' => $parentId]
            );
            $result->addCreated();
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $baseProducts
     * @return array<int, array<string, mixed>>
     */
    private function getCategoryCoverageProducts(array $baseProducts): array
    {
        $coveredCategoryIds = [];
        foreach ($baseProducts as $product) {
            foreach ($product['category_handles'] ?? [] as $handle) {
                $categoryId = $this->getCategoryIdByHandle((string)$handle);
                if ($categoryId > 0) {
                    $coveredCategoryIds[$categoryId] = true;
                }
            }
        }

        $categories = $this->category->clear()
            ->fields('main_table.' . Category::schema_fields_ID . ',main_table.' . Category::schema_fields_HANDLE . ',main_table.' . Category::schema_fields_NAME)
            ->order('main_table.' . Category::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $products = [];
        foreach ($categories as $category) {
            $categoryId = (int)($category[Category::schema_fields_ID] ?? 0);
            $handle = (string)($category[Category::schema_fields_HANDLE] ?? '');
            $name = trim((string)($category[Category::schema_fields_NAME] ?? $handle));
            if ($categoryId <= 0 || $handle === CatalogProvider::EMPTY_CATEGORY_HANDLE || isset($coveredCategoryIds[$categoryId])) {
                continue;
            }

            $products[] = $this->buildCategoryCoverageProduct($categoryId, $handle, $name !== '' ? $name : $handle);
        }

        return $products;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCategoryCoverageProduct(int $categoryId, string $handle, string $categoryName): array
    {
        $image = $this->resolveCategoryImage($handle);
        $englishName = ucwords(str_replace('-', ' ', $handle));
        $displayName = $this->isMostlyAscii($categoryName) ? $categoryName : $categoryName . '精选款';

        return [
            'sku' => 'DEMO-CAT-' . str_pad((string)$categoryId, 4, '0', STR_PAD_LEFT),
            'spu' => 'DEMO-CAT-' . str_pad((string)$categoryId, 4, '0', STR_PAD_LEFT),
            'handle' => 'demo-category-' . $categoryId . '-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $handle)),
            'name' => $displayName,
            'short_description' => $categoryName . ' category sample product with realistic storefront data.',
            'description' => $displayName . ' is a seeded catalog item for category browsing, filters, product cards, and checkout smoke tests.',
            'price' => $this->resolveCategoryPrice($handle, $categoryId),
            'cost' => $this->resolveCategoryPrice($handle, $categoryId) * 0.45,
            'stock' => 40 + ($categoryId % 90),
            'weight' => 1 + ($categoryId % 8),
            'image' => $image,
            'images' => $this->encodeGalleryImages($this->resolveProductGalleryImages(
                'DEMO-CAT-' . str_pad((string)$categoryId, 4, '0', STR_PAD_LEFT),
                $displayName,
                $image
            )),
            'meta_keywords' => strtolower($englishName) . ',demo product,category sample',
            'category_ids' => [$categoryId],
        ];
    }

    private function resolveCategoryImage(string $handle): string
    {
        $images = [
            'electronics' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=900&q=80',
            'phone' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=80',
            'computer' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=80',
            'audio' => 'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=900&q=80',
            'camera' => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80',
            'clothing' => 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=900&q=80',
            'shirt' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
            'shoe' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
            'bag' => 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?auto=format&fit=crop&w=900&q=80',
            'home' => 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=900&q=80',
            'furniture' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=80',
            'kitchen' => 'https://images.unsplash.com/photo-1556911220-bff31c812dba?auto=format&fit=crop&w=900&q=80',
            'textile' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
            'sports' => 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=900&q=80',
        ];

        $needle = strtolower($handle);
        foreach ($images as $keyword => $image) {
            if (str_contains($needle, $keyword)) {
                return $image;
            }
        }

        return 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=900&q=80';
    }

    private function resolveCategoryPrice(string $handle, int $categoryId): float
    {
        $handle = strtolower($handle);
        $base = match (true) {
            str_contains($handle, 'sofa'),
            str_contains($handle, 'bed'),
            str_contains($handle, 'wardrobe'),
            str_contains($handle, 'laptop'),
            str_contains($handle, 'camera') => 599,
            str_contains($handle, 'phone'),
            str_contains($handle, 'watch'),
            str_contains($handle, 'speaker'),
            str_contains($handle, 'monitor') => 299,
            str_contains($handle, 'shoe'),
            str_contains($handle, 'bag'),
            str_contains($handle, 'jacket'),
            str_contains($handle, 'coat') => 159,
            default => 79,
        };

        return (float)($base + ($categoryId % 7) * 10);
    }

    private function isMostlyAscii(string $value): bool
    {
        return preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    private function backfillMissingProductImages(): void
    {
        $rows = $this->product->clear()
            ->fields(
                'main_table.' . Product::schema_fields_ID
                . ',main_table.' . Product::schema_fields_sku
                . ',main_table.' . Product::schema_fields_name
                . ',main_table.' . Product::schema_fields_image
                . ',main_table.' . Product::schema_fields_images
            )
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $sku = (string)($row[Product::schema_fields_sku] ?? '');
            $name = (string)($row[Product::schema_fields_name] ?? '');
            $image = trim((string)($row[Product::schema_fields_image] ?? ''));
            $currentImages = $this->decodeGalleryImages((string)($row[Product::schema_fields_images] ?? ''));
            if ($image !== '' && count($currentImages) >= 3) {
                continue;
            }
            if ($image === '') {
                $image = $this->resolveProductImage($sku, $name);
            }
            $galleryImages = $this->resolveProductGalleryImages(
                $sku,
                $name,
                $image,
                []
            );
            if (count($galleryImages) < 3) {
                continue;
            }

            $productModel = clone $this->product;
            $productModel->reset()->clearData();
            $productModel
                ->load($productId)
                ->setImage($image)
                ->setImages($this->encodeGalleryImages($galleryImages))
                ->save();
        }
    }

    private function repairStaleDemoFoodHandleCollisions(): void
    {
        $rows = $this->product->clear()
            ->fields(
                'main_table.' . Product::schema_fields_ID
                . ',main_table.' . Product::schema_fields_sku
                . ',main_table.' . Product::schema_fields_HANDLE
            )
            ->where(Product::schema_fields_HANDLE, 'demo-category-126-food')
            ->select()
            ->fetchArray();

        $keepFirstDemoProduct = true;
        foreach ($rows as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            $sku = trim((string)($row[Product::schema_fields_sku] ?? ''));
            if ($productId <= 0 || $sku === '') {
                continue;
            }
            if ($keepFirstDemoProduct && str_starts_with($sku, 'DEMO-CAT-')) {
                $keepFirstDemoProduct = false;
                continue;
            }

            $productModel = clone $this->product;
            $productModel->reset()->clearData();
            $productModel
                ->load($productId)
                ->setData(Product::schema_fields_HANDLE, $this->buildUniqueRepairHandle($sku, $productId))
                ->save();
        }
    }

    private function buildUniqueRepairHandle(string $sku, int $productId): string
    {
        $handle = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $sku));
        $handle = trim($handle, '-');
        if ($handle === '') {
            $handle = 'product';
        }
        return 'repaired-' . $handle . '-' . $productId;
    }

    private function repairDuplicateSkus(): void
    {
        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $statement = $pdo->query(
            'SELECT sku, ARRAY_AGG(product_id ORDER BY product_id DESC) AS product_ids '
            . 'FROM "m_weshop_product" WHERE sku <> \'\' GROUP BY sku HAVING COUNT(*) > 1'
        );
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rawIds = trim((string)($row['product_ids'] ?? ''), '{}');
            $ids = array_values(array_filter(array_map('intval', explode(',', $rawIds))));
            if (count($ids) <= 1) {
                continue;
            }
            array_shift($ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM \"m_weshop_product_category\" WHERE product_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM \"m_weshop_product_option_id\" WHERE product_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM \"m_eav_product_select_option\" WHERE entity_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM \"m_weshop_product\" WHERE product_id IN ({$placeholders})")->execute($ids);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function withGalleryImages(array $productData): array
    {
        $image = (string)($productData['image'] ?? '');
        if ($image === '') {
            $image = $this->resolveProductImage(
                (string)($productData['sku'] ?? ''),
                (string)($productData['name'] ?? '')
            );
        }
        $productData['image'] = $image;
        $productData['images'] = $this->encodeGalleryImages($this->resolveProductGalleryImages(
            (string)($productData['sku'] ?? ''),
            (string)($productData['name'] ?? ''),
            $image,
            [],
            $productData
        ));

        return $productData;
    }

    /**
     * @return array<int, string>
     */
    private function decodeGalleryImages(string $images): array
    {
        $decoded = json_decode($images, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /**
     * @param array<int, string> $images
     */
    private function encodeGalleryImages(array $images): string
    {
        return json_encode(array_values(array_unique($images)), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<int, string> $existingImages
     * @return array<int, string>
     */
    private function resolveProductGalleryImages(string $sku, string $name, string $mainImage, array $existingImages = [], array $productData = []): array
    {
        $gallery = array_values(array_filter(array_merge([$mainImage], $existingImages)));
        foreach ($this->resolveProductGalleryPool($sku, $name, $productData) as $image) {
            $gallery[] = $image;
            if (count(array_unique($gallery)) >= 5) {
                break;
            }
        }

        return array_values(array_unique($gallery));
    }

    /**
     * @return array<int, string>
     */
    private function resolveProductGalleryPool(string $sku, string $name, array $productData = []): array
    {
        $needle = strtolower(
            $sku . ' ' . $name . ' '
            . (string)($productData['gallery_family'] ?? '') . ' '
            . (string)($productData['meta_keywords'] ?? '') . ' '
            . implode(' ', array_map('strval', $productData['category_handles'] ?? []))
        );
        $sets = [
            'phone' => [
                'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1598327105666-5b89351aff97?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1601784551446-20c9e07cdbdb?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1616348436168-de43ad0db179?auto=format&fit=crop&w=900&q=80',
            ],
            'computer' => [
                'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1525547719571-a2d4ac8945e2?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=900&q=80',
            ],
            'office' => [
                'https://images.unsplash.com/photo-1497366811353-6870744d04b2?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1518455027359-f3f8164ba6bd?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1555212697-194d092e3b8f?auto=format&fit=crop&w=900&q=80',
            ],
            'audio' => [
                'https://images.unsplash.com/photo-1606220945770-b5b6c2c55bf1?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=900&q=80',
            ],
            'apparel' => [
                'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=900&q=80',
            ],
            'shoes' => [
                'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1608231387042-66d1773070a5?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1460353581641-37baddab0fa2?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1600185365483-26d7a4cc7519?auto=format&fit=crop&w=900&q=80',
            ],
            'home' => [
                'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
            ],
            'digital' => [
                'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1516280440614-37939bbacd81?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=900&q=80',
            ],
            'generic' => [
                'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1531297484001-80022131f5a1?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1516321497487-e288fb19713f?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=900&q=80',
            ],
        ];

        return match (true) {
            str_contains($needle, 'gallery_family:apparel'),
            str_contains($needle, 'fake-apparel'),
            str_contains($needle, 'daily-wear') => $sets['apparel'],
            str_contains($needle, 'gallery_family:office'),
            str_contains($needle, 'office gear'),
            str_contains($needle, 'desk setup'),
            str_contains($needle, 'office') => $sets['office'],
            str_contains($needle, 'watchface'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'download') => $sets['digital'],
            str_contains($needle, 'shoe'),
            str_contains($needle, 'sneaker') => $sets['shoes'],
            str_contains($needle, 'hoodie'),
            str_contains($needle, 'shirt'),
            str_contains($needle, 'tee'),
            str_contains($needle, 'apparel'),
            str_contains($needle, 'clothing') => $sets['apparel'],
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp'),
            str_contains($needle, 'home') => $sets['home'],
            str_contains($needle, 'airpods'),
            str_contains($needle, 'earbuds'),
            str_contains($needle, 'speaker'),
            str_contains($needle, 'headphone'),
            str_contains($needle, 'audio'),
            str_contains($needle, 'bose'),
            str_contains($needle, 'sony') => $sets['audio'],
            str_contains($needle, 'macbook'),
            str_contains($needle, 'ipad'),
            str_contains($needle, 'monitor'),
            str_contains($needle, 'keyboard'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'computer'),
            str_contains($needle, 'laptop') => $sets['computer'],
            str_contains($needle, 'iphone'),
            str_contains($needle, 'galaxy'),
            str_contains($needle, 'phone') => $sets['phone'],
            default => $sets['generic'],
        };
    }

    private function resolveProductImage(string $sku, string $name): string
    {
        $needle = strtolower($sku . ' ' . $name);
        $images = [
            'iphone' => 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?auto=format&fit=crop&w=900&q=80',
            'samsung' => 'https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?auto=format&fit=crop&w=900&q=80',
            'macbook' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=900&q=80',
            'airpods' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=900&q=80',
            'sony' => 'https://images.unsplash.com/photo-1618366712010-f4ae9c647dcb?auto=format&fit=crop&w=900&q=80',
            'bose' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=900&q=80',
            'logitech' => 'https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?auto=format&fit=crop&w=900&q=80',
            'canon' => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80',
            'apple-watch' => 'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?auto=format&fit=crop&w=900&q=80',
            'nike' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
            'adidas' => 'https://images.unsplash.com/photo-1608231387042-66d1773070a5?auto=format&fit=crop&w=900&q=80',
            'uniqlo' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
            'levis' => 'https://images.unsplash.com/photo-1542272604-787c3835535d?auto=format&fit=crop&w=900&q=80',
            'dri-fit' => 'https://images.unsplash.com/photo-1517466787929-bc90951d0974?auto=format&fit=crop&w=900&q=80',
            'osprey' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80',
            'ikea' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
            'herman' => 'https://images.unsplash.com/photo-1586023492125-27b2c045efd7?auto=format&fit=crop&w=900&q=80',
            'muji' => 'https://images.unsplash.com/photo-1608571423902-eed4a5ad8108?auto=format&fit=crop&w=900&q=80',
            'dyson' => 'https://images.unsplash.com/photo-1558317374-067fb5f30001?auto=format&fit=crop&w=900&q=80',
            'nespresso' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=900&q=80',
            'lindt' => 'https://images.unsplash.com/photo-1549007994-cb92caebd54b?auto=format&fit=crop&w=900&q=80',
            'book' => 'https://images.unsplash.com/photo-1512820790803-83ca734da794?auto=format&fit=crop&w=900&q=80',
            'lego' => 'https://images.unsplash.com/photo-1587654780291-39c9404d746b?auto=format&fit=crop&w=900&q=80',
            'tesla' => 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?auto=format&fit=crop&w=900&q=80',
        ];

        foreach ($images as $keyword => $image) {
            if (str_contains($needle, $keyword)) {
                return $image;
            }
        }

        return $this->resolveCategoryImage($needle);
    }

    private function saveProductAttributes(
        int $productId,
        array $productData,
        int $parentProductId,
        array $configurableAttributes = []
    ): void
    {
        $this->resetProductAttributeData($productId);
        $configurableAttributes = array_flip(array_map('strval', $configurableAttributes));
        foreach ($this->resolveAttributePayload($productData) as $attributeCode => $optionCode) {
            $attributeId = $this->ensureAttribute($attributeCode);
            if ($attributeId <= 0) {
                continue;
            }
            $optionId = $this->ensureAttributeOption($attributeId, $attributeCode, $optionCode);
            if ($optionId <= 0) {
                continue;
            }
            $this->saveAttributeValue($attributeId, $productId, $optionId);
            if ($parentProductId > 0 && isset($configurableAttributes[$attributeCode])) {
                $this->linkVariantOption($parentProductId, $productId, $attributeId, $optionId);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveAttributePayload(array $productData): array
    {
        $payload = [];
        foreach ($productData['attributes'] ?? [] as $attributeCode => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $payload[(string)$attributeCode] = $value;
            }
        }

        foreach (['color', 'size', 'material', 'brand', 'delivery_type', 'download_format', 'license_term'] as $attributeCode) {
            $value = trim((string)($productData[$attributeCode] ?? ''));
            if ($value !== '') {
                $payload[$attributeCode] = $value;
            }
        }
        $payload += $this->resolveDefaultAttributes($productData);

        $sku = strtolower((string)($productData['sku'] ?? ''));
        $name = strtolower((string)($productData['name'] ?? ''));

        if (!isset($payload['color'])) {
            if (str_contains($sku, '-blk-') || str_contains($sku, 'black')) {
                $payload['color'] = 'black';
            } elseif (str_contains($sku, '-wht-') || str_contains($sku, 'white')) {
                $payload['color'] = 'white';
            } elseif (str_contains($sku, '-gray-') || str_contains($sku, 'gray')) {
                $payload['color'] = 'gray';
            } elseif (str_contains($sku, 'titanium')) {
                $payload['color'] = 'silver';
            }
        }

        if (!isset($payload['size'])) {
            if (preg_match('/-(256|512)\b/i', $sku, $matches)) {
                $payload['size'] = $matches[1] . 'gb';
            } elseif (str_contains($sku, 'usbc')) {
                $payload['size'] = 'usb-c';
            } elseif (str_contains($sku, 'magsafe')) {
                $payload['size'] = 'magsafe';
            } elseif (str_contains($sku, '15') && str_contains($name, 'inch')) {
                $payload['size'] = '15-inch';
            } elseif (str_contains($sku, '13') && str_contains($name, 'inch')) {
                $payload['size'] = '13-inch';
            } elseif (str_contains($sku, '27') && str_contains($name, 'monitor')) {
                $payload['size'] = '27-inch';
            } elseif (str_contains($sku, '20k')) {
                $payload['size'] = '20000mah';
            } elseif (str_contains($sku, '2pk')) {
                $payload['size'] = '2-pack';
            }
        }

        if (str_contains($sku, 'watchface') || str_contains($sku, 'preset-pack') || str_contains($name, 'download')) {
            $payload['delivery_type'] = $payload['delivery_type'] ?? 'download';
            $payload['download_format'] = $payload['download_format'] ?? 'zip';
            $payload['license_term'] = $payload['license_term'] ?? (str_contains($sku, 'preset-pack') ? 'commercial' : 'lifetime');
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function resolveDefaultAttributes(array $productData): array
    {
        $needle = strtolower(
            (string)($productData['sku'] ?? '') . ' '
            . (string)($productData['name'] ?? '') . ' '
            . implode(' ', array_map('strval', $productData['category_handles'] ?? []))
        );

        $payload = [];
        $brandMap = [
            'iphone' => 'apple',
            'ipad' => 'apple',
            'macbook' => 'apple',
            'airpods' => 'apple',
            'watchface' => 'apple',
            'galaxy' => 'samsung',
            'sony' => 'sony',
            'bose' => 'bose',
            'logitech' => 'logitech',
            'canon' => 'canon',
            'nike' => 'nike',
            'adidas' => 'adidas',
            'levis' => 'levis',
            'uniqlo' => 'uniqlo',
            'ikea' => 'ikea',
            'muji' => 'muji',
            'dyson' => 'dyson',
            'nespresso' => 'nespresso',
            'osprey' => 'osprey',
            'herman' => 'herman_miller',
            'lindt' => 'lindt',
            'lego' => 'lego',
            'tesla' => 'tesla',
        ];
        foreach ($brandMap as $keyword => $brand) {
            if (str_contains($needle, $keyword)) {
                $payload['brand'] = $brand;
                break;
            }
        }

        $materialMap = [
            'iphone' => 'titanium',
            'galaxy' => 'titanium',
            'macbook' => 'aluminum',
            'ipad' => 'aluminum',
            'airpods' => 'plastic',
            'headphones' => 'plastic',
            'earbuds' => 'plastic',
            'keyboard' => 'plastic',
            'mouse' => 'plastic',
            'hoodie' => 'cotton_blend',
            't-shirt' => 'cotton',
            'tee' => 'cotton',
            'sneakers' => 'knit',
            'sofa' => 'linen',
            'table' => 'oak',
            'lamp' => 'metal',
            'bag' => 'nylon',
        ];
        foreach ($materialMap as $keyword => $material) {
            if (str_contains($needle, $keyword)) {
                $payload['material'] = $material;
                break;
            }
        }

        $weight = (float)($productData['weight'] ?? 0);
        $payload['delivery_type'] = $weight <= 0 ? 'download' : 'physical';

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeVariantProductData(array $productData, array $variant): array
    {
        $merged = $productData;
        unset($merged['variants'], $merged['configurable_attributes']);
        return array_replace($merged, $variant);
    }

    private function ensureAttribute(string $attributeCode): int
    {
        $entityId = $this->getProductEntityId();
        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $statement = $pdo->prepare('SELECT attribute_id FROM "m_eav_attribute" WHERE eav_entity_id = ? AND code = ? LIMIT 1');
        $statement->execute([$entityId, $attributeCode]);
        $attributeId = (int)($statement->fetch(\PDO::FETCH_ASSOC)['attribute_id'] ?? 0);
        if ($attributeId > 0) {
            return $attributeId;
        }

        $definition = $this->getAttributeDefinitions()[$attributeCode] ?? ['name' => ucfirst(str_replace('_', ' ', $attributeCode))];
        $statement = $pdo->prepare(
            'INSERT INTO "m_eav_attribute" '
            . '(code, eav_entity_id, set_id, group_id, name, type_id, basic_is_enable, frontend_is_visible, frontend_is_filterable, frontend_is_searchable, data_is_multiple, data_has_option) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $attributeCode,
            $entityId,
            $this->getDefaultProductSetId(),
            $this->getDefaultAttributeGroupId(),
            (string)$definition['name'],
            $this->getSelectTypeId(),
            1,
            1,
            1,
            1,
            0,
            1,
        ]);

        $statement = $pdo->prepare('SELECT attribute_id FROM "m_eav_attribute" WHERE eav_entity_id = ? AND code = ? LIMIT 1');
        $statement->execute([$entityId, $attributeCode]);
        return (int)($statement->fetch(\PDO::FETCH_ASSOC)['attribute_id'] ?? 0);
    }

    private function resetProductAttributeData(int $productId): void
    {
        $attributeIds = $this->getManagedAttributeIds();
        if ($attributeIds === []) {
            return;
        }

        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $placeholders = implode(',', array_fill(0, count($attributeIds), '?'));
        $pdo->prepare("DELETE FROM \"m_eav_product_select_option\" WHERE entity_id = ? AND attribute_id IN ({$placeholders})")
            ->execute(array_merge([$productId], $attributeIds));
        $pdo->prepare("DELETE FROM \"m_weshop_product_option_id\" WHERE product_id = ? AND attribute_id IN ({$placeholders})")
            ->execute(array_merge([$productId], $attributeIds));
    }

    private function cleanupInvalidManagedAttributeOptions(): void
    {
        $attributeIds = $this->getManagedAttributeIdsByCode();
        if ($attributeIds === []) {
            return;
        }

        $pdo = $this->product->getConnection()->getConnector()->getLink();
        foreach ($attributeIds as $attributeCode => $attributeId) {
            $allowedCodes = array_keys($this->getAttributeDefinitions()[$attributeCode]['options'] ?? []);
            if ($allowedCodes === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($allowedCodes), '?'));
            $invalidOptionIds = [];
            $statement = $pdo->prepare(
                "SELECT option_id FROM \"m_eav_attribute_option\" WHERE attribute_id = ? AND code NOT IN ({$placeholders})"
            );
            $statement->execute(array_merge([$attributeId], $allowedCodes));
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $invalidOptionIds[] = (int)($row['option_id'] ?? 0);
            }
            $invalidOptionIds = array_values(array_filter($invalidOptionIds));
            if ($invalidOptionIds === []) {
                continue;
            }

            $optionPlaceholders = implode(',', array_fill(0, count($invalidOptionIds), '?'));
            $pdo->prepare("DELETE FROM \"m_weshop_product_option_id\" WHERE option_id IN ({$optionPlaceholders})")
                ->execute($invalidOptionIds);
            $pdo->prepare("DELETE FROM \"m_eav_product_select_option\" WHERE attribute_id = ? AND value IN ({$optionPlaceholders})")
                ->execute(array_merge([$attributeId], array_map('strval', $invalidOptionIds)));
            $pdo->prepare("DELETE FROM \"m_eav_attribute_option\" WHERE option_id IN ({$optionPlaceholders})")
                ->execute($invalidOptionIds);
        }
    }

    /**
     * @return int[]
     */
    private function getManagedAttributeIds(): array
    {
        return array_values($this->getManagedAttributeIdsByCode());
    }

    /**
     * @return array<string, int>
     */
    private function getManagedAttributeIdsByCode(): array
    {
        $entityId = $this->getProductEntityId();
        if ($entityId <= 0) {
            return [];
        }

        $rows = ($this->eavAttribute ?? ObjectManager::getInstance(EavAttribute::class))->reset()
            ->fields(EavAttribute::schema_fields_ID . ',' . EavAttribute::schema_fields_code)
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, array_keys($this->getAttributeDefinitions()), 'in')
            ->select()
            ->fetchArray();

        $ids = [];
        foreach ($rows as $row) {
            $attributeId = (int)($row[EavAttribute::schema_fields_ID] ?? 0);
            $code = (string)($row[EavAttribute::schema_fields_code] ?? '');
            if ($attributeId > 0 && $code !== '') {
                $ids[$code] = $attributeId;
            }
        }

        return $ids;
    }

    private function ensureAttributeOption(int $attributeId, string $attributeCode, string $optionCode): int
    {
        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $statement = $pdo->prepare('SELECT option_id FROM "m_eav_attribute_option" WHERE attribute_id = ? AND code = ? LIMIT 1');
        $statement->execute([$attributeId, $optionCode]);
        $optionId = (int)($statement->fetch(\PDO::FETCH_ASSOC)['option_id'] ?? 0);
        if ($optionId > 0) {
            $definition = $this->getAttributeDefinitions()[$attributeCode]['options'][$optionCode] ?? null;
            if (is_array($definition)) {
                $pdo->prepare(
                    'UPDATE "m_eav_attribute_option" SET value = ?, swatch_color = ?, swatch_image = ?, swatch_text = ? WHERE option_id = ?'
                )->execute([
                    (string)$definition['value'],
                    (string)($definition['swatch_color'] ?? ''),
                    (string)($definition['swatch_image'] ?? ''),
                    (string)($definition['swatch_text'] ?? ''),
                    $optionId,
                ]);
            }
            return $optionId;
        }

        $definition = $this->getAttributeDefinitions()[$attributeCode]['options'][$optionCode] ?? ['value' => strtoupper($optionCode)];
        $statement = $pdo->prepare(
            'INSERT INTO "m_eav_attribute_option" (eav_entity_id, attribute_id, code, value, swatch_color, swatch_image, swatch_text) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->getProductEntityId(),
            $attributeId,
            $optionCode,
            (string)$definition['value'],
            (string)($definition['swatch_color'] ?? ''),
            (string)($definition['swatch_image'] ?? ''),
            (string)($definition['swatch_text'] ?? ''),
        ]);

        $statement = $pdo->prepare('SELECT option_id FROM "m_eav_attribute_option" WHERE attribute_id = ? AND code = ? LIMIT 1');
        $statement->execute([$attributeId, $optionCode]);
        return (int)($statement->fetch(\PDO::FETCH_ASSOC)['option_id'] ?? 0);
    }

    private function saveAttributeValue(int $attributeId, int $productId, int $optionId): void
    {
        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $table = 'm_eav_product_select_option';
        $pdo->prepare("DELETE FROM \"{$table}\" WHERE attribute_id=:attribute_id AND entity_id=:entity_id")
            ->execute([':attribute_id' => $attributeId, ':entity_id' => $productId]);
        $pdo->prepare("INSERT INTO \"{$table}\" (attribute_id, entity_id, value) VALUES (:attribute_id,:entity_id,:value)")
            ->execute([':attribute_id' => $attributeId, ':entity_id' => $productId, ':value' => (string)$optionId]);
    }

    private function linkVariantOption(int $parentProductId, int $productId, int $attributeId, int $optionId): void
    {
        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $pdo->prepare(
            'DELETE FROM "m_weshop_product_option_id" WHERE parent_product_id = ? AND product_id = ? AND attribute_id = ? AND option_id = ?'
        )->execute([$parentProductId, $productId, $attributeId, $optionId]);
        $pdo->prepare(
            'INSERT INTO "m_weshop_product_option_id" (parent_product_id, attribute_id, option_id, product_id) VALUES (?, ?, ?, ?)'
        )->execute([$parentProductId, $attributeId, $optionId, $productId]);
    }

    private function getDefaultAttributeGroupId(): int
    {
        $groupModel = $this->attributeGroup ?? ObjectManager::getInstance(Group::class);
        $group = $groupModel->reset()
            ->where(Group::schema_fields_code, 'default')
            ->where(Group::schema_fields_set_id, $this->getDefaultProductSetId())
            ->where(Group::schema_fields_eav_entity_id, $this->getProductEntityId())
            ->find()
            ->fetch();
        return (int)($group->getId() ?? 0);
    }

    private function getSelectTypeId(): int
    {
        $typeModel = $this->attributeType ?? ObjectManager::getInstance(Type::class);
        foreach (['select', 'select_option', 'select_yes_no'] as $typeCode) {
            $type = $typeModel->reset()->where(Type::schema_fields_code, $typeCode)->find()->fetch();
            if ($type->getId()) {
                return (int)$type->getId();
            }
        }
        return 0;
    }

    private function getProductEntityId(): int
    {
        return (int)$this->eavEntity->clear()->loadByCode(Product::entity_code)->getId();
    }

    /**
     * @return array<string, array{name:string, options: array<string, array<string, string>>}>
     */
    private function getAttributeDefinitions(): array
    {
        return [
            'color' => ['name' => 'Color', 'options' => [
                'black' => ['value' => 'Black', 'swatch_color' => '#111111'],
                'white' => ['value' => 'White', 'swatch_color' => '#f5f5f5'],
                'gray' => ['value' => 'Gray', 'swatch_color' => '#7c7c7c'],
                'silver' => ['value' => 'Silver', 'swatch_color' => '#c0c0c0'],
                'blue' => ['value' => 'Blue', 'swatch_color' => '#2563eb'],
                'green' => ['value' => 'Green', 'swatch_color' => '#16a34a'],
                'red' => ['value' => 'Red', 'swatch_color' => '#dc2626'],
                'navy' => ['value' => 'Navy', 'swatch_color' => '#1e3a8a'],
                'beige' => ['value' => 'Beige', 'swatch_color' => '#d6c6a8'],
                'natural' => ['value' => 'Natural', 'swatch_color' => '#b08d57'],
            ]],
            'size' => ['name' => 'Size', 'options' => [
                'xs' => ['value' => 'XS'],
                's' => ['value' => 'S'],
                'm' => ['value' => 'M'],
                'l' => ['value' => 'L'],
                'xl' => ['value' => 'XL'],
                'xxl' => ['value' => 'XXL'],
                '256gb' => ['value' => '256GB'],
                '512gb' => ['value' => '512GB'],
                '128gb' => ['value' => '128GB'],
                '1tb' => ['value' => '1TB'],
                'usb-c' => ['value' => 'USB-C'],
                'magsafe' => ['value' => 'MagSafe'],
                '13-inch' => ['value' => '13-inch'],
                '15-inch' => ['value' => '15-inch'],
                '27-inch' => ['value' => '27-inch'],
                '20000mah' => ['value' => '20000mAh'],
                '2-pack' => ['value' => '2-Pack'],
                '40' => ['value' => 'EU 40'],
                '41' => ['value' => 'EU 41'],
                '42' => ['value' => 'EU 42'],
                '43' => ['value' => 'EU 43'],
            ]],
            'material' => ['name' => 'Material', 'options' => [
                'titanium' => ['value' => 'Titanium'],
                'aluminum' => ['value' => 'Aluminum'],
                'plastic' => ['value' => 'Plastic'],
                'cotton' => ['value' => 'Cotton'],
                'cotton_blend' => ['value' => 'Cotton Blend'],
                'linen' => ['value' => 'Linen'],
                'oak' => ['value' => 'Oak'],
                'metal' => ['value' => 'Metal'],
                'nylon' => ['value' => 'Nylon'],
                'knit' => ['value' => 'Knit'],
            ]],
            'brand' => ['name' => 'Brand', 'options' => [
                'apple' => ['value' => 'Apple'],
                'samsung' => ['value' => 'Samsung'],
                'sony' => ['value' => 'Sony'],
                'bose' => ['value' => 'Bose'],
                'logitech' => ['value' => 'Logitech'],
                'canon' => ['value' => 'Canon'],
                'nike' => ['value' => 'Nike'],
                'adidas' => ['value' => 'Adidas'],
                'levis' => ['value' => 'Levi\'s'],
                'uniqlo' => ['value' => 'Uniqlo'],
                'ikea' => ['value' => 'IKEA'],
                'muji' => ['value' => 'MUJI'],
                'dyson' => ['value' => 'Dyson'],
                'nespresso' => ['value' => 'Nespresso'],
                'osprey' => ['value' => 'Osprey'],
                'herman_miller' => ['value' => 'Herman Miller'],
                'lindt' => ['value' => 'Lindt'],
                'lego' => ['value' => 'LEGO'],
                'tesla' => ['value' => 'Tesla'],
            ]],
            'delivery_type' => ['name' => 'Delivery Type', 'options' => [
                'physical' => ['value' => 'Physical Shipment'],
                'download' => ['value' => 'Download'],
                'virtual' => ['value' => 'Virtual Access'],
            ]],
            'download_format' => ['name' => 'Download Format', 'options' => [
                'zip' => ['value' => 'ZIP'],
                'pdf' => ['value' => 'PDF'],
                'mp3' => ['value' => 'MP3'],
                'wav' => ['value' => 'WAV'],
                'license_key' => ['value' => 'License Key'],
            ]],
            'license_term' => ['name' => 'License Term', 'options' => [
                'lifetime' => ['value' => 'Lifetime'],
                'commercial' => ['value' => 'Commercial Use'],
                'personal' => ['value' => 'Personal Use'],
                'subscription' => ['value' => 'Subscription'],
            ]],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyLimit(array $items, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return $items;
        }
        return array_slice($items, 0, $limit);
    }
}
