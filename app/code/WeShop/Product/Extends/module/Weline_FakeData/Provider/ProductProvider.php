<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_FakeData\Provider;

use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Extends\Module\Weline_FakeData\Provider\CatalogProvider;
use WeShop\Customer\Model\Customer;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\Product\Model\Product\LocalDescription as ProductLocalDescription;
use WeShop\Product\Model\Product\OptionId as ProductOptionId;
use WeShop\Review\Model\Review;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocalDescription;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocalDescription;
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
    private const ENTITY_REVIEW = 'review';

    public function __construct(
        private readonly Product $product,
        private readonly Category $category,
        private readonly ProductCategory $productCategory,
        private readonly EavEntity $eavEntity,
        private readonly Set $attributeSet,
        private readonly ?ProductLocalDescription $productLocalDescription = null,
        private readonly ?ProductOptionId $productOptionId = null,
        private readonly ?EavAttribute $eavAttribute = null,
        private readonly ?Option $eavAttributeOption = null,
        private readonly ?Group $attributeGroup = null,
        private readonly ?Type $attributeType = null,
        private readonly ?Review $review = null,
        private readonly ?Customer $customer = null,
        private readonly ?AttributeLocalDescription $attributeLocalDescription = null,
        private readonly ?OptionLocalDescription $optionLocalDescription = null,
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
        $defaultSetId = $this->getDefaultProductSetId();
        if ($defaultSetId === 0) {
            return $result->addError((string)__('Default product attribute set is missing. Run setup:upgrade first.'));
        }

        $this->cleanupLegacyWesternApparelProducts();
        $products = $this->applyLimit($this->getProducts(), $context->getLimit());
        foreach ($products as $productData) {
            $productData = $this->withGalleryImages($productData);
            $setId = $this->resolveProductAttributeSetId($productData);
            if ($setId <= 0) {
                $setId = $defaultSetId;
            }
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

            $this->syncLocalDescriptions($productId, $productData);
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
        $this->syncManagedAttributeLocalDescriptions();
        $result->merge($this->seedProductReviews($context));

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $records = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_PRODUCT);

        foreach ($records as $record) {
            $productId = (int)($record['entity_id'] ?? 0);
            $entityType = (string)($record['entity_type'] ?? '');
            $stableKey = (string)($record['stable_key'] ?? '');
            if ($entityType === self::ENTITY_REVIEW && $productId > 0) {
                $this->getReviewModel()->clear()
                    ->getQuery()
                    ->where(Review::schema_fields_ID, $productId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            } elseif ($productId > 0) {
                $this->getProductLocalDescriptionModel()->clear()
                    ->getQuery()
                    ->where(ProductLocalDescription::schema_fields_ID, $productId)
                    ->delete()
                    ->fetch();
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

    private function seedProductReviews(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $productIds = $this->resolveRootProductIdsFromRecords($context);
        if ($productIds === []) {
            return $result;
        }

        $customerIds = $this->resolveReviewCustomerIds();
        $existingRecords = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_REVIEW);
        $existingByKey = [];
        foreach ($existingRecords as $record) {
            $stableKey = (string)($record['stable_key'] ?? '');
            if ($stableKey !== '') {
                $existingByKey[$stableKey] = (int)($record['entity_id'] ?? 0);
            }
        }

        $reviewTitles = $this->getSampleReviewTitles();
        $reviewContents = $this->getSampleReviewContents();
        $touchedStableKeys = [];
        foreach ($productIds as $productId) {
            $reviewCount = 2 + ($productId % 5);
            for ($index = 0; $index < $reviewCount; $index++) {
                $stableKey = 'review:product:' . $productId . ':' . $index;
                $touchedStableKeys[$stableKey] = true;
                $existingId = (int)($existingByKey[$stableKey] ?? 0);
                $createdAt = $this->buildReviewTimestamp($productId, $index);

                $review = clone $this->getReviewModel();
                $review->reset()->clearData();
                if ($existingId > 0) {
                    $review->load($existingId);
                }

                $review->setData(Review::schema_fields_PRODUCT_ID, $productId)
                    ->setData(Review::schema_fields_CUSTOMER_ID, $customerIds[($productId + $index) % count($customerIds)])
                    ->setData(Review::schema_fields_RATING, $this->resolveReviewRating($productId, $index))
                    ->setData(Review::schema_fields_TITLE, $reviewTitles[($productId + $index) % count($reviewTitles)])
                    ->setData(Review::schema_fields_CONTENT, $reviewContents[($productId * 2 + $index) % count($reviewContents)])
                    ->setData(Review::schema_fields_STATUS, Review::STATUS_APPROVED)
                    ->setData(Review::schema_fields_CREATED_AT, $createdAt)
                    ->setData(Review::schema_fields_UPDATED_AT, $createdAt)
                    ->save();

                $reviewId = (int)($review->getId() ?? 0);
                if ($reviewId <= 0) {
                    $result->addError((string)__('Failed to save fake review for product %{1}', [$productId]));
                    continue;
                }

                $context->record(
                    self::CODE,
                    self::ENTITY_REVIEW,
                    $reviewId,
                    $stableKey,
                    ['product_id' => $productId, 'index' => $index]
                );
                $existingId > 0 ? $result->addUpdated() : $result->addCreated();
            }
        }

        foreach ($existingByKey as $stableKey => $reviewId) {
            if (isset($touchedStableKeys[$stableKey])) {
                continue;
            }

            if ($reviewId > 0) {
                $this->getReviewModel()->clear()
                    ->getQuery()
                    ->where(Review::schema_fields_ID, $reviewId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }
            $context->getRecordService()->removeRecord(self::CODE, $stableKey);
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
        $products = array_map([$this, 'withProductLocalDescriptions'], $this->getBaseProducts());
        $coverageProducts = array_map(
            [$this, 'withProductLocalDescriptions'],
            $this->getCategoryCoverageProducts($products)
        );

        return array_merge($products, $coverageProducts);
    }

    /**
     * @param array<string, mixed> $productData
     * @return array<string, mixed>
     */
    private function withProductLocalDescriptions(array $productData): array
    {
        $productData['attributes'] = $this->resolveAttributePayload($productData);
        $existingLocalDescriptions = $productData['local_descriptions'] ?? [];
        $sku = (string)($productData['sku'] ?? '');
        $overrides = $this->getProductLocalDescriptionOverrides()[$sku] ?? [];

        $productData['local_descriptions'] = array_replace_recursive(
            $this->buildFallbackProductLocalDescriptions($productData),
            is_array($existingLocalDescriptions) ? $existingLocalDescriptions : [],
            $overrides
        );

        $variants = $productData['variants'] ?? [];
        if (is_array($variants) && $variants !== []) {
            $parentData = $productData;
            unset($parentData['variants'], $parentData['local_descriptions'], $parentData['attributes']);
            $productData['variants'] = array_map(function (array $variant) use ($parentData): array {
                return $this->withProductLocalDescriptions(array_replace($parentData, $variant));
            }, $variants);
        }

        return $productData;
    }

    /**
     * @param array<string, mixed> $productData
     * @return array<string, array<string, string>>
     */
    private function buildFallbackProductLocalDescriptions(array $productData): array
    {
        $fields = [
            'name' => (string)($productData['name'] ?? ''),
            'short_description' => (string)($productData['short_description'] ?? ''),
            'description' => (string)($productData['description'] ?? ''),
            'meta_name' => (string)($productData['name'] ?? ''),
            'meta_description' => (string)($productData['short_description'] ?? ''),
            'meta_keywords' => (string)($productData['meta_keywords'] ?? ''),
        ];

        return [
            'zh_Hans_CN' => $fields,
            'en_US' => $fields,
        ];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function getProductLocalDescriptionOverrides(): array
    {
        return [
            'FAKE-HANFU-RUAO-001' => [
                'en_US' => [
                    'name' => 'Qingyu Cross-Collar Ru\'ao',
                    'short_description' => 'Song-inspired cross-collar hanfu top with a light drape for everyday styling.',
                    'description' => 'A seeded hanfu top inspired by Song-style cross-collar tailoring, relaxed sleeves, tie details, and subtle patterned fabric for pairing with mamian skirts or shawls.',
                    'meta_name' => 'Qingyu Cross-Collar Ru\'ao',
                    'meta_description' => 'Song-inspired cross-collar hanfu top with a light drape for everyday styling.',
                    'meta_keywords' => 'hanfu,ruao,cross-collar top,traditional chinese clothing',
                ],
            ],
            'FAKE-HANFU-MAMIANQUN-001' => [
                'en_US' => [
                    'name' => 'Gold-Woven Mamian Skirt',
                    'short_description' => 'Ming-inspired mamian skirt with a structured hem for pairing with hanfu tops and shawls.',
                    'description' => 'A seeded mamian skirt demo product inspired by traditional gold-woven motifs, designed for category filters, specification display, and hanfu outfit showcases.',
                    'meta_name' => 'Gold-Woven Mamian Skirt',
                    'meta_description' => 'Ming-inspired mamian skirt with a structured hem for pairing with hanfu tops and shawls.',
                    'meta_keywords' => 'hanfu,mamian skirt,traditional chinese skirt',
                ],
            ],
            'FAKE-HANFU-BUXIE-001' => [
                'en_US' => [
                    'name' => 'Cloud-Pattern Embroidered Cloth Shoes',
                    'short_description' => 'Traditional embroidered cloth shoes for lightweight hanfu styling.',
                    'description' => 'Seeded hanfu cloth shoes inspired by traditional cloud embroidery and soft soles, used for shoe specifications, color filters, and outfit pairing demos.',
                    'meta_name' => 'Cloud-Pattern Embroidered Cloth Shoes',
                    'meta_description' => 'Traditional embroidered cloth shoes for lightweight hanfu styling.',
                    'meta_keywords' => 'hanfu,embroidered cloth shoes,traditional footwear',
                ],
            ],
            'FAKE-HANFU-RUAO-GRN-M' => ['en_US' => ['name' => 'Qingyu Cross-Collar Ru\'ao Pine Green M']],
            'FAKE-HANFU-RUAO-WHT-L' => ['en_US' => ['name' => 'Qingyu Cross-Collar Ru\'ao Moon White L']],
            'FAKE-HANFU-RUAO-RED-M' => ['en_US' => ['name' => 'Qingyu Cross-Collar Ru\'ao Crimson M']],
            'FAKE-HANFU-RUAO-BEI-XL' => ['en_US' => ['name' => 'Qingyu Cross-Collar Ru\'ao Beige XL']],
            'FAKE-HANFU-MAMIANQUN-RED-M' => ['en_US' => ['name' => 'Gold-Woven Mamian Skirt Crimson M']],
            'FAKE-HANFU-MAMIANQUN-NAVY-L' => ['en_US' => ['name' => 'Gold-Woven Mamian Skirt Dark Cyan L']],
            'FAKE-HANFU-MAMIANQUN-GRN-S' => ['en_US' => ['name' => 'Gold-Woven Mamian Skirt Bamboo Green S']],
            'FAKE-HANFU-MAMIANQUN-BEI-XL' => ['en_US' => ['name' => 'Gold-Woven Mamian Skirt Beige XL']],
            'FAKE-HANFU-BUXIE-RED-40' => ['en_US' => ['name' => 'Cloud-Pattern Embroidered Cloth Shoes Vermilion 40']],
            'FAKE-HANFU-BUXIE-BEI-41' => ['en_US' => ['name' => 'Cloud-Pattern Embroidered Cloth Shoes Beige 41']],
            'FAKE-HANFU-BUXIE-WHT-42' => ['en_US' => ['name' => 'Cloud-Pattern Embroidered Cloth Shoes Moon White 42']],
            'FAKE-HANFU-BUXIE-GRN-43' => ['en_US' => ['name' => 'Cloud-Pattern Embroidered Cloth Shoes Bamboo Green 43']],
        ];
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
                'sku' => 'FAKE-HANFU-RUAO-001',
                'spu' => 'HANFU-JIAOLING-RUAO',
                'handle' => 'hanfu-jiaoling-ruao',
                'name' => '青玉交领上襦',
                'short_description' => '宋制灵感交领上襦，轻薄垂坠，适合作为汉服日常上装。',
                'description' => '以宋制交领剪裁为灵感的汉服上襦，袖型舒展，搭配系带与暗纹面料，适合与马面裙、褶裙或披帛组合穿着。',
                'price' => 239.00,
                'cost' => 98.00,
                'stock' => 64,
                'weight' => 1,
                'image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
                'images' => '[]',
                'meta_keywords' => 'hanfu,jiaoling ruao,traditional chinese clothing',
                'category_handles' => ['fake-apparel', 'fake-daily-wear'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'hua_chao', 'material' => 'silk'],
                'variants' => [
                    ['sku' => 'FAKE-HANFU-RUAO-GRN-M', 'handle' => 'hanfu-jiaoling-ruao-green-m', 'name' => '青玉交领上襦 松绿 M', 'price' => 239.00, 'cost' => 98.00, 'stock' => 18, 'color' => 'green', 'size' => 'm', 'image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-RUAO-WHT-L', 'handle' => 'hanfu-jiaoling-ruao-white-l', 'name' => '青玉交领上襦 月白 L', 'price' => 239.00, 'cost' => 98.00, 'stock' => 22, 'color' => 'white', 'size' => 'l', 'image' => 'https://images.pexels.com/photos/8152155/pexels-photo-8152155.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-RUAO-RED-M', 'handle' => 'hanfu-jiaoling-ruao-red-m', 'name' => '青玉交领上襦 绛纱 M', 'price' => 249.00, 'cost' => 102.00, 'stock' => 20, 'color' => 'red', 'size' => 'm', 'image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-RUAO-BEI-XL', 'handle' => 'hanfu-jiaoling-ruao-beige-xl', 'name' => '青玉交领上襦 米杏 XL', 'price' => 259.00, 'cost' => 108.00, 'stock' => 15, 'color' => 'beige', 'size' => 'xl', 'image' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=900'],
                ],
            ],
            [
                'sku' => 'FAKE-HANFU-MAMIANQUN-001',
                'spu' => 'HANFU-MAMIANQUN',
                'handle' => 'hanfu-mamianqun',
                'name' => '织金马面裙',
                'short_description' => '明制灵感马面裙，下摆挺括，适合搭配交领上襦与披帛。',
                'description' => '以传统织金纹样为灵感的马面裙示例商品，兼顾分类筛选、规格展示与国风穿搭场景，适合作为汉服下装假数据。',
                'price' => 329.00,
                'cost' => 146.00,
                'stock' => 58,
                'weight' => 1,
                'image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
                'images' => '[]',
                'meta_keywords' => 'hanfu,mamianqun,traditional chinese skirt',
                'category_handles' => ['fake-apparel', 'fake-daily-wear'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'yun_jin_studio', 'material' => 'silk'],
                'variants' => [
                    ['sku' => 'FAKE-HANFU-MAMIANQUN-RED-M', 'handle' => 'hanfu-mamianqun-red-m', 'name' => '织金马面裙 绯红 M', 'price' => 329.00, 'cost' => 146.00, 'stock' => 16, 'color' => 'red', 'size' => 'm', 'image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-MAMIANQUN-NAVY-L', 'handle' => 'hanfu-mamianqun-navy-l', 'name' => '织金马面裙 黛青 L', 'price' => 339.00, 'cost' => 152.00, 'stock' => 18, 'color' => 'navy', 'size' => 'l', 'image' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-MAMIANQUN-GRN-S', 'handle' => 'hanfu-mamianqun-green-s', 'name' => '织金马面裙 竹青 S', 'price' => 329.00, 'cost' => 146.00, 'stock' => 12, 'color' => 'green', 'size' => 's', 'image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-MAMIANQUN-BEI-XL', 'handle' => 'hanfu-mamianqun-beige-xl', 'name' => '织金马面裙 米杏 XL', 'price' => 349.00, 'cost' => 158.00, 'stock' => 12, 'color' => 'beige', 'size' => 'xl', 'image' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=900'],
                ],
            ],
            [
                'sku' => 'FAKE-HANFU-BUXIE-001',
                'spu' => 'HANFU-YUNWEN-BUXIE',
                'handle' => 'hanfu-yunwen-buxie',
                'name' => '云纹绣花布鞋',
                'short_description' => '传统云纹绣花布鞋，轻便柔软，适合作为汉服鞋履搭配。',
                'description' => '以传统绣花云纹与软底鞋型为灵感的汉服布鞋示例商品，用于展示鞋类规格、颜色筛选与国风搭配效果。',
                'price' => 189.00,
                'cost' => 72.00,
                'stock' => 96,
                'weight' => 1,
                'image' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
                'images' => '[]',
                'meta_keywords' => 'hanfu,buxie,embroidered cloth shoes,traditional footwear',
                'category_handles' => ['fake-apparel'],
                'configurable_attributes' => ['color', 'size'],
                'attributes' => ['brand' => 'jin_xiu_ge', 'material' => 'cotton'],
                'variants' => [
                    ['sku' => 'FAKE-HANFU-BUXIE-RED-40', 'handle' => 'hanfu-yunwen-buxie-red-40', 'name' => '云纹绣花布鞋 朱砂 40', 'price' => 189.00, 'cost' => 72.00, 'stock' => 16, 'color' => 'red', 'size' => '40', 'image' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-BUXIE-BEI-41', 'handle' => 'hanfu-yunwen-buxie-beige-41', 'name' => '云纹绣花布鞋 米杏 41', 'price' => 189.00, 'cost' => 72.00, 'stock' => 18, 'color' => 'beige', 'size' => '41', 'image' => 'https://images.pexels.com/photos/35222104/pexels-photo-35222104.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-BUXIE-WHT-42', 'handle' => 'hanfu-yunwen-buxie-white-42', 'name' => '云纹绣花布鞋 月白 42', 'price' => 189.00, 'cost' => 72.00, 'stock' => 14, 'color' => 'white', 'size' => '42', 'image' => 'https://images.pexels.com/photos/12024998/pexels-photo-12024998.jpeg?auto=compress&cs=tinysrgb&w=900'],
                    ['sku' => 'FAKE-HANFU-BUXIE-GRN-43', 'handle' => 'hanfu-yunwen-buxie-green-43', 'name' => '云纹绣花布鞋 竹青 43', 'price' => 199.00, 'cost' => 78.00, 'stock' => 10, 'color' => 'green', 'size' => '43', 'image' => 'https://images.pexels.com/photos/35069508/pexels-photo-35069508.jpeg?auto=compress&cs=tinysrgb&w=900'],
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

            $this->syncLocalDescriptions($variantId, $variant);
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
        $profile = $this->resolveCategoryCoverageProfile($handle, $categoryName);
        $displayName = (string)($profile['name'] ?? ($this->isMostlyAscii($categoryName) ? $categoryName : $categoryName . '精选款'));
        $resolvedImage = (string)($profile['image'] ?? $image);
        $metaKeywords = (string)($profile['meta_keywords'] ?? (strtolower($englishName) . ',demo product,category sample'));

        return array_replace([
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
            'image' => $resolvedImage,
            'images' => $this->encodeGalleryImages($this->resolveProductGalleryImages(
                'DEMO-CAT-' . str_pad((string)$categoryId, 4, '0', STR_PAD_LEFT),
                $displayName,
                $resolvedImage
            )),
            'meta_keywords' => $metaKeywords,
            'category_ids' => [$categoryId],
            'local_descriptions' => $profile['local_descriptions'] ?? $this->buildCategoryCoverageLocalDescriptions(
                $handle,
                $categoryName,
                $displayName,
                $englishName,
                $metaKeywords
            ),
        ], $profile);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCategoryCoverageProfile(string $handle, string $categoryName): array
    {
        $needle = strtolower($handle . ' ' . $categoryName);

        return match (true) {
            str_contains($needle, 'shoe'),
            str_contains($needle, 'sneaker'),
            str_contains($needle, '布鞋') => [
                'name' => '汉服布鞋精选款',
                'short_description' => '国风绣花布鞋示例商品，适配鞋类筛选与汉服搭配展示。',
                'description' => '用于演示鞋类分类浏览、筛选与商品卡片的汉服布鞋假数据，保留传统软底鞋与绣花元素的视觉风格。',
                'image' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => 'hanfu,buxie,embroidered cloth shoes,category sample',
                'attributes' => ['brand' => 'jin_xiu_ge', 'material' => 'cotton'],
                'local_descriptions' => [
                    'zh_Hans_CN' => [
                        'name' => '汉服布鞋精选款',
                        'short_description' => '国风绣花布鞋示例商品，适配鞋类筛选与汉服搭配展示。',
                        'description' => '用于演示鞋类分类浏览、筛选与商品卡片的汉服布鞋假数据，保留传统软底鞋与绣花元素的视觉风格。',
                        'meta_name' => '汉服布鞋精选款',
                        'meta_description' => '国风绣花布鞋示例商品，适配鞋类筛选与汉服搭配展示。',
                        'meta_keywords' => '汉服布鞋,绣花布鞋,类目示例',
                    ],
                    'en_US' => [
                        'name' => 'Hanfu Cloth Shoes Featured Pick',
                        'short_description' => 'Sample embroidered cloth shoes for shoe filters and hanfu outfit showcases.',
                        'description' => 'Seeded hanfu cloth shoes demo product for category browsing, filtering, product cards, and storefront smoke tests.',
                        'meta_name' => 'Hanfu Cloth Shoes Featured Pick',
                        'meta_description' => 'Sample embroidered cloth shoes for shoe filters and hanfu outfit showcases.',
                        'meta_keywords' => 'hanfu cloth shoes,embroidered shoes,category sample',
                    ],
                ],
            ],
            str_contains($needle, 'pant'),
            str_contains($needle, 'skirt'),
            str_contains($needle, '裙') => [
                'name' => '马面裙精选款',
                'short_description' => '汉服下装示例商品，适配下装分类筛选与规格展示。',
                'description' => '用于演示下装分类浏览、筛选与商品卡片的马面裙假数据，突出汉服裙装的传统纹样与层次感。',
                'image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => 'hanfu,mamianqun,traditional chinese skirt,category sample',
                'attributes' => ['brand' => 'yun_jin_studio', 'material' => 'silk'],
                'local_descriptions' => [
                    'zh_Hans_CN' => [
                        'name' => '马面裙精选款',
                        'short_description' => '汉服下装示例商品，适配下装分类筛选与规格展示。',
                        'description' => '用于演示下装分类浏览、筛选与商品卡片的马面裙假数据，突出汉服裙装的传统纹样与层次感。',
                        'meta_name' => '马面裙精选款',
                        'meta_description' => '汉服下装示例商品，适配下装分类筛选与规格展示。',
                        'meta_keywords' => '马面裙,汉服下装,类目示例',
                    ],
                    'en_US' => [
                        'name' => 'Mamian Skirt Featured Pick',
                        'short_description' => 'Sample hanfu skirt product for apparel filters, specifications, and storefront browsing.',
                        'description' => 'Seeded mamian skirt demo product for category browsing, filtering, and product cards with traditional hanfu styling cues.',
                        'meta_name' => 'Mamian Skirt Featured Pick',
                        'meta_description' => 'Sample hanfu skirt product for apparel filters, specifications, and storefront browsing.',
                        'meta_keywords' => 'mamian skirt,hanfu skirt,category sample',
                    ],
                ],
            ],
            str_contains($needle, 'shirt'),
            str_contains($needle, 't-shirt'),
            str_contains($needle, 'top'),
            str_contains($needle, 'clothing'),
            str_contains($needle, '上衣'),
            str_contains($needle, '汉服'),
            str_contains($needle, '服饰') => [
                'name' => '交领上襦精选款',
                'short_description' => '汉服上装示例商品，适配服饰分类筛选与商品卡片展示。',
                'description' => '用于演示服饰分类浏览、筛选与商品卡片的交领上襦假数据，以汉服上装替代普通 T 恤与日常卫衣风格。',
                'image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => 'hanfu,jiaoling ruao,traditional chinese clothing,category sample',
                'attributes' => ['brand' => 'hua_chao', 'material' => 'silk'],
                'local_descriptions' => [
                    'zh_Hans_CN' => [
                        'name' => '交领上襦精选款',
                        'short_description' => '汉服上装示例商品，适配服饰分类筛选与商品卡片展示。',
                        'description' => '用于演示服饰分类浏览、筛选与商品卡片的交领上襦假数据，以汉服上装替代普通 T 恤与日常卫衣风格。',
                        'meta_name' => '交领上襦精选款',
                        'meta_description' => '汉服上装示例商品，适配服饰分类筛选与商品卡片展示。',
                        'meta_keywords' => '交领上襦,汉服上装,类目示例',
                    ],
                    'en_US' => [
                        'name' => 'Cross-Collar Ruao Featured Pick',
                        'short_description' => 'Sample hanfu top product for apparel filters, product cards, and category browsing.',
                        'description' => 'Seeded cross-collar ruao demo product for storefront browsing, filtering, and product-card flows with traditional hanfu styling.',
                        'meta_name' => 'Cross-Collar Ruao Featured Pick',
                        'meta_description' => 'Sample hanfu top product for apparel filters, product cards, and category browsing.',
                        'meta_keywords' => 'cross-collar ruao,hanfu top,category sample',
                    ],
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildCategoryCoverageLocalDescriptions(
        string $handle,
        string $categoryName,
        string $displayName,
        string $englishName,
        string $metaKeywords
    ): array {
        $chineseCategoryName = trim($categoryName) !== '' ? $categoryName : $displayName;
        $englishCategoryName = $this->resolveCategoryCoverageEnglishLabel($handle, $categoryName, $englishName);
        $englishProductName = $englishCategoryName !== '' ? $englishCategoryName . ' Featured Pick' : 'Featured Category Pick';
        $englishShortDescription = $englishCategoryName !== ''
            ? 'Sample ' . strtolower($englishCategoryName) . ' product with realistic storefront demo data.'
            : 'Sample category product with realistic storefront demo data.';
        $englishDescription = $englishProductName . ' is a seeded catalog item for category browsing, filters, product cards, and checkout smoke tests.';

        return [
            'zh_Hans_CN' => [
                'name' => $displayName,
                'short_description' => $chineseCategoryName . '类目示例商品，使用更贴近真实店铺的演示数据。',
                'description' => $displayName . '用于演示分类浏览、筛选、商品卡片与下单流程等店铺前台场景。',
                'meta_name' => $displayName,
                'meta_description' => $chineseCategoryName . '类目示例商品，使用更贴近真实店铺的演示数据。',
                'meta_keywords' => $chineseCategoryName . ',示例商品,分类演示',
            ],
            'en_US' => [
                'name' => $englishProductName,
                'short_description' => $englishShortDescription,
                'description' => $englishDescription,
                'meta_name' => $englishProductName,
                'meta_description' => $englishShortDescription,
                'meta_keywords' => $metaKeywords,
            ],
        ];
    }

    private function resolveCategoryCoverageEnglishLabel(string $handle, string $categoryName, string $fallback): string
    {
        $needle = strtolower($handle . ' ' . $categoryName);

        return match (true) {
            str_contains($needle, 'bag') => 'Handbag',
            str_contains($needle, 'shoe'),
            str_contains($needle, 'sneaker') => 'Shoes',
            str_contains($needle, 'pant'),
            str_contains($needle, 'skirt') => 'Skirt',
            str_contains($needle, 'shirt'),
            str_contains($needle, 't-shirt'),
            str_contains($needle, 'top'),
            str_contains($needle, 'clothing') => 'Apparel',
            str_contains($needle, 'electronics') => 'Electronics',
            str_contains($needle, 'phone') => 'Phone',
            str_contains($needle, 'computer') => 'Computer',
            str_contains($needle, 'watch') => 'Watch',
            str_contains($needle, 'home') => 'Home Living',
            str_contains($needle, 'furniture') => 'Furniture',
            str_contains($needle, 'kitchen') => 'Kitchen',
            default => $this->isMostlyAscii($categoryName) ? $categoryName : $fallback,
        };
    }

    private function resolveCategoryImage(string $handle): string
    {
        $images = [
            'electronics' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=900&q=80',
            'phone' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=80',
            'computer' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=80',
            'audio' => 'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=900&q=80',
            'camera' => 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80',
            'hanfu' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
            'clothing' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
            'shirt' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
            'shoe' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
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

    /**
     * @param array<string, mixed> $productData
     */
    private function syncLocalDescriptions(int $productId, array $productData): void
    {
        $localDescriptions = $this->resolveProductLocalDescriptions($productData);
        if ($productId <= 0 || $localDescriptions === []) {
            return;
        }

        $records = [];
        foreach ($localDescriptions as $localeCode => $fields) {
            $localeCode = trim((string)$localeCode);
            if ($localeCode === '' || !is_array($fields)) {
                continue;
            }

            $name = trim((string)($fields['name'] ?? ''));
            $shortDescription = trim((string)($fields['short_description'] ?? ''));
            $description = trim((string)($fields['description'] ?? ''));
            if ($name === '' && $shortDescription === '' && $description === '') {
                continue;
            }

            $records[] = [
                ProductLocalDescription::schema_fields_ID => $productId,
                ProductLocalDescription::schema_fields_local_code => $localeCode,
                ProductLocalDescription::schema_fields_NAME => $name !== '' ? $name : (string)($productData['name'] ?? ''),
                ProductLocalDescription::schema_fields_SHORT_DESCRIPTION => $shortDescription !== '' ? $shortDescription : (string)($productData['short_description'] ?? ''),
                ProductLocalDescription::schema_fields_DESCRIPTION => $description !== '' ? $description : (string)($productData['description'] ?? ''),
                ProductLocalDescription::schema_fields_META_NAME => trim((string)($fields['meta_name'] ?? '')) !== '' ? (string)$fields['meta_name'] : (string)($fields['name'] ?? $productData['name'] ?? ''),
                ProductLocalDescription::schema_fields_META_DESCRIPTION => trim((string)($fields['meta_description'] ?? '')) !== '' ? (string)$fields['meta_description'] : (string)($fields['short_description'] ?? $productData['short_description'] ?? ''),
                ProductLocalDescription::schema_fields_META_KEYWORDS => trim((string)($fields['meta_keywords'] ?? '')) !== '' ? (string)$fields['meta_keywords'] : (string)($productData['meta_keywords'] ?? ''),
            ];
        }

        if ($records === []) {
            return;
        }

        $localDescriptionModel = $this->getProductLocalDescriptionModel();
        $localDescriptionModel->clear()
            ->getQuery()
            ->where(ProductLocalDescription::schema_fields_ID, $productId)
            ->delete()
            ->fetch();

        $localDescriptionModel->reset()
            ->insert($records)
            ->fetch();
    }

    /**
     * @param array<string, mixed> $productData
     * @return array<string, array<string, mixed>>
     */
    private function resolveProductLocalDescriptions(array $productData): array
    {
        $localDescriptions = $productData['local_descriptions'] ?? [];

        return is_array($localDescriptions) ? $localDescriptions : [];
    }

    private function getProductLocalDescriptionModel(): ProductLocalDescription
    {
        return $this->productLocalDescription ?? ObjectManager::getInstance(ProductLocalDescription::class);
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

    private function cleanupLegacyWesternApparelProducts(): void
    {
        $legacySkus = [
            'FAKE-DAILY-HOODIE-001',
            'FAKE-DAILY-HOODIE-NAVY-M',
            'FAKE-DAILY-HOODIE-NAVY-L',
            'FAKE-DAILY-HOODIE-GRAY-M',
            'FAKE-DAILY-HOODIE-BLK-XL',
            'FAKE-CITY-TEE-001',
            'FAKE-CITY-TEE-WHT-S',
            'FAKE-CITY-TEE-WHT-M',
            'FAKE-CITY-TEE-BLK-M',
            'FAKE-CITY-TEE-BLUE-L',
            'FAKE-STREET-SNEAKERS-001',
            'FAKE-STREET-SNEAKERS-BLK-41',
            'FAKE-STREET-SNEAKERS-BLK-42',
            'FAKE-STREET-SNEAKERS-WHT-42',
            'FAKE-STREET-SNEAKERS-GREEN-43',
        ];

        $productIds = [];
        foreach ($legacySkus as $sku) {
            $productId = $this->getProductIdBySku($sku);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return;
        }

        $pdo = $this->product->getConnection()->getConnector()->getLink();
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $statement = $pdo->prepare("SELECT product_id FROM \"m_weshop_product\" WHERE parent_id IN ({$placeholders})");
        $statement->execute($productIds);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $childId = (int)($row['product_id'] ?? 0);
            if ($childId > 0) {
                $productIds[] = $childId;
            }
        }

        $productIds = array_values(array_unique($productIds));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $pdo->prepare("DELETE FROM \"m_weshop_product_category\" WHERE product_id IN ({$placeholders})")->execute($productIds);
        $pdo->prepare("DELETE FROM \"m_weshop_product_option_id\" WHERE product_id IN ({$placeholders}) OR parent_product_id IN ({$placeholders})")
            ->execute(array_merge($productIds, $productIds));
        $pdo->prepare("DELETE FROM \"m_eav_product_select_option\" WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $pdo->prepare("DELETE FROM \"m_weshop_product\" WHERE product_id IN ({$placeholders})")->execute($productIds);
    }

    /**
     * @return array<int, int>
     */
    private function resolveRootProductIdsFromRecords(FakeDataContext $context): array
    {
        $records = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_PRODUCT);
        $candidateIds = [];
        foreach ($records as $record) {
            $productId = (int)($record['entity_id'] ?? 0);
            if ($productId > 0) {
                $candidateIds[] = $productId;
            }
        }
        $candidateIds = array_values(array_unique($candidateIds));
        if ($candidateIds === []) {
            return [];
        }

        $rows = $this->product->clear()
            ->fields(Product::schema_fields_ID . ',' . Product::schema_fields_parent_id)
            ->where(Product::schema_fields_ID, $candidateIds, 'in')
            ->select()
            ->fetchArray();

        $productIds = [];
        foreach ($rows as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            $parentId = (int)($row[Product::schema_fields_parent_id] ?? 0);
            if ($productId > 0 && $parentId === 0) {
                $productIds[] = $productId;
            }
        }

        sort($productIds);
        return $productIds;
    }

    /**
     * @return array<int, int>
     */
    private function resolveReviewCustomerIds(): array
    {
        $rows = $this->getCustomerModel()->reset()
            ->fields(Customer::schema_fields_ID)
            ->order(Customer::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $customerIds = [];
        foreach ($rows as $row) {
            $customerId = (int)($row[Customer::schema_fields_ID] ?? 0);
            if ($customerId > 0) {
                $customerIds[] = $customerId;
            }
        }

        return $customerIds !== [] ? array_values(array_unique($customerIds)) : [1];
    }

    private function resolveReviewRating(int $productId, int $index): int
    {
        $pattern = [5, 5, 4, 4, 5, 3, 4, 5, 2, 5];
        return $pattern[($productId + $index) % count($pattern)];
    }

    private function buildReviewTimestamp(int $productId, int $index): string
    {
        $daysAgo = (($productId + $index * 11) % 160) + 1;
        $hoursAgo = (($productId + $index * 17) % 23);
        return date('Y-m-d H:i:s', strtotime("-{$daysAgo} days -{$hoursAgo} hours"));
    }

    /**
     * @return array<int, string>
     */
    private function getSampleReviewTitles(): array
    {
        return [
            '做工精致',
            '上身很有气质',
            '配色很雅致',
            '版型非常好',
            '适合日常穿搭',
            '细节超出预期',
            '面料垂感不错',
            '整体很满意',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getSampleReviewContents(): array
    {
        return [
            '实物比图片更有层次，面料轻盈，搭配马面裙和披帛都很顺。',
            '颜色很正，汉服细节处理得不错，日常拍照和活动穿着都合适。',
            '版型端正，袖型自然，整体不会显得臃肿，穿起来很有国风气质。',
            '绣花和纹样比较精致，鞋子或裙摆的做工也比预期稳，值得推荐。',
            '尺码比较合适，穿着舒适，和页面展示的汉服风格基本一致。',
            '适合做入门汉服穿搭，价格和完成度比较平衡，整体体验很好。',
        ];
    }

    private function getReviewModel(): Review
    {
        return $this->review ?? ObjectManager::getInstance(Review::class);
    }

    private function getCustomerModel(): Customer
    {
        return $this->customer ?? ObjectManager::getInstance(Customer::class);
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
                'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=900',
            ],
            'shoes' => [
                'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/12024998/pexels-photo-12024998.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/35222104/pexels-photo-35222104.jpeg?auto=compress&cs=tinysrgb&w=900',
                'https://images.pexels.com/photos/35069508/pexels-photo-35069508.jpeg?auto=compress&cs=tinysrgb&w=900',
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
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'jiaoling'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'ruqun'),
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
            str_contains($needle, 'buxie'),
            str_contains($needle, 'cloth shoes'),
            str_contains($needle, 'embroidered shoes'),
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
            'hanfu' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
            'jiaoling' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
            'ruao' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
            'mamian' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
            'buxie' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
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

        foreach (array_keys($this->getAttributeDefinitions()) as $attributeCode) {
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
            'hanfu' => 'hua_chao',
            'jiaoling' => 'hua_chao',
            'ruao' => 'hua_chao',
            'mamian' => 'yun_jin_studio',
            'buxie' => 'jin_xiu_ge',
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
            'hanfu' => 'silk',
            'jiaoling' => 'silk',
            'ruao' => 'silk',
            'mamian' => 'silk',
            'buxie' => 'cotton',
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
        $payload['brand'] ??= 'weshop_demo';
        $payload['material'] ??= $this->resolveDefaultMaterial($needle);
        $payload['color'] = $this->resolveDefaultColor($needle);
        $payload['size'] = $this->resolveDefaultSize($needle);
        $payload['style'] = $this->resolveDefaultStyle($needle);
        $payload['usage_scene'] = $this->resolveDefaultUsageScene($needle);
        $payload['package_type'] = $this->resolveDefaultPackageType($productData, $needle);
        $payload['warranty'] = $this->resolveDefaultWarranty($payload['delivery_type'], $needle);
        $payload['specification'] = $this->resolveDefaultSpecification($needle);
        $payload['form_factor'] = $this->resolveDefaultFormFactor($needle);
        $payload['feature_set'] = $this->resolveDefaultFeatureSet($needle);
        $payload['compatibility'] = $this->resolveDefaultCompatibility($needle);
        $payload['care_instruction'] = $this->resolveDefaultCareInstruction($payload['delivery_type'], $needle);
        $payload['season'] = $this->resolveDefaultSeason($needle);

        return $payload;
    }

    private function resolveDefaultMaterial(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'sofa') => 'linen',
            str_contains($needle, 'table'),
            str_contains($needle, 'oak') => 'oak',
            str_contains($needle, 'lamp') => 'metal',
            str_contains($needle, 'bag'),
            str_contains($needle, 'router') => 'nylon',
            str_contains($needle, 'book'),
            str_contains($needle, 'ebook') => 'paper',
            str_contains($needle, 'camera'),
            str_contains($needle, 'monitor') => 'aluminum',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'watchface') => 'digital_file',
            default => 'mixed_material',
        };
    }

    private function resolveDefaultColor(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'pink'),
            str_contains($needle, 'rose') => 'pink',
            str_contains($needle, 'purple'),
            str_contains($needle, 'violet') => 'purple',
            str_contains($needle, 'gold') => 'gold',
            str_contains($needle, 'brown') => 'brown',
            str_contains($needle, '-wht-'),
            str_contains($needle, ' white '),
            str_contains($needle, 'moon white') => 'white',
            str_contains($needle, '-gray-'),
            str_contains($needle, ' gray '),
            str_contains($needle, 'grey') => 'gray',
            str_contains($needle, 'silver') => 'silver',
            str_contains($needle, 'titanium') => 'silver',
            str_contains($needle, '-red-'),
            str_contains($needle, ' red '),
            str_contains($needle, 'crimson'),
            str_contains($needle, 'vermilion') => 'red',
            str_contains($needle, '-green-'),
            str_contains($needle, '-grn-'),
            str_contains($needle, ' green '),
            str_contains($needle, 'bamboo green') => 'green',
            str_contains($needle, '-navy-'),
            str_contains($needle, ' navy '),
            str_contains($needle, 'dark cyan') => 'navy',
            str_contains($needle, '-blue-'),
            str_contains($needle, ' blue ') => 'blue',
            str_contains($needle, '-bei-'),
            str_contains($needle, ' beige '),
            str_contains($needle, 'linen'),
            str_contains($needle, 'sofa') => 'beige',
            str_contains($needle, 'oak'),
            str_contains($needle, 'table'),
            str_contains($needle, 'natural') => 'natural',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'watchface'),
            str_contains($needle, 'preset') => 'blue',
            str_contains($needle, 'lamp') => 'white',
            str_contains($needle, '-blk-'),
            str_contains($needle, ' black ') => 'black',
            default => 'black',
        };
    }

    private function resolveDefaultSize(string $needle): string
    {
        return match (true) {
            str_contains($needle, '64gb') => '64gb',
            str_contains($needle, '32gb') => '32gb',
            str_contains($needle, '512') => '512gb',
            str_contains($needle, '256') => '256gb',
            str_contains($needle, '128') => '128gb',
            str_contains($needle, '1tb') => '1tb',
            str_contains($needle, 'usb-c'),
            str_contains($needle, 'usbc') => 'usb-c',
            str_contains($needle, 'magsafe') => 'magsafe',
            str_contains($needle, '15-inch'),
            str_contains($needle, '15 inch') => '15-inch',
            str_contains($needle, '13-inch'),
            str_contains($needle, '13 inch') => '13-inch',
            str_contains($needle, '27-inch'),
            str_contains($needle, '27 inch'),
            str_contains($needle, 'monitor') => '27-inch',
            str_contains($needle, '20000mah'),
            str_contains($needle, '20k') => '20000mah',
            str_contains($needle, '2-pack'),
            str_contains($needle, '2pk') => '2-pack',
            str_contains($needle, '84-key') => '84-key',
            str_contains($needle, '7-inch') => '7-inch',
            str_contains($needle, 'queen'),
            str_contains($needle, '160x200') => 'queen',
            str_contains($needle, '500g') => '500g',
            str_contains($needle, '50-pack') => '50-pack',
            str_contains($needle, 'three-seat'),
            str_contains($needle, 'sofa') => 'three-seat',
            str_contains($needle, 'download'),
            str_contains($needle, 'watchface'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'digital') => 'digital-bundle',
            str_contains($needle, 'shoe'),
            str_contains($needle, 'buxie') => '42',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian') => 'm',
            str_contains($needle, 'lamp'),
            str_contains($needle, 'speaker'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'camera'),
            str_contains($needle, 'hub'),
            str_contains($needle, 'dock'),
            str_contains($needle, 'reader'),
            str_contains($needle, 'table') => 'compact',
            default => 'regular',
        };
    }

    private function resolveDefaultStyle(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'buxie') => 'traditional',
            str_contains($needle, 'gaming') => 'gaming',
            str_contains($needle, 'office'),
            str_contains($needle, 'desk'),
            str_contains($needle, 'work') => 'professional',
            str_contains($needle, 'sport'),
            str_contains($needle, 'fitness') => 'sporty',
            str_contains($needle, 'home'),
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'minimalist',
            default => 'modern',
        };
    }

    private function resolveDefaultUsageScene(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'buxie') => 'traditional_outfit',
            str_contains($needle, 'travel'),
            str_contains($needle, 'power bank'),
            str_contains($needle, 'earbuds'),
            str_contains($needle, 'headphone') => 'travel',
            str_contains($needle, 'office'),
            str_contains($needle, 'desk'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'keyboard'),
            str_contains($needle, 'monitor'),
            str_contains($needle, 'laptop'),
            str_contains($needle, 'dock') => 'office',
            str_contains($needle, 'camera'),
            str_contains($needle, 'creator'),
            str_contains($needle, 'vlog'),
            str_contains($needle, 'preset') => 'creator',
            str_contains($needle, 'gaming') => 'gaming',
            str_contains($needle, 'smart home'),
            str_contains($needle, 'smarthome'),
            str_contains($needle, 'hub') => 'smart_home',
            str_contains($needle, 'home'),
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'home_living',
            str_contains($needle, 'download'),
            str_contains($needle, 'digital'),
            str_contains($needle, 'watchface') => 'digital',
            default => 'daily_use',
        };
    }

    private function resolveDefaultPackageType(array $productData, string $needle): string
    {
        if (is_array($productData['variants'] ?? null) && $productData['variants'] !== []) {
            return 'configurable_parent';
        }

        return match (true) {
            str_contains($needle, 'download'),
            str_contains($needle, 'digital'),
            str_contains($needle, 'watchface'),
            str_contains($needle, 'preset') => 'digital_pack',
            str_contains($needle, 'kit'),
            str_contains($needle, 'bundle'),
            str_contains($needle, '2-pack') => 'bundle',
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'furniture_piece',
            default => 'single_item',
        };
    }

    private function resolveDefaultWarranty(string $deliveryType, string $needle): string
    {
        if ($deliveryType === 'download') {
            return str_contains($needle, 'lifetime') || str_contains($needle, 'watchface')
                ? 'lifetime_access'
                : 'commercial_license';
        }

        return match (true) {
            str_contains($needle, 'phone'),
            str_contains($needle, 'iphone'),
            str_contains($needle, 'galaxy'),
            str_contains($needle, 'macbook'),
            str_contains($needle, 'ipad'),
            str_contains($needle, 'camera'),
            str_contains($needle, 'monitor'),
            str_contains($needle, 'router'),
            str_contains($needle, 'hub') => 'two_year',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'buxie'),
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'thirty_day',
            default => 'one_year',
        };
    }

    private function resolveDefaultSpecification(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'ultra') => 'ultra',
            str_contains($needle, 'pro') => 'pro',
            str_contains($needle, 'air') => 'lightweight',
            str_contains($needle, 'bundle'),
            str_contains($needle, 'kit'),
            str_contains($needle, '2-pack'),
            str_contains($needle, 'pack') => 'bundle',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'watchface') => 'digital',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'buxie') => 'heritage',
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'home_standard',
            str_contains($needle, 'mouse'),
            str_contains($needle, 'keyboard'),
            str_contains($needle, 'monitor'),
            str_contains($needle, 'dock') => 'workspace',
            default => 'standard',
        };
    }

    private function resolveDefaultFormFactor(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'phone'),
            str_contains($needle, 'iphone'),
            str_contains($needle, 'galaxy'),
            str_contains($needle, 'handheld'),
            str_contains($needle, 'power bank'),
            str_contains($needle, 'reader') => 'handheld',
            str_contains($needle, 'watch'),
            str_contains($needle, 'band') => 'wearable',
            str_contains($needle, 'headphone'),
            str_contains($needle, 'earbuds'),
            str_contains($needle, 'speaker') => 'audio_device',
            str_contains($needle, 'macbook'),
            str_contains($needle, 'ipad'),
            str_contains($needle, 'monitor'),
            str_contains($needle, 'keyboard'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'dock') => 'desktop_device',
            str_contains($needle, 'ruao') => 'apparel_top',
            str_contains($needle, 'mamian') => 'apparel_bottom',
            str_contains($needle, 'buxie'),
            str_contains($needle, 'shoe') => 'footwear',
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table') => 'furniture',
            str_contains($needle, 'lamp') => 'home_decor',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'watchface') => 'digital_asset',
            default => 'accessory',
        };
    }

    private function resolveDefaultFeatureSet(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'noise'),
            str_contains($needle, 'anc'),
            str_contains($needle, 'headphone'),
            str_contains($needle, 'earbuds') => 'noise_cancelling',
            str_contains($needle, 'power bank'),
            str_contains($needle, 'charging'),
            str_contains($needle, 'usb-c'),
            str_contains($needle, 'dock') => 'fast_charging',
            str_contains($needle, 'wireless'),
            str_contains($needle, 'bluetooth'),
            str_contains($needle, 'speaker'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'keyboard') => 'wireless',
            str_contains($needle, 'camera'),
            str_contains($needle, 'creator'),
            str_contains($needle, 'vlog') => 'creator_ready',
            str_contains($needle, 'gaming') => 'performance',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'buxie') => 'handcrafted',
            str_contains($needle, 'sofa'),
            str_contains($needle, 'chair'),
            str_contains($needle, 'mouse') => 'ergonomic',
            str_contains($needle, 'smart home'),
            str_contains($needle, 'smarthome'),
            str_contains($needle, 'hub'),
            str_contains($needle, 'router') => 'smart_connected',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'preset'),
            str_contains($needle, 'watchface') => 'instant_access',
            default => 'daily_essential',
        };
    }

    private function resolveDefaultCompatibility(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'iphone'),
            str_contains($needle, 'ipad'),
            str_contains($needle, 'macbook'),
            str_contains($needle, 'airpods'),
            str_contains($needle, 'watchface') => 'apple_ecosystem',
            str_contains($needle, 'galaxy'),
            str_contains($needle, 'android') => 'android',
            str_contains($needle, 'usb-c'),
            str_contains($needle, 'dock'),
            str_contains($needle, 'power bank') => 'usb_c',
            str_contains($needle, 'bluetooth'),
            str_contains($needle, 'speaker'),
            str_contains($needle, 'headphone'),
            str_contains($needle, 'earbuds'),
            str_contains($needle, 'mouse'),
            str_contains($needle, 'keyboard') => 'bluetooth',
            str_contains($needle, 'smart home'),
            str_contains($needle, 'smarthome'),
            str_contains($needle, 'hub') => 'smart_home',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian'),
            str_contains($needle, 'buxie') => 'hanfu_outfit',
            str_contains($needle, 'digital'),
            str_contains($needle, 'download'),
            str_contains($needle, 'preset') => 'digital_download',
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => 'home_living',
            default => 'universal',
        };
    }

    private function resolveDefaultCareInstruction(string $deliveryType, string $needle): string
    {
        if ($deliveryType === 'download') {
            return 'digital_backup';
        }

        return match (true) {
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian') => 'dry_clean',
            str_contains($needle, 'buxie'),
            str_contains($needle, 'shoe'),
            str_contains($needle, 'sofa') => 'spot_clean',
            str_contains($needle, 'cotton'),
            str_contains($needle, 'tee'),
            str_contains($needle, 'shirt') => 'machine_wash',
            str_contains($needle, 'phone'),
            str_contains($needle, 'macbook'),
            str_contains($needle, 'ipad'),
            str_contains($needle, 'camera'),
            str_contains($needle, 'monitor') => 'keep_dry',
            default => 'wipe_clean',
        };
    }

    private function resolveDefaultSeason(string $needle): string
    {
        return match (true) {
            str_contains($needle, 'linen'),
            str_contains($needle, 'summer') => 'summer',
            str_contains($needle, 'hoodie'),
            str_contains($needle, 'coat'),
            str_contains($needle, 'winter') => 'winter',
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'ruao'),
            str_contains($needle, 'mamian') => 'spring_autumn',
            default => 'all_season',
        };
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
        $groupId = $this->ensureAttributeGroupId($this->getDefaultProductSetId());
        if ($groupId <= 0) {
            return 0;
        }
        $statement = $pdo->prepare(
            'INSERT INTO "m_eav_attribute" '
            . '(code, eav_entity_id, set_id, group_id, name, type_id, basic_is_enable, frontend_is_visible, frontend_is_filterable, frontend_is_searchable, data_is_multiple, data_has_option) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $attributeCode,
            $entityId,
            $this->getDefaultProductSetId(),
            $groupId,
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

    private function syncManagedAttributeLocalDescriptions(): void
    {
        $attributeIdsByCode = $this->getManagedAttributeIdsByCode();
        if ($attributeIdsByCode === []) {
            return;
        }

        $this->syncAttributeLocalDescriptionRows($attributeIdsByCode);
        $this->syncOptionLocalDescriptionRows($attributeIdsByCode);
    }

    /**
     * @param array<string, int> $attributeIdsByCode
     */
    private function syncAttributeLocalDescriptionRows(array $attributeIdsByCode): void
    {
        $records = [];
        $localDefinitions = $this->getAttributeLocalDefinitions();
        foreach ($attributeIdsByCode as $attributeCode => $attributeId) {
            $attributeId = (int)$attributeId;
            if ($attributeId <= 0 || !isset($localDefinitions[$attributeCode])) {
                continue;
            }

            foreach ($localDefinitions[$attributeCode] as $localeCode => $name) {
                $records[] = [
                    AttributeLocalDescription::fields_ID => $attributeId,
                    AttributeLocalDescription::schema_fields_local_code => $localeCode,
                    AttributeLocalDescription::schema_fields_name => $name,
                ];
            }
        }

        if ($records === []) {
            return;
        }

        $model = $this->getAttributeLocalDescriptionModel();
        $model->clear()
            ->getQuery()
            ->where(AttributeLocalDescription::fields_ID, array_values($attributeIdsByCode), 'in')
            ->where(AttributeLocalDescription::schema_fields_local_code, ['zh_Hans_CN', 'en_US'], 'in')
            ->delete()
            ->fetch();
        $model->reset()->insert($records)->fetch();
    }

    /**
     * @param array<string, int> $attributeIdsByCode
     */
    private function syncOptionLocalDescriptionRows(array $attributeIdsByCode): void
    {
        $optionRecords = [];
        $optionIds = [];
        $attributeCodeById = array_flip($attributeIdsByCode);
        $localDefinitions = $this->getOptionLocalDefinitions();
        $optionRows = ($this->eavAttributeOption ?? ObjectManager::getInstance(Option::class))->reset()
            ->fields(Option::schema_fields_ID . ',' . Option::schema_fields_attribute_id . ',' . Option::schema_fields_code)
            ->where(Option::schema_fields_attribute_id, array_values($attributeIdsByCode), 'in')
            ->select()
            ->fetchArray();

        foreach ($optionRows as $optionRow) {
            $attributeId = (int)($optionRow[Option::schema_fields_attribute_id] ?? 0);
            $attributeCode = (string)($attributeCodeById[$attributeId] ?? '');
            $optionId = (int)($optionRow[Option::schema_fields_ID] ?? 0);
            $optionCode = (string)($optionRow[Option::schema_fields_code] ?? '');
            $translations = $localDefinitions[$attributeCode][$optionCode] ?? null;
            if ($attributeCode === '' || $optionId <= 0 || !is_array($translations)) {
                continue;
            }

            $optionIds[] = $optionId;
            foreach ($translations as $localeCode => $value) {
                $optionRecords[] = [
                    OptionLocalDescription::fields_ID => $optionId,
                    OptionLocalDescription::schema_fields_local_code => $localeCode,
                    Option::schema_fields_value => $value,
                ];
            }
        }

        if ($optionRecords === [] || $optionIds === []) {
            return;
        }

        $model = $this->getOptionLocalDescriptionModel();
        $model->clear()
            ->getQuery()
            ->where(OptionLocalDescription::fields_ID, array_values(array_unique($optionIds)), 'in')
            ->where(OptionLocalDescription::schema_fields_local_code, ['zh_Hans_CN', 'en_US'], 'in')
            ->delete()
            ->fetch();
        $model->reset()->insert($optionRecords)->fetch();
    }

    private function getAttributeLocalDescriptionModel(): AttributeLocalDescription
    {
        return $this->attributeLocalDescription ?? ObjectManager::getInstance(AttributeLocalDescription::class);
    }

    private function getOptionLocalDescriptionModel(): OptionLocalDescription
    {
        return $this->optionLocalDescription ?? ObjectManager::getInstance(OptionLocalDescription::class);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getAttributeLocalDefinitions(): array
    {
        return [
            'color' => ['zh_Hans_CN' => '颜色', 'en_US' => 'Color'],
            'size' => ['zh_Hans_CN' => '尺寸', 'en_US' => 'Size'],
            'material' => ['zh_Hans_CN' => '材质', 'en_US' => 'Material'],
            'brand' => ['zh_Hans_CN' => '品牌', 'en_US' => 'Brand'],
            'style' => ['zh_Hans_CN' => '风格', 'en_US' => 'Style'],
            'usage_scene' => ['zh_Hans_CN' => '使用场景', 'en_US' => 'Usage Scene'],
            'package_type' => ['zh_Hans_CN' => '包装类型', 'en_US' => 'Package Type'],
            'warranty' => ['zh_Hans_CN' => '售后保障', 'en_US' => 'Warranty'],
            'specification' => ['zh_Hans_CN' => '规格档位', 'en_US' => 'Specification'],
            'form_factor' => ['zh_Hans_CN' => '商品形态', 'en_US' => 'Form Factor'],
            'feature_set' => ['zh_Hans_CN' => '核心卖点', 'en_US' => 'Feature Set'],
            'compatibility' => ['zh_Hans_CN' => '兼容性', 'en_US' => 'Compatibility'],
            'care_instruction' => ['zh_Hans_CN' => '保养方式', 'en_US' => 'Care Instruction'],
            'season' => ['zh_Hans_CN' => '适用季节', 'en_US' => 'Season'],
            'delivery_type' => ['zh_Hans_CN' => '配送类型', 'en_US' => 'Delivery Type'],
            'download_format' => ['zh_Hans_CN' => '下载格式', 'en_US' => 'Download Format'],
            'license_term' => ['zh_Hans_CN' => '授权期限', 'en_US' => 'License Term'],
        ];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function getOptionLocalDefinitions(): array
    {
        return [
            'color' => [
                'black' => ['zh_Hans_CN' => '黑色', 'en_US' => 'Black'],
                'white' => ['zh_Hans_CN' => '白色', 'en_US' => 'White'],
                'gray' => ['zh_Hans_CN' => '灰色', 'en_US' => 'Gray'],
                'silver' => ['zh_Hans_CN' => '银色', 'en_US' => 'Silver'],
                'blue' => ['zh_Hans_CN' => '蓝色', 'en_US' => 'Blue'],
                'green' => ['zh_Hans_CN' => '绿色', 'en_US' => 'Green'],
                'red' => ['zh_Hans_CN' => '红色', 'en_US' => 'Red'],
                'navy' => ['zh_Hans_CN' => '黛青', 'en_US' => 'Navy'],
                'beige' => ['zh_Hans_CN' => '米杏', 'en_US' => 'Beige'],
                'natural' => ['zh_Hans_CN' => '原木色', 'en_US' => 'Natural'],
                'pink' => ['zh_Hans_CN' => '粉色', 'en_US' => 'Pink'],
                'purple' => ['zh_Hans_CN' => '紫色', 'en_US' => 'Purple'],
                'gold' => ['zh_Hans_CN' => '金色', 'en_US' => 'Gold'],
                'brown' => ['zh_Hans_CN' => '棕色', 'en_US' => 'Brown'],
            ],
            'size' => [
                'xs' => ['zh_Hans_CN' => 'XS', 'en_US' => 'XS'],
                's' => ['zh_Hans_CN' => 'S', 'en_US' => 'S'],
                'm' => ['zh_Hans_CN' => 'M', 'en_US' => 'M'],
                'l' => ['zh_Hans_CN' => 'L', 'en_US' => 'L'],
                'xl' => ['zh_Hans_CN' => 'XL', 'en_US' => 'XL'],
                'xxl' => ['zh_Hans_CN' => 'XXL', 'en_US' => 'XXL'],
                '32gb' => ['zh_Hans_CN' => '32GB', 'en_US' => '32GB'],
                '64gb' => ['zh_Hans_CN' => '64GB', 'en_US' => '64GB'],
                '256gb' => ['zh_Hans_CN' => '256GB', 'en_US' => '256GB'],
                '512gb' => ['zh_Hans_CN' => '512GB', 'en_US' => '512GB'],
                '128gb' => ['zh_Hans_CN' => '128GB', 'en_US' => '128GB'],
                '1tb' => ['zh_Hans_CN' => '1TB', 'en_US' => '1TB'],
                'usb-c' => ['zh_Hans_CN' => 'USB-C', 'en_US' => 'USB-C'],
                'magsafe' => ['zh_Hans_CN' => 'MagSafe', 'en_US' => 'MagSafe'],
                '13-inch' => ['zh_Hans_CN' => '13 英寸', 'en_US' => '13-inch'],
                '15-inch' => ['zh_Hans_CN' => '15 英寸', 'en_US' => '15-inch'],
                '27-inch' => ['zh_Hans_CN' => '27 英寸', 'en_US' => '27-inch'],
                '7-inch' => ['zh_Hans_CN' => '7 英寸', 'en_US' => '7-inch'],
                '20000mah' => ['zh_Hans_CN' => '20000mAh', 'en_US' => '20000mAh'],
                '2-pack' => ['zh_Hans_CN' => '2 件装', 'en_US' => '2-Pack'],
                '84-key' => ['zh_Hans_CN' => '84 键', 'en_US' => '84-Key'],
                'compact' => ['zh_Hans_CN' => '紧凑款', 'en_US' => 'Compact'],
                'regular' => ['zh_Hans_CN' => '标准款', 'en_US' => 'Regular'],
                'large' => ['zh_Hans_CN' => '大号', 'en_US' => 'Large'],
                'one-size' => ['zh_Hans_CN' => '均码', 'en_US' => 'One Size'],
                'three-seat' => ['zh_Hans_CN' => '三人位', 'en_US' => 'Three-Seat'],
                'digital-bundle' => ['zh_Hans_CN' => '数字套装', 'en_US' => 'Digital Bundle'],
                'queen' => ['zh_Hans_CN' => 'Queen 尺寸', 'en_US' => 'Queen'],
                '500g' => ['zh_Hans_CN' => '500g', 'en_US' => '500g'],
                '50-pack' => ['zh_Hans_CN' => '50 件装', 'en_US' => '50-Pack'],
                '40' => ['zh_Hans_CN' => '欧码 40', 'en_US' => 'EU 40'],
                '41' => ['zh_Hans_CN' => '欧码 41', 'en_US' => 'EU 41'],
                '42' => ['zh_Hans_CN' => '欧码 42', 'en_US' => 'EU 42'],
                '43' => ['zh_Hans_CN' => '欧码 43', 'en_US' => 'EU 43'],
            ],
            'material' => [
                'titanium' => ['zh_Hans_CN' => '钛金属', 'en_US' => 'Titanium'],
                'aluminum' => ['zh_Hans_CN' => '铝合金', 'en_US' => 'Aluminum'],
                'plastic' => ['zh_Hans_CN' => '塑料', 'en_US' => 'Plastic'],
                'cotton' => ['zh_Hans_CN' => '棉', 'en_US' => 'Cotton'],
                'cotton_blend' => ['zh_Hans_CN' => '棉混纺', 'en_US' => 'Cotton Blend'],
                'silk' => ['zh_Hans_CN' => '丝绸', 'en_US' => 'Silk'],
                'linen' => ['zh_Hans_CN' => '亚麻', 'en_US' => 'Linen'],
                'oak' => ['zh_Hans_CN' => '橡木', 'en_US' => 'Oak'],
                'metal' => ['zh_Hans_CN' => '金属', 'en_US' => 'Metal'],
                'nylon' => ['zh_Hans_CN' => '尼龙', 'en_US' => 'Nylon'],
                'knit' => ['zh_Hans_CN' => '针织', 'en_US' => 'Knit'],
                'paper' => ['zh_Hans_CN' => '纸质', 'en_US' => 'Paper'],
                'digital_file' => ['zh_Hans_CN' => '数字文件', 'en_US' => 'Digital File'],
                'mixed_material' => ['zh_Hans_CN' => '混合材质', 'en_US' => 'Mixed Material'],
            ],
            'brand' => [
                'weshop_demo' => ['zh_Hans_CN' => 'WeShop 演示', 'en_US' => 'WeShop Demo'],
                'apple' => ['zh_Hans_CN' => 'Apple', 'en_US' => 'Apple'],
                'samsung' => ['zh_Hans_CN' => 'Samsung', 'en_US' => 'Samsung'],
                'sony' => ['zh_Hans_CN' => 'Sony', 'en_US' => 'Sony'],
                'bose' => ['zh_Hans_CN' => 'Bose', 'en_US' => 'Bose'],
                'logitech' => ['zh_Hans_CN' => 'Logitech', 'en_US' => 'Logitech'],
                'canon' => ['zh_Hans_CN' => 'Canon', 'en_US' => 'Canon'],
                'hua_chao' => ['zh_Hans_CN' => '华朝汉服', 'en_US' => 'Huachao Hanfu'],
                'yun_jin_studio' => ['zh_Hans_CN' => '云锦工坊', 'en_US' => 'Yunjin Studio'],
                'jin_xiu_ge' => ['zh_Hans_CN' => '锦绣阁', 'en_US' => 'Jinxiu Pavilion'],
                'nike' => ['zh_Hans_CN' => 'Nike', 'en_US' => 'Nike'],
                'adidas' => ['zh_Hans_CN' => 'Adidas', 'en_US' => 'Adidas'],
                'levis' => ['zh_Hans_CN' => 'Levi\'s', 'en_US' => 'Levi\'s'],
                'uniqlo' => ['zh_Hans_CN' => 'Uniqlo', 'en_US' => 'Uniqlo'],
                'ikea' => ['zh_Hans_CN' => 'IKEA', 'en_US' => 'IKEA'],
                'muji' => ['zh_Hans_CN' => 'MUJI', 'en_US' => 'MUJI'],
                'dyson' => ['zh_Hans_CN' => 'Dyson', 'en_US' => 'Dyson'],
                'nespresso' => ['zh_Hans_CN' => 'Nespresso', 'en_US' => 'Nespresso'],
                'osprey' => ['zh_Hans_CN' => 'Osprey', 'en_US' => 'Osprey'],
                'herman_miller' => ['zh_Hans_CN' => 'Herman Miller', 'en_US' => 'Herman Miller'],
                'lindt' => ['zh_Hans_CN' => 'Lindt', 'en_US' => 'Lindt'],
                'lego' => ['zh_Hans_CN' => 'LEGO', 'en_US' => 'LEGO'],
                'tesla' => ['zh_Hans_CN' => 'Tesla', 'en_US' => 'Tesla'],
            ],
            'style' => [
                'modern' => ['zh_Hans_CN' => '现代', 'en_US' => 'Modern'],
                'minimalist' => ['zh_Hans_CN' => '简约', 'en_US' => 'Minimalist'],
                'traditional' => ['zh_Hans_CN' => '传统', 'en_US' => 'Traditional'],
                'professional' => ['zh_Hans_CN' => '专业', 'en_US' => 'Professional'],
                'sporty' => ['zh_Hans_CN' => '运动', 'en_US' => 'Sporty'],
                'gaming' => ['zh_Hans_CN' => '游戏', 'en_US' => 'Gaming'],
            ],
            'usage_scene' => [
                'daily_use' => ['zh_Hans_CN' => '日常使用', 'en_US' => 'Daily Use'],
                'traditional_outfit' => ['zh_Hans_CN' => '国风穿搭', 'en_US' => 'Traditional Outfit'],
                'travel' => ['zh_Hans_CN' => '旅行通勤', 'en_US' => 'Travel'],
                'office' => ['zh_Hans_CN' => '办公', 'en_US' => 'Office'],
                'creator' => ['zh_Hans_CN' => '创作工作', 'en_US' => 'Creator Work'],
                'gaming' => ['zh_Hans_CN' => '游戏娱乐', 'en_US' => 'Gaming'],
                'smart_home' => ['zh_Hans_CN' => '智能家居', 'en_US' => 'Smart Home'],
                'home_living' => ['zh_Hans_CN' => '居家生活', 'en_US' => 'Home Living'],
                'digital' => ['zh_Hans_CN' => '数字使用', 'en_US' => 'Digital Use'],
            ],
            'package_type' => [
                'single_item' => ['zh_Hans_CN' => '单品', 'en_US' => 'Single Item'],
                'configurable_parent' => ['zh_Hans_CN' => '可配置主商品', 'en_US' => 'Configurable Parent'],
                'bundle' => ['zh_Hans_CN' => '套装', 'en_US' => 'Bundle'],
                'digital_pack' => ['zh_Hans_CN' => '数字资源包', 'en_US' => 'Digital Pack'],
                'furniture_piece' => ['zh_Hans_CN' => '家具体件', 'en_US' => 'Furniture Piece'],
            ],
            'warranty' => [
                'thirty_day' => ['zh_Hans_CN' => '30 天支持', 'en_US' => '30-Day Support'],
                'one_year' => ['zh_Hans_CN' => '一年质保', 'en_US' => '1-Year Warranty'],
                'two_year' => ['zh_Hans_CN' => '两年质保', 'en_US' => '2-Year Warranty'],
                'lifetime_access' => ['zh_Hans_CN' => '终身访问', 'en_US' => 'Lifetime Access'],
                'commercial_license' => ['zh_Hans_CN' => '商用授权', 'en_US' => 'Commercial License'],
            ],
            'specification' => [
                'standard' => ['zh_Hans_CN' => '标准款', 'en_US' => 'Standard'],
                'pro' => ['zh_Hans_CN' => 'Pro 款', 'en_US' => 'Pro'],
                'ultra' => ['zh_Hans_CN' => 'Ultra 款', 'en_US' => 'Ultra'],
                'lightweight' => ['zh_Hans_CN' => '轻量款', 'en_US' => 'Lightweight'],
                'bundle' => ['zh_Hans_CN' => '套装规格', 'en_US' => 'Bundle'],
                'digital' => ['zh_Hans_CN' => '数字规格', 'en_US' => 'Digital'],
                'heritage' => ['zh_Hans_CN' => '传统规格', 'en_US' => 'Heritage'],
                'home_standard' => ['zh_Hans_CN' => '家居标准款', 'en_US' => 'Home Standard'],
                'workspace' => ['zh_Hans_CN' => '工作台规格', 'en_US' => 'Workspace'],
            ],
            'form_factor' => [
                'handheld' => ['zh_Hans_CN' => '手持设备', 'en_US' => 'Handheld'],
                'wearable' => ['zh_Hans_CN' => '可穿戴', 'en_US' => 'Wearable'],
                'audio_device' => ['zh_Hans_CN' => '音频设备', 'en_US' => 'Audio Device'],
                'desktop_device' => ['zh_Hans_CN' => '桌面设备', 'en_US' => 'Desktop Device'],
                'apparel_top' => ['zh_Hans_CN' => '上装', 'en_US' => 'Apparel Top'],
                'apparel_bottom' => ['zh_Hans_CN' => '下装', 'en_US' => 'Apparel Bottom'],
                'footwear' => ['zh_Hans_CN' => '鞋履', 'en_US' => 'Footwear'],
                'furniture' => ['zh_Hans_CN' => '家具', 'en_US' => 'Furniture'],
                'home_decor' => ['zh_Hans_CN' => '家居装饰', 'en_US' => 'Home Decor'],
                'digital_asset' => ['zh_Hans_CN' => '数字资产', 'en_US' => 'Digital Asset'],
                'accessory' => ['zh_Hans_CN' => '配件', 'en_US' => 'Accessory'],
            ],
            'feature_set' => [
                'daily_essential' => ['zh_Hans_CN' => '日常基础', 'en_US' => 'Daily Essential'],
                'noise_cancelling' => ['zh_Hans_CN' => '主动降噪', 'en_US' => 'Noise Cancelling'],
                'fast_charging' => ['zh_Hans_CN' => '快速充电', 'en_US' => 'Fast Charging'],
                'wireless' => ['zh_Hans_CN' => '无线连接', 'en_US' => 'Wireless'],
                'creator_ready' => ['zh_Hans_CN' => '创作者适配', 'en_US' => 'Creator Ready'],
                'performance' => ['zh_Hans_CN' => '性能增强', 'en_US' => 'Performance'],
                'handcrafted' => ['zh_Hans_CN' => '手工工艺', 'en_US' => 'Handcrafted'],
                'ergonomic' => ['zh_Hans_CN' => '人体工学', 'en_US' => 'Ergonomic'],
                'smart_connected' => ['zh_Hans_CN' => '智能互联', 'en_US' => 'Smart Connected'],
                'instant_access' => ['zh_Hans_CN' => '即时访问', 'en_US' => 'Instant Access'],
            ],
            'compatibility' => [
                'universal' => ['zh_Hans_CN' => '通用', 'en_US' => 'Universal'],
                'apple_ecosystem' => ['zh_Hans_CN' => 'Apple 生态', 'en_US' => 'Apple Ecosystem'],
                'android' => ['zh_Hans_CN' => 'Android', 'en_US' => 'Android'],
                'usb_c' => ['zh_Hans_CN' => 'USB-C', 'en_US' => 'USB-C'],
                'bluetooth' => ['zh_Hans_CN' => '蓝牙', 'en_US' => 'Bluetooth'],
                'smart_home' => ['zh_Hans_CN' => '智能家居', 'en_US' => 'Smart Home'],
                'hanfu_outfit' => ['zh_Hans_CN' => '汉服穿搭', 'en_US' => 'Hanfu Outfit'],
                'home_living' => ['zh_Hans_CN' => '家居空间', 'en_US' => 'Home Living'],
                'digital_download' => ['zh_Hans_CN' => '数字下载', 'en_US' => 'Digital Download'],
            ],
            'care_instruction' => [
                'wipe_clean' => ['zh_Hans_CN' => '擦拭清洁', 'en_US' => 'Wipe Clean'],
                'hand_wash' => ['zh_Hans_CN' => '手洗', 'en_US' => 'Hand Wash'],
                'dry_clean' => ['zh_Hans_CN' => '干洗', 'en_US' => 'Dry Clean'],
                'machine_wash' => ['zh_Hans_CN' => '机洗', 'en_US' => 'Machine Wash'],
                'keep_dry' => ['zh_Hans_CN' => '保持干燥', 'en_US' => 'Keep Dry'],
                'digital_backup' => ['zh_Hans_CN' => '备份文件', 'en_US' => 'Digital Backup'],
                'spot_clean' => ['zh_Hans_CN' => '局部清洁', 'en_US' => 'Spot Clean'],
            ],
            'season' => [
                'all_season' => ['zh_Hans_CN' => '四季', 'en_US' => 'All Season'],
                'spring_autumn' => ['zh_Hans_CN' => '春秋', 'en_US' => 'Spring/Autumn'],
                'summer' => ['zh_Hans_CN' => '夏季', 'en_US' => 'Summer'],
                'winter' => ['zh_Hans_CN' => '冬季', 'en_US' => 'Winter'],
            ],
            'delivery_type' => [
                'physical' => ['zh_Hans_CN' => '实物配送', 'en_US' => 'Physical Shipment'],
                'download' => ['zh_Hans_CN' => '数字下载', 'en_US' => 'Download'],
                'virtual' => ['zh_Hans_CN' => '虚拟访问', 'en_US' => 'Virtual Access'],
            ],
            'download_format' => [
                'zip' => ['zh_Hans_CN' => 'ZIP', 'en_US' => 'ZIP'],
                'pdf' => ['zh_Hans_CN' => 'PDF', 'en_US' => 'PDF'],
                'mp3' => ['zh_Hans_CN' => 'MP3', 'en_US' => 'MP3'],
                'wav' => ['zh_Hans_CN' => 'WAV', 'en_US' => 'WAV'],
                'license_key' => ['zh_Hans_CN' => '授权密钥', 'en_US' => 'License Key'],
            ],
            'license_term' => [
                'lifetime' => ['zh_Hans_CN' => '终身', 'en_US' => 'Lifetime'],
                'commercial' => ['zh_Hans_CN' => '商用', 'en_US' => 'Commercial Use'],
                'personal' => ['zh_Hans_CN' => '个人使用', 'en_US' => 'Personal Use'],
                'subscription' => ['zh_Hans_CN' => '订阅', 'en_US' => 'Subscription'],
            ],
        ];
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
        return $this->ensureAttributeGroupId($this->getDefaultProductSetId());
    }

    private function ensureAttributeGroupId(int $setId): int
    {
        if ($setId <= 0) {
            return 0;
        }

        $groupModel = $this->attributeGroup ?? ObjectManager::getInstance(Group::class);
        $group = $groupModel->reset()
            ->where(Group::schema_fields_code, 'default')
            ->where(Group::schema_fields_set_id, $setId)
            ->where(Group::schema_fields_eav_entity_id, $this->getProductEntityId())
            ->find()
            ->fetch();
        $groupId = (int)($group->getId() ?? 0);
        if ($groupId > 0) {
            return $groupId;
        }

        $groupModel->reset()->clearData();
        $groupModel->setData(Group::schema_fields_code, 'default')
            ->setData(Group::schema_fields_set_id, $setId)
            ->setData(Group::schema_fields_eav_entity_id, $this->getProductEntityId())
            ->setData(Group::schema_fields_name, '默认属性组')
            ->forceCheck(true, [Group::schema_fields_code, Group::schema_fields_set_id, Group::schema_fields_eav_entity_id])
            ->save();

        return (int)($groupModel->getId() ?? 0);
    }

    private function resolveProductAttributeSetId(array $productData): int
    {
        $entityId = $this->getProductEntityId();
        if ($entityId <= 0) {
            return 0;
        }

        $setMeta = $this->resolveProductAttributeSetMeta($productData);
        $setModel = $this->attributeSet ?? ObjectManager::getInstance(Set::class);
        $existing = $setModel->reset()
            ->where(Set::schema_fields_code, $setMeta['code'])
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->find()
            ->fetch();
        $setId = (int)($existing->getId() ?? 0);
        if ($setId > 0) {
            $this->ensureAttributeGroupId($setId);
            return $setId;
        }

        $setModel->reset()->clearData();
        $setModel->setData(Set::schema_fields_code, $setMeta['code'])
            ->setData(Set::schema_fields_eav_entity_id, $entityId)
            ->setData(Set::schema_fields_name, $setMeta['name'])
            ->forceCheck(true, [Set::schema_fields_code, Set::schema_fields_eav_entity_id])
            ->save();

        $setId = (int)($setModel->getId() ?? 0);
        if ($setId > 0) {
            $this->ensureAttributeGroupId($setId);
        }

        return $setId;
    }

    /**
     * @return array{code:string,name:string}
     */
    private function resolveProductAttributeSetMeta(array $productData): array
    {
        $needle = strtolower(
            (string)($productData['sku'] ?? '') . ' '
            . (string)($productData['name'] ?? '') . ' '
            . (string)($productData['meta_keywords'] ?? '') . ' '
            . implode(' ', array_map('strval', $productData['category_handles'] ?? []))
        );

        return match (true) {
            str_contains($needle, 'hanfu'),
            str_contains($needle, 'apparel'),
            str_contains($needle, 'clothing'),
            str_contains($needle, 'daily-wear'),
            str_contains($needle, 'shoe'),
            str_contains($needle, 'buxie') => ['code' => 'apparel', 'name' => '服饰属性集'],
            str_contains($needle, 'iphone'),
            str_contains($needle, 'galaxy'),
            str_contains($needle, 'electronics'),
            str_contains($needle, 'smart-device'),
            str_contains($needle, 'audio'),
            str_contains($needle, 'computer'),
            str_contains($needle, 'laptop') => ['code' => 'electronics', 'name' => '电子属性集'],
            str_contains($needle, 'download'),
            str_contains($needle, 'watchface'),
            str_contains($needle, 'preset') => ['code' => 'digital', 'name' => '数字商品属性集'],
            str_contains($needle, 'home'),
            str_contains($needle, 'sofa'),
            str_contains($needle, 'table'),
            str_contains($needle, 'lamp') => ['code' => 'home', 'name' => '家居属性集'],
            default => ['code' => 'default', 'name' => '默认属性集'],
        };
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
                'black' => ['value' => 'Black', 'swatch_color' => '#111111', 'swatch_image' => 'https://images.unsplash.com/photo-1616348436168-de43ad0db179?auto=format&fit=crop&w=240&q=70'],
                'white' => ['value' => 'White', 'swatch_color' => '#f5f5f5', 'swatch_image' => 'https://images.unsplash.com/photo-1600294037681-c80b4cb5b434?auto=format&fit=crop&w=240&q=70'],
                'gray' => ['value' => 'Gray', 'swatch_color' => '#7c7c7c', 'swatch_image' => 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=240&q=70'],
                'silver' => ['value' => 'Silver', 'swatch_color' => '#c0c0c0', 'swatch_image' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=240&q=70'],
                'blue' => ['value' => 'Blue', 'swatch_color' => '#2563eb', 'swatch_image' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=240&q=70'],
                'green' => ['value' => 'Green', 'swatch_color' => '#16a34a', 'swatch_image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=240'],
                'red' => ['value' => 'Red', 'swatch_color' => '#dc2626', 'swatch_image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=240'],
                'navy' => ['value' => 'Navy', 'swatch_color' => '#1e3a8a', 'swatch_image' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=240'],
                'beige' => ['value' => 'Beige', 'swatch_color' => '#d6c6a8', 'swatch_image' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=240'],
                'natural' => ['value' => 'Natural', 'swatch_color' => '#b08d57', 'swatch_image' => 'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=240&q=70'],
                'pink' => ['value' => 'Pink', 'swatch_color' => '#ec4899', 'swatch_image' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=240&q=70'],
                'purple' => ['value' => 'Purple', 'swatch_color' => '#7c3aed', 'swatch_image' => 'https://images.unsplash.com/photo-1557683316-973673baf926?auto=format&fit=crop&w=240&q=70'],
                'gold' => ['value' => 'Gold', 'swatch_color' => '#d4af37', 'swatch_image' => 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?auto=format&fit=crop&w=240&q=70'],
                'brown' => ['value' => 'Brown', 'swatch_color' => '#8b5a2b', 'swatch_image' => 'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=240&q=70'],
            ]],
            'size' => ['name' => 'Size', 'options' => [
                'xs' => ['value' => 'XS'],
                's' => ['value' => 'S'],
                'm' => ['value' => 'M'],
                'l' => ['value' => 'L'],
                'xl' => ['value' => 'XL'],
                'xxl' => ['value' => 'XXL'],
                '32gb' => ['value' => '32GB'],
                '64gb' => ['value' => '64GB'],
                '256gb' => ['value' => '256GB'],
                '512gb' => ['value' => '512GB'],
                '128gb' => ['value' => '128GB'],
                '1tb' => ['value' => '1TB'],
                'usb-c' => ['value' => 'USB-C'],
                'magsafe' => ['value' => 'MagSafe'],
                '13-inch' => ['value' => '13-inch'],
                '15-inch' => ['value' => '15-inch'],
                '27-inch' => ['value' => '27-inch'],
                '7-inch' => ['value' => '7-inch'],
                '20000mah' => ['value' => '20000mAh'],
                '2-pack' => ['value' => '2-Pack'],
                '84-key' => ['value' => '84-Key'],
                'compact' => ['value' => 'Compact'],
                'regular' => ['value' => 'Regular'],
                'large' => ['value' => 'Large'],
                'one-size' => ['value' => 'One Size'],
                'three-seat' => ['value' => 'Three-Seat'],
                'digital-bundle' => ['value' => 'Digital Bundle'],
                'queen' => ['value' => 'Queen'],
                '500g' => ['value' => '500g'],
                '50-pack' => ['value' => '50-Pack'],
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
                'silk' => ['value' => 'Silk'],
                'linen' => ['value' => 'Linen'],
                'oak' => ['value' => 'Oak'],
                'metal' => ['value' => 'Metal'],
                'nylon' => ['value' => 'Nylon'],
                'knit' => ['value' => 'Knit'],
                'paper' => ['value' => 'Paper'],
                'digital_file' => ['value' => 'Digital File'],
                'mixed_material' => ['value' => 'Mixed Material'],
            ]],
            'brand' => ['name' => 'Brand', 'options' => [
                'weshop_demo' => ['value' => 'WeShop Demo'],
                'apple' => ['value' => 'Apple'],
                'samsung' => ['value' => 'Samsung'],
                'sony' => ['value' => 'Sony'],
                'bose' => ['value' => 'Bose'],
                'logitech' => ['value' => 'Logitech'],
                'canon' => ['value' => 'Canon'],
                'hua_chao' => ['value' => 'Huachao Hanfu'],
                'yun_jin_studio' => ['value' => 'Yunjin Studio'],
                'jin_xiu_ge' => ['value' => 'Jinxiu Pavilion'],
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
            'style' => ['name' => 'Style', 'options' => [
                'modern' => ['value' => 'Modern'],
                'minimalist' => ['value' => 'Minimalist'],
                'traditional' => ['value' => 'Traditional'],
                'professional' => ['value' => 'Professional'],
                'sporty' => ['value' => 'Sporty'],
                'gaming' => ['value' => 'Gaming'],
            ]],
            'usage_scene' => ['name' => 'Usage Scene', 'options' => [
                'daily_use' => ['value' => 'Daily Use'],
                'traditional_outfit' => ['value' => 'Traditional Outfit'],
                'travel' => ['value' => 'Travel'],
                'office' => ['value' => 'Office'],
                'creator' => ['value' => 'Creator Work'],
                'gaming' => ['value' => 'Gaming'],
                'smart_home' => ['value' => 'Smart Home'],
                'home_living' => ['value' => 'Home Living'],
                'digital' => ['value' => 'Digital Use'],
            ]],
            'package_type' => ['name' => 'Package Type', 'options' => [
                'single_item' => ['value' => 'Single Item'],
                'configurable_parent' => ['value' => 'Configurable Parent'],
                'bundle' => ['value' => 'Bundle'],
                'digital_pack' => ['value' => 'Digital Pack'],
                'furniture_piece' => ['value' => 'Furniture Piece'],
            ]],
            'warranty' => ['name' => 'Warranty', 'options' => [
                'thirty_day' => ['value' => '30-Day Support'],
                'one_year' => ['value' => '1-Year Warranty'],
                'two_year' => ['value' => '2-Year Warranty'],
                'lifetime_access' => ['value' => 'Lifetime Access'],
                'commercial_license' => ['value' => 'Commercial License'],
            ]],
            'specification' => ['name' => 'Specification', 'options' => [
                'standard' => ['value' => 'Standard'],
                'pro' => ['value' => 'Pro'],
                'ultra' => ['value' => 'Ultra'],
                'lightweight' => ['value' => 'Lightweight'],
                'bundle' => ['value' => 'Bundle'],
                'digital' => ['value' => 'Digital'],
                'heritage' => ['value' => 'Heritage'],
                'home_standard' => ['value' => 'Home Standard'],
                'workspace' => ['value' => 'Workspace'],
            ]],
            'form_factor' => ['name' => 'Form Factor', 'options' => [
                'handheld' => ['value' => 'Handheld'],
                'wearable' => ['value' => 'Wearable'],
                'audio_device' => ['value' => 'Audio Device'],
                'desktop_device' => ['value' => 'Desktop Device'],
                'apparel_top' => ['value' => 'Apparel Top'],
                'apparel_bottom' => ['value' => 'Apparel Bottom'],
                'footwear' => ['value' => 'Footwear'],
                'furniture' => ['value' => 'Furniture'],
                'home_decor' => ['value' => 'Home Decor'],
                'digital_asset' => ['value' => 'Digital Asset'],
                'accessory' => ['value' => 'Accessory'],
            ]],
            'feature_set' => ['name' => 'Feature Set', 'options' => [
                'daily_essential' => ['value' => 'Daily Essential'],
                'noise_cancelling' => ['value' => 'Noise Cancelling'],
                'fast_charging' => ['value' => 'Fast Charging'],
                'wireless' => ['value' => 'Wireless'],
                'creator_ready' => ['value' => 'Creator Ready'],
                'performance' => ['value' => 'Performance'],
                'handcrafted' => ['value' => 'Handcrafted'],
                'ergonomic' => ['value' => 'Ergonomic'],
                'smart_connected' => ['value' => 'Smart Connected'],
                'instant_access' => ['value' => 'Instant Access'],
            ]],
            'compatibility' => ['name' => 'Compatibility', 'options' => [
                'universal' => ['value' => 'Universal'],
                'apple_ecosystem' => ['value' => 'Apple Ecosystem'],
                'android' => ['value' => 'Android'],
                'usb_c' => ['value' => 'USB-C'],
                'bluetooth' => ['value' => 'Bluetooth'],
                'smart_home' => ['value' => 'Smart Home'],
                'hanfu_outfit' => ['value' => 'Hanfu Outfit'],
                'home_living' => ['value' => 'Home Living'],
                'digital_download' => ['value' => 'Digital Download'],
            ]],
            'care_instruction' => ['name' => 'Care Instruction', 'options' => [
                'wipe_clean' => ['value' => 'Wipe Clean'],
                'hand_wash' => ['value' => 'Hand Wash'],
                'dry_clean' => ['value' => 'Dry Clean'],
                'machine_wash' => ['value' => 'Machine Wash'],
                'keep_dry' => ['value' => 'Keep Dry'],
                'digital_backup' => ['value' => 'Digital Backup'],
                'spot_clean' => ['value' => 'Spot Clean'],
            ]],
            'season' => ['name' => 'Season', 'options' => [
                'all_season' => ['value' => 'All Season'],
                'spring_autumn' => ['value' => 'Spring/Autumn'],
                'summer' => ['value' => 'Summer'],
                'winter' => ['value' => 'Winter'],
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
