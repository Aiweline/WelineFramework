<?php

declare(strict_types=1);

namespace WeShop\Catalog\Extends\Module\Weline_FakeData\Provider;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Model\ProductCategory;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

class CatalogProvider implements FakeDataProviderInterface
{
    private const CODE = 'weshop_catalog';
    private const ENTITY_CATEGORY = 'category';
    public const EMPTY_CATEGORY_HANDLE = 'fake-empty-category';

    public function __construct(
        private readonly Category $category,
        private readonly ProductCategory $productCategory,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getModuleName(): string
    {
        return 'WeShop_Catalog';
    }

    public function getLabel(): string
    {
        return 'WeShop demo categories';
    }

    public function getSortOrder(): int
    {
        return 100;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function describe(): array
    {
        return [
            'entities' => [self::ENTITY_CATEGORY],
            'count' => count($this->getCategories()),
        ];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $createdByHandle = [];
        $categories = $this->applyLimit($this->getCategories(), $context->getLimit());

        foreach ($categories as $categoryData) {
            $parentHandle = (string)($categoryData['parent_handle'] ?? '');
            $parentId = $parentHandle !== '' ? (int)($createdByHandle[$parentHandle] ?? $this->getCategoryIdByHandle($parentHandle)) : 0;
            if ($parentHandle !== '' && $parentId === 0) {
                $result->addWarning((string)__('Skipped category %{1}: missing parent %{2}', [$categoryData['handle'], $parentHandle]));
                $result->addSkipped();
                continue;
            }

            $existingId = $this->getCategoryIdByHandle((string)$categoryData['handle']);
            $this->category->clear()
                ->setData([
                    Category::schema_fields_PARENT_ID => $parentId,
                    Category::schema_fields_NAME => (string)$categoryData['name'],
                    Category::schema_fields_HANDLE => (string)$categoryData['handle'],
                    Category::schema_fields_DESCRIPTION => (string)$categoryData['description'],
                    Category::schema_fields_IMAGE => (string)$categoryData['image'],
                    Category::schema_fields_SORT_ORDER => (int)$categoryData['sort_order'],
                    Category::schema_fields_IS_ACTIVE => 1,
                    Category::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                    Category::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->forceCheck(true, [Category::schema_fields_HANDLE])
                ->save();

            $categoryId = $this->getCategoryIdByHandle((string)$categoryData['handle']);
            if ($categoryId === 0) {
                $result->addError((string)__('Failed to resolve category after save: %{1}', [$categoryData['handle']]));
                continue;
            }

            $createdByHandle[(string)$categoryData['handle']] = $categoryId;
            $context->record(
                self::CODE,
                self::ENTITY_CATEGORY,
                $categoryId,
                'category:' . (string)$categoryData['handle'],
                ['handle' => (string)$categoryData['handle']]
            );

            $existingId > 0 ? $result->addUpdated() : $result->addCreated();
        }

        $result->merge($this->backfillExistingCategoryImages());

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $records = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_CATEGORY);

        foreach ($records as $record) {
            $categoryId = (int)($record['entity_id'] ?? 0);
            $stableKey = (string)($record['stable_key'] ?? '');
            if ($categoryId > 0) {
                $this->productCategory->clear()
                    ->getQuery()
                    ->where(ProductCategory::schema_fields_category_id, $categoryId)
                    ->delete()
                    ->fetch();
                $this->category->clear()
                    ->getQuery()
                    ->where(Category::schema_fields_ID, $categoryId)
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
    private function getCategories(): array
    {
        return [
            [
                'handle' => 'fake-electronics',
                'name' => 'Consumer Electronics',
                'description' => 'Phones, audio, wearables, and smart-device demo products.',
                'image' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 10,
            ],
            [
                'handle' => 'fake-apparel',
                'name' => 'Everyday Apparel',
                'description' => 'Casual clothing, sneakers, and daily-wear demo products.',
                'image' => 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 20,
            ],
            [
                'handle' => 'fake-home',
                'name' => 'Home Living',
                'description' => 'Furniture, lighting, and home-lifestyle demo products.',
                'image' => 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 30,
            ],
            [
                'handle' => 'fake-smart-devices',
                'parent_handle' => 'fake-electronics',
                'name' => 'Smart Devices',
                'description' => 'Wearables, smart accessories, and connected devices.',
                'image' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 11,
            ],
            [
                'handle' => 'fake-daily-wear',
                'parent_handle' => 'fake-apparel',
                'name' => 'Daily Wear',
                'description' => 'Comfortable everyday tops, shoes, and accessories.',
                'image' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 21,
            ],
            [
                'handle' => 'fake-living-space',
                'parent_handle' => 'fake-home',
                'name' => 'Living Space',
                'description' => 'Living-room furniture, tables, seating, and decor.',
                'image' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 31,
            ],
            [
                'handle' => self::EMPTY_CATEGORY_HANDLE,
                'name' => 'Empty Category Demo',
                'description' => 'A deliberate empty category used to verify storefront empty-state behavior.',
                'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80',
                'sort_order' => 99,
            ],
        ];
    }

    private function backfillExistingCategoryImages(): FakeDataResult
    {
        $result = new FakeDataResult();
        $pdo = $this->category->getConnection()->getConnector()->getLink();
        $sql = 'UPDATE "m_weshop_category" SET "image" = :image, "updated_at" = :updated_at WHERE "category_id" = :category_id AND ("image" IS NULL OR "image" = \'\')';

        foreach ($this->getExistingCategoryImageMap() as $handle => $image) {
            $row = $this->category->clear()
                ->where(Category::schema_fields_HANDLE, $handle)
                ->find()
                ->fetch();
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            $currentImage = trim((string)($row[Category::schema_fields_IMAGE] ?? ''));
            if ($categoryId <= 0 || $currentImage !== '') {
                continue;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':image' => $image,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':category_id' => $categoryId,
            ]);
            if ($stmt->rowCount() > 0) {
                $result->addUpdated();
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function getExistingCategoryImageMap(): array
    {
        return [
            'electronics' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1200&q=80',
            'phones' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=80',
            'computers' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=1200&q=80',
            'smart-devices' => 'https://images.unsplash.com/photo-1551816230-ef5deaed4a26?auto=format&fit=crop&w=1200&q=80',
            'audio-video' => 'https://images.unsplash.com/photo-1545454675-3531b543be5d?auto=format&fit=crop&w=1200&q=80',
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
