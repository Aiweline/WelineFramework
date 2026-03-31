<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Provider\BrandFilterProvider;
use WeShop\Filters\Provider\ColorFilterProvider;

class EavFacetFallbackTest extends TestCase
{
    public function testBrandFacetDefinitionFallsBackWhenAttributeMetadataIsUnavailable(): void
    {
        $provider = new class() extends BrandFilterProvider {
            protected function getProductAttributeInfo(string $attributeCode): ?array
            {
                return null;
            }
        };

        $definition = $provider->getSearchFacetDefinition(14);

        $this->assertIsArray($definition);
        $this->assertSame('brand', $definition['code'] ?? null);
        $this->assertSame('brand', $definition['attribute_code'] ?? null);
        $this->assertSame('eav', $definition['type'] ?? null);
        $this->assertSame('list', $definition['display_type'] ?? null);
        $this->assertSame(0, $definition['attribute_id'] ?? null);
    }

    public function testColorOptionsUseSearchBackedFallbackWhenAttributeMetadataIsUnavailable(): void
    {
        $provider = new class() extends ColorFilterProvider {
            protected function getProductAttributeInfo(string $attributeCode): ?array
            {
                return null;
            }

            protected function getSearchBackedOptionsFallback(int $categoryId, array $appliedFilters = []): array
            {
                return [[
                    'value' => 'gray',
                    'label' => 'Gray',
                    'count' => 1,
                    'selected' => false,
                    'swatch' => ['type' => 'color', 'value' => '#808080'],
                ]];
            }
        };

        $options = $provider->getOptions(14, [2]);

        $this->assertCount(1, $options);
        $this->assertSame('gray', $options[0]['value'] ?? null);
        $this->assertSame('Gray', $options[0]['label'] ?? null);
        $this->assertSame('color', $options[0]['swatch']['type'] ?? null);
    }
}
