<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Service\ProductShardSchemaCatalog;

final class OfferTaxClassBindingContractTest extends TestCase
{
    public function testOfferSchemaIncludesTaxClassCode(): void
    {
        self::assertSame('4.13.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
        self::assertSame('tax_class_code', Offer::schema_fields_TAX_CLASS_CODE);
        $catalog = new ProductShardSchemaCatalog();
        $schemas = $catalog->schemasForShard('0');
        $offer = null;
        foreach ($schemas as $schema) {
            if (str_ends_with($schema->tableName, '_offer')) {
                $offer = $schema;
                break;
            }
        }
        self::assertNotNull($offer);
        $cols = array_map(static fn($c) => $c->name, $offer->columns);
        self::assertContains('tax_class_code', $cols);
    }

    public function testAdminAndCartSurfacesWireTaxClass(): void
    {
        $edit = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/catalog/edit.phtml',
        );
        $create = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/catalog/index.phtml',
        );
        $cmd = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminCommandService.php',
        );
        $snap = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php',
        );
        self::assertStringContainsString('product-edit-tax-class', $edit);
        self::assertStringContainsString('name="tax_class_code"', $edit);
        self::assertStringContainsString('销售税税类', $edit);
        self::assertStringContainsString('product-create-tax-class', $create);
        self::assertStringContainsString('normalizeTaxClassCode', $cmd);
        self::assertStringContainsString('writeOfferTaxClassCode', $cmd);
        self::assertStringContainsString('TAX_CLASS_CODE', $snap);
        self::assertStringContainsString('taxClassCode:', $snap);
    }
}
