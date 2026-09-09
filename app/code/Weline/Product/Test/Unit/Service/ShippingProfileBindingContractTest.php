<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Service\ProductShardSchemaCatalog;

final class ShippingProfileBindingContractTest extends TestCase
{
    public function testOfferSchemaIncludesShippingProfileCode(): void
    {
        self::assertSame('4.7.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
        self::assertSame('shipping_profile_code', Offer::schema_fields_SHIPPING_PROFILE_CODE);
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
        self::assertContains('shipping_profile_code', $cols);
    }

    public function testAdminAndPdpSurfacesExist(): void
    {
        $create = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/catalog/index.phtml',
        );
        $edit = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/catalog/edit.phtml',
        );
        $pdp = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml',
        );
        $cmd = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminCommandService.php',
        );
        $snap = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php',
        );
        self::assertStringContainsString('product-create-shipping-profile', $create);
        self::assertStringContainsString('配送方案', $create);
        self::assertStringContainsString('product-edit-shipping-profile', $edit);
        self::assertStringContainsString('product-shipping-hint', $pdp);
        self::assertStringContainsString('previewHint', $pdp);
        self::assertStringContainsString('normalizeShippingProfileCode', $cmd);
        self::assertStringContainsString('shipping_profile_code', $snap);
    }

    public function testSpiRegistryIsEmptySafe(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/Service/Storefront/StorefrontShippingProfileCatalogProviderRegistry.php',
        );
        self::assertStringContainsString('forTesting', $src);
        self::assertStringContainsString('?StorefrontShippingProfileCatalogProviderInterface', $src);
    }
}
