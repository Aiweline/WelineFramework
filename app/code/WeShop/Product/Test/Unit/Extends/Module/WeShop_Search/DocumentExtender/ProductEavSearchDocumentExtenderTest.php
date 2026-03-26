<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Extends\Module\WeShop_Search\DocumentExtender;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Extends\Module\WeShop_Search\DocumentExtender\ProductEavSearchDocumentExtender;
use WeShop\Product\Service\ProductEavService;

class ProductEavSearchDocumentExtenderTest extends TestCase
{
    public function testExtendDocumentAppendsSearchTextAndFacets(): void
    {
        $productEavService = $this->createMock(ProductEavService::class);
        $productEavService->expects($this->once())
            ->method('getSearchIndexData')
            ->with(15)
            ->willReturn([
                'eav_search_text' => ['Color Red', 'Material Leather'],
                'eav_facets' => [
                    [
                        'attribute_code' => 'color',
                        'value_keyword' => 'red',
                        'value_text' => 'Red',
                    ],
                ],
            ]);

        $extender = new ProductEavSearchDocumentExtender($productEavService);
        $document = $extender->extendDocument([
            'product_id' => 15,
            'searchable_text' => 'Travel Bag',
        ]);

        $this->assertSame('Color Red Material Leather', $document['eav_search_text']);
        $this->assertSame('Travel Bag Color Red Material Leather', $document['searchable_text']);
        $this->assertSame('red', $document['eav_facets'][0]['value_keyword']);
    }

    public function testGetIndexConfigurationExposesDynamicEavFields(): void
    {
        $extender = new ProductEavSearchDocumentExtender($this->createMock(ProductEavService::class));
        $configuration = $extender->getIndexConfiguration();

        $this->assertSame(['eav_search_text'], $configuration['searchable_fields']);
        $this->assertContains('eav_facets.attribute_code', $configuration['filterable_fields']);
        $this->assertContains('eav_facets.value_keyword', $configuration['filterable_fields']);
        $this->assertContains('eav_facets.value_number', $configuration['filterable_fields']);
    }
}
