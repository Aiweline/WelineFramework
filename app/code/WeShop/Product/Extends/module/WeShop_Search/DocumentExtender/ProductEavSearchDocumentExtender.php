<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\WeShop_Search\DocumentExtender;

use WeShop\Product\Service\ProductEavService;
use WeShop\Search\Api\SearchDocumentExtenderInterface;

class ProductEavSearchDocumentExtender implements SearchDocumentExtenderInterface
{
    public function __construct(
        private readonly ProductEavService $productEavService
    ) {
    }

    public function getTargetProviderCode(): string
    {
        return 'product';
    }

    public function extendDocument(array $document, array $context = []): array
    {
        $productId = (int) ($document['product_id'] ?? $document['entity_id'] ?? 0);
        if ($productId <= 0) {
            return $document;
        }

        $indexData = $this->productEavService->getSearchIndexData($productId);
        $searchText = implode(' ', $indexData['eav_search_text'] ?? []);

        $document['eav_search_text'] = $searchText;
        $document['eav_facets'] = $indexData['eav_facets'] ?? [];

        $document['searchable_text'] = trim(implode(' ', array_filter([
            (string) ($document['searchable_text'] ?? ''),
            $searchText,
        ])));

        return $document;
    }

    public function getIndexConfiguration(): array
    {
        return [
            'searchable_fields' => [
                'eav_search_text',
            ],
            'filterable_fields' => [
                'eav_facets.attribute_code',
                'eav_facets.value_keyword',
                'eav_facets.value_number',
            ],
            'sortable_fields' => [],
        ];
    }
}
