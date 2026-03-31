<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Provider\BrandFilterProvider;
use WeShop\Filters\Provider\ColorFilterProvider;
use WeShop\Filters\Provider\MaterialFilterProvider;

class EavFilterProviderIntegrationTest extends TestCase
{
    public function testBrandProviderReturnsSearchFacetDefinitionForLiveProvider(): void
    {
        $provider = new BrandFilterProvider();

        $definition = $provider->getSearchFacetDefinition(14);

        $this->assertIsArray($definition);
        $this->assertSame('brand', $definition['code'] ?? null);
        $this->assertSame('eav', $definition['type'] ?? null);
        $this->assertSame('brand', $definition['attribute_code'] ?? null);
    }

    public function testColorProviderReturnsSearchFacetDefinitionForLiveProvider(): void
    {
        $provider = new ColorFilterProvider();

        $definition = $provider->getSearchFacetDefinition(14);

        $this->assertIsArray($definition);
        $this->assertSame('color', $definition['code'] ?? null);
        $this->assertSame('swatch', $definition['display_type'] ?? null);
        $this->assertSame('eav', $definition['type'] ?? null);
    }

    public function testMaterialProviderReturnsSearchFacetDefinitionForLiveProvider(): void
    {
        $provider = new MaterialFilterProvider();

        $definition = $provider->getSearchFacetDefinition(14);

        $this->assertIsArray($definition);
        $this->assertSame('material', $definition['code'] ?? null);
        $this->assertSame('eav', $definition['type'] ?? null);
        $this->assertSame('material', $definition['attribute_code'] ?? null);
    }
}
