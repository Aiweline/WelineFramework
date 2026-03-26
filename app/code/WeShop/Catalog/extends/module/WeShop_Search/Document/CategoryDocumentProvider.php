<?php

declare(strict_types=1);

namespace WeShop\Catalog\Extends\Module\WeShop_Search\Document;

use WeShop\Catalog\Model\Category;
use WeShop\Search\Api\SearchDocumentProviderInterface;

class CategoryDocumentProvider implements SearchDocumentProviderInterface
{
    public function __construct(
        private readonly Category $categoryModel
    ) {
    }

    public function getProviderCode(): string
    {
        return 'category';
    }

    public function getDocumentType(): string
    {
        return 'category';
    }

    public function getBatchDocuments(int $page = 1, int $pageSize = 100): array
    {
        $category = clone $this->categoryModel;
        $category->clear()->pagination(max(1, $page), max(1, $pageSize));

        $documents = [];
        foreach ($category->select()->fetchArray() as $item) {
            $document = $this->buildDocument($item);
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function getDocumentByEntityId(int|string $entityId): ?array
    {
        $categoryId = (int) $entityId;
        if ($categoryId <= 0) {
            return null;
        }

        $category = clone $this->categoryModel;
        $category->load($categoryId);
        if (!$category->getId()) {
            return null;
        }

        return $this->buildDocument($category->getData());
    }

    public function getDocumentId(int|string $entityId): string
    {
        return 'category_' . (int) $entityId;
    }

    public function getIndexConfiguration(): array
    {
        return [
            'searchable_fields' => [
                'name',
                'handle',
                'description',
                'searchable_text',
            ],
            'filterable_fields' => [
                'document_type',
                'status',
                'parent_id',
            ],
            'sortable_fields' => [
                'name',
                'entity_id',
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'category',
            'document_type' => 'category',
            'module' => 'WeShop_Catalog',
            'description' => __('提供分类搜索文档（分类名、handle、描述等）'),
        ];
    }

    /**
     * @param array<string, mixed> $category
     * @return array<string, mixed>|null
     */
    private function buildDocument(array $category): ?array
    {
        $categoryId = (int) ($category[Category::schema_fields_ID] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        $name = trim((string) ($category[Category::schema_fields_NAME] ?? ''));
        $description = trim(strip_tags((string) ($category[Category::schema_fields_DESCRIPTION] ?? '')));
        $handle = trim((string) ($category[Category::schema_fields_HANDLE] ?? ''));

        return [
            'document_id' => $this->getDocumentId($categoryId),
            'document_type' => 'category',
            'entity_id' => $categoryId,
            'category_id' => $categoryId,
            'parent_id' => (int) ($category[Category::schema_fields_PARENT_ID] ?? 0),
            'name' => $name,
            'handle' => $handle,
            'description' => $description,
            'image' => (string) ($category[Category::schema_fields_IMAGE] ?? ''),
            'status' => (int) ($category[Category::schema_fields_IS_ACTIVE] ?? 0),
            'url' => '/catalog/category/view?id=' . $categoryId,
            'searchable_text' => implode(' ', array_filter([$name, $handle, $description])),
        ];
    }
}
