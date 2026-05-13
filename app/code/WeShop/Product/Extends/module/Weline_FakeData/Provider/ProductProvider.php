<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_FakeData\Provider;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

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
            $existingId = $this->getProductIdBySku((string)$productData['sku']);
            $this->product->clear()
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
                ->forceCheck(true, [Product::schema_fields_sku])
                ->save();

            $productId = $this->getProductIdBySku((string)$productData['sku']);
            if ($productId === 0) {
                $result->addError((string)__('Failed to resolve product after save: %{1}', [$productData['sku']]));
                continue;
            }

            $this->syncCategories($productId, $productData['category_handles'] ?? []);
            $context->record(
                self::CODE,
                self::ENTITY_PRODUCT,
                $productId,
                'product:' . (string)$productData['sku'],
                ['sku' => (string)$productData['sku'], 'handle' => (string)$productData['handle']]
            );

            $existingId > 0 ? $result->addUpdated() : $result->addCreated();
        }

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
        return [
            [
                'sku' => 'FAKE-SMART-BAND-001',
                'spu' => 'FAKE-SMART-BAND',
                'handle' => 'fake-smart-band',
                'name' => 'Fake Smart Band',
                'short_description' => 'Development smart band product.',
                'description' => 'A reusable fake product for storefront and checkout development.',
                'price' => 129.00,
                'cost' => 59.00,
                'stock' => 120,
                'weight' => 1,
                'image' => '/media/fake-data/product-smart-band.jpg',
                'images' => '[]',
                'meta_keywords' => 'fake,smart,band',
                'category_handles' => ['fake-electronics', 'fake-smart-devices'],
            ],
            [
                'sku' => 'FAKE-WIRELESS-LAMP-001',
                'spu' => 'FAKE-WIRELESS-LAMP',
                'handle' => 'fake-wireless-lamp',
                'name' => 'Fake Wireless Lamp',
                'short_description' => 'Development wireless lamp product.',
                'description' => 'A home product used for layout and cart development data.',
                'price' => 89.00,
                'cost' => 35.00,
                'stock' => 80,
                'weight' => 2,
                'image' => '/media/fake-data/product-wireless-lamp.jpg',
                'images' => '[]',
                'meta_keywords' => 'fake,home,lamp',
                'category_handles' => ['fake-home'],
            ],
            [
                'sku' => 'FAKE-DAILY-HOODIE-001',
                'spu' => 'FAKE-DAILY-HOODIE',
                'handle' => 'fake-daily-hoodie',
                'name' => 'Fake Daily Hoodie',
                'short_description' => 'Development apparel product.',
                'description' => 'A daily wear product used for storefront listing development.',
                'price' => 199.00,
                'cost' => 82.00,
                'stock' => 60,
                'weight' => 1,
                'image' => '/media/fake-data/product-hoodie.jpg',
                'images' => '[]',
                'meta_keywords' => 'fake,apparel,hoodie',
                'category_handles' => ['fake-apparel', 'fake-daily-wear'],
            ],
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
