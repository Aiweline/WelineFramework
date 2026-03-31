<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Console\Product\Import\ImportDefault;
use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

/**
 * Legacy EAV compatibility source.
 *
 * The current sample dataset still has usable product EAV value tables even in
 * environments where the attribute metadata tables were created with the wrong
 * schema. This service reconstructs a minimal attribute/option view from the
 * sample import definitions plus the persisted value rows so storefront filters
 * and search indexing can keep working.
 */
class ProductEavCompatibilityService
{
    /**
     * Canonical sample attribute ids observed in the live value tables.
     *
     * @var array<string, int>
     */
    private array $canonicalAttributeIds = [
        'color' => 5,
        'size' => 6,
        'material' => 7,
        'brand' => 8,
    ];

    /**
     * Duplicate legacy attribute ids that can appear in older datasets.
     *
     * @var array<int, string>
     */
    private array $attributeCodeById = [
        1 => 'color',
        2 => 'size',
        3 => 'material',
        4 => 'brand',
        5 => 'color',
        6 => 'size',
        7 => 'material',
        8 => 'brand',
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $attributeDefinitions = null;

    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $sampleProductsBySku = null;

    /**
     * @var array<int, array<string, array<string, mixed>>>|null
     */
    private ?array $optionLookupByAttributeId = null;

    public function __construct(
        private readonly Product $productModel,
        private readonly ImportDefault $importDefault
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttributeInfo(string $attributeCode): ?array
    {
        $attributeCode = trim($attributeCode);
        if ($attributeCode === '') {
            return null;
        }

        $definition = $this->getCompatibilityAttributeDefinitions()[$attributeCode] ?? null;
        if (!$definition) {
            return null;
        }

        return [
            'attribute_id' => (int) ($definition['attribute_id'] ?? 0),
            'type_code' => 'select_option',
            'has_option' => true,
            'name' => (string) ($definition['name'] ?? $attributeCode),
            'code' => $attributeCode,
            'is_multiple' => false,
            'frontend_is_filterable' => true,
            'frontend_is_searchable' => !empty($definition['searchable']),
            'frontend_is_visible' => true,
            'is_swatch' => ($definition['display_type'] ?? 'list') === 'swatch',
        ];
    }

    /**
     * @param array<int|string, mixed> $optionIds
     * @return array<string, array<string, mixed>>
     */
    public function getOptionLabelsByAttributeId(int $attributeId, array $optionIds): array
    {
        if ($attributeId <= 0 || $optionIds === []) {
            return [];
        }

        $lookup = $this->getOptionLookupByAttributeId()[$attributeId] ?? [];
        if ($lookup === []) {
            return [];
        }

        $result = [];
        foreach ($optionIds as $optionId) {
            $optionKey = (string) $optionId;
            if (!isset($lookup[$optionKey])) {
                continue;
            }
            $result[$optionKey] = $lookup[$optionKey];
        }

        return $result;
    }

    /**
     * @return array{eav_search_text:array<int, string>, eav_facets:array<int, array<string, mixed>>}
     */
    public function getSearchIndexData(int $productId): array
    {
        if ($productId <= 0) {
            return ['eav_search_text' => [], 'eav_facets' => []];
        }

        $rows = $this->fetchProductAttributeRows([$productId]);
        if ($rows === []) {
            return ['eav_search_text' => [], 'eav_facets' => []];
        }

        $definitions = $this->getCompatibilityAttributeDefinitions();
        $lookup = $this->getOptionLookupByAttributeId();
        $searchTexts = [];
        $facets = [];
        $seen = [];

        foreach ($rows as $row) {
            $attributeId = (int) ($row['attribute_id'] ?? 0);
            $optionId = (string) ($row['value'] ?? '');
            if ($attributeId <= 0 || $optionId === '') {
                continue;
            }

            $attributeCode = $this->getAttributeCodeById($attributeId);
            if ($attributeCode === null) {
                continue;
            }

            $definition = $definitions[$attributeCode] ?? null;
            $option = $lookup[$attributeId][$optionId] ?? null;
            if (!$definition || !$option) {
                continue;
            }

            if (!empty($definition['searchable'])) {
                $searchTexts[] = trim((string) ($definition['name'] ?? $attributeCode) . ' ' . (string) ($option['value'] ?? $optionId));
            }

            $facetKey = $attributeCode . ':' . $optionId;
            if (isset($seen[$facetKey])) {
                continue;
            }

            $facets[] = [
                'attribute_id' => (int) ($definition['attribute_id'] ?? $attributeId),
                'attribute_code' => $attributeCode,
                'attribute_label' => (string) ($definition['name'] ?? $attributeCode),
                'display_type' => (string) ($definition['display_type'] ?? 'list'),
                'has_option' => true,
                'is_multiple' => false,
                'value_keyword' => $optionId,
                'value_text' => (string) ($option['value'] ?? $optionId),
                'value_number' => is_numeric($optionId) ? (float) $optionId : null,
                'swatch_color' => $option['swatch_color'] ?? null,
                'swatch_image' => $option['swatch_image'] ?? null,
                'swatch_text' => $option['swatch_text'] ?? null,
            ];
            $seen[$facetKey] = true;
        }

        return [
            'eav_search_text' => array_values(array_filter(array_unique($searchTexts))),
            'eav_facets' => array_values($facets),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getCompatibilityAttributeDefinitions(): array
    {
        if ($this->attributeDefinitions !== null) {
            return $this->attributeDefinitions;
        }

        $baseDefinitions = $this->readImportDefaultAttributeDefinitions();
        $definitions = [];

        foreach ($this->canonicalAttributeIds as $attributeCode => $attributeId) {
            $base = $baseDefinitions[$attributeCode] ?? [];
            $definitions[$attributeCode] = [
                'attribute_id' => $attributeId,
                'name' => (string) ($base['name'] ?? ucfirst($attributeCode)),
                'display_type' => $attributeCode === 'color' ? 'swatch' : 'list',
                'searchable' => true,
                'options' => is_array($base['options'] ?? null) ? $base['options'] : [],
            ];
        }

        $this->attributeDefinitions = $definitions;

        return $this->attributeDefinitions;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getSampleProductsBySku(): array
    {
        if ($this->sampleProductsBySku !== null) {
            return $this->sampleProductsBySku;
        }

        $products = $this->readImportDefaultTestProducts();
        $bySku = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $sku = trim((string) ($product['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $attributes = [];
            foreach (['brand', 'color', 'material', 'size'] as $attributeCode) {
                if (!empty($product[$attributeCode])) {
                    $attributes[$attributeCode] = (string) $product[$attributeCode];
                }
            }

            if ($attributes !== []) {
                $bySku[$sku] = $attributes;
            }
        }

        $this->sampleProductsBySku = $bySku;

        return $this->sampleProductsBySku;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductAttributeRows(array $productIds = []): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));

        $pdo = $this->productModel->getConnection()->getConnector()->getLink();
        $sql = 'SELECT p.product_id, p.sku, v.attribute_id, v.value'
            . ' FROM "m_weshop_product" AS p'
            . ' INNER JOIN "m_eav_product_select_option" AS v ON v.entity_id = p.product_id';

        $params = [];
        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql .= ' WHERE p.product_id IN (' . $placeholders . ')';
            $params = $productIds;
        }

        $sql .= ' ORDER BY p.product_id, v.attribute_id';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function getOptionLookupByAttributeId(): array
    {
        if ($this->optionLookupByAttributeId !== null) {
            return $this->optionLookupByAttributeId;
        }

        $definitions = $this->getCompatibilityAttributeDefinitions();
        $sampleProductsBySku = $this->getSampleProductsBySku();
        $lookup = [];

        foreach ($this->fetchProductAttributeRows() as $row) {
            $attributeId = (int) ($row['attribute_id'] ?? 0);
            $optionId = (string) ($row['value'] ?? '');
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($attributeId <= 0 || $optionId === '' || $sku === '') {
                continue;
            }

            $attributeCode = $this->getAttributeCodeById($attributeId);
            if ($attributeCode === null) {
                continue;
            }

            $sampleProduct = $sampleProductsBySku[$sku] ?? null;
            $optionCode = $sampleProduct[$attributeCode] ?? null;
            if ($optionCode === null) {
                continue;
            }

            $optionDefinition = $definitions[$attributeCode]['options'][$optionCode] ?? null;
            if (!is_array($optionDefinition)) {
                continue;
            }

            $lookup[$attributeId][$optionId] = [
                'code' => $optionCode,
                'value' => (string) ($optionDefinition['value'] ?? $optionCode),
                'swatch_color' => $optionDefinition['swatch_color'] ?? null,
                'swatch_image' => $optionDefinition['swatch_image'] ?? null,
                'swatch_text' => $optionDefinition['swatch_text'] ?? null,
            ];
        }

        // Mirror canonical lookup to the duplicate legacy ids when both sets are in use.
        foreach ([1 => 5, 2 => 6, 3 => 7, 4 => 8] as $legacyId => $canonicalId) {
            if (!isset($lookup[$legacyId]) && isset($lookup[$canonicalId])) {
                $lookup[$legacyId] = $lookup[$canonicalId];
            }
            if (!isset($lookup[$canonicalId]) && isset($lookup[$legacyId])) {
                $lookup[$canonicalId] = $lookup[$legacyId];
            }
        }

        $this->optionLookupByAttributeId = $lookup;

        return $this->optionLookupByAttributeId;
    }

    private function getAttributeCodeById(int $attributeId): ?string
    {
        return $this->attributeCodeById[$attributeId] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readImportDefaultAttributeDefinitions(): array
    {
        try {
            $method = new \ReflectionMethod($this->importDefault, 'getAttributeDefinitions');
            $method->setAccessible(true);
            $definitions = $method->invoke($this->importDefault);
            return is_array($definitions) ? $definitions : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readImportDefaultTestProducts(): array
    {
        try {
            $method = new \ReflectionMethod($this->importDefault, 'getTestProducts');
            $method->setAccessible(true);
            $products = $method->invoke($this->importDefault);
            return is_array($products) ? $products : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
