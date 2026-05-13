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
                'name' => 'Fake Electronics',
                'description' => 'Development category for electronic demo products.',
                'image' => '/media/fake-data/category-electronics.jpg',
                'sort_order' => 10,
            ],
            [
                'handle' => 'fake-apparel',
                'name' => 'Fake Apparel',
                'description' => 'Development category for apparel demo products.',
                'image' => '/media/fake-data/category-apparel.jpg',
                'sort_order' => 20,
            ],
            [
                'handle' => 'fake-home',
                'name' => 'Fake Home',
                'description' => 'Development category for home and lifestyle demo products.',
                'image' => '/media/fake-data/category-home.jpg',
                'sort_order' => 30,
            ],
            [
                'handle' => 'fake-smart-devices',
                'parent_handle' => 'fake-electronics',
                'name' => 'Fake Smart Devices',
                'description' => 'Development subcategory for smart devices.',
                'image' => '/media/fake-data/category-smart-devices.jpg',
                'sort_order' => 11,
            ],
            [
                'handle' => 'fake-daily-wear',
                'parent_handle' => 'fake-apparel',
                'name' => 'Fake Daily Wear',
                'description' => 'Development subcategory for daily wear.',
                'image' => '/media/fake-data/category-daily-wear.jpg',
                'sort_order' => 21,
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
