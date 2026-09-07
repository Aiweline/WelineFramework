<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductAttributeMetadataCatalog;
use Weline\Product\Service\ProductCatalogEavBootstrap;

final class ProductScopedOptionContractTest extends TestCase
{
    public function testMetadataCatalogEnsuresScopedOptionsApi(): void
    {
        self::assertTrue(method_exists(
            ProductAttributeMetadataCatalog::class,
            'ensureAndCanonicalizeVariantAxes',
        ));
        self::assertTrue(method_exists(
            ProductAttributeMetadataCatalog::class,
            'ensureVariantAxisOptions',
        ));
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAttributeMetadataCatalog.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('AttributeOptionStoreInterface', $source);
        self::assertStringContainsString('ensureInScope(', $source);
        self::assertStringContainsString('metadataIndex($productId)', $source);
    }

    public function testCommandServiceCreatesConfigurableAfterProductId(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminCommandService.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('ensureAndCanonicalizeVariantAxes', $source);
        self::assertStringContainsString('canonicalizeOfferMatrix($matrixPayload, $productId)', $source);
        self::assertStringContainsString('normalizeRows($rows, $productId)', $source);
    }

    public function testSearchProjectionIndexesPrivateOptionLabels(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductSearchProjectionService.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('collectPrivateOptionLabelsByProduct', $source);
        self::assertStringContainsString(
            'Option::schema_fields_scope_instance_id',
            $source,
        );
    }

    public function testBootstrapImportDoesNotPersistProductOptionsGlobally(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCatalogEavBootstrap.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('bool $persistOptions = true', $source);
        self::assertStringContainsString(
            'Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED',
            $source,
        );
        self::assertTrue(class_exists(ProductCatalogEavBootstrap::class));
    }

    public function testImportAllowsUnresolvedOptionsForPrivateEnsure(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/scripts/import-1688-hanfu-catalog.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            'Instance-private options are materialized during Product create/save',
            $source,
        );
        self::assertStringContainsString(
            'ensured as instance-private rows on Product create/save',
            $source,
        );
    }

    public function testSingletonColorRemediationScriptExists(): void
    {
        $path = dirname(__DIR__, 3) . '/scripts/remediate-singleton-color-options-to-private.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('scope_instance_id', $source);
        self::assertStringContainsString('attribute_code', $source);
        self::assertStringContainsString('--dry-run', $source);
        self::assertStringContainsString('--apply', $source);
    }

    public function testSpecialAxisPrivatizationScriptExists(): void
    {
        $path = dirname(__DIR__, 3) . '/scripts/remediate-special-axis-options-to-private.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('character', $source);
        self::assertStringContainsString('look_ref', $source);
        self::assertStringContainsString('style_type', $source);
        self::assertStringContainsString('combination_key', $source);
        self::assertStringContainsString('scope_instance_id', $source);
        self::assertStringContainsString('--dry-run', $source);
        self::assertStringContainsString('--apply', $source);
    }

    public function testMisfiledColorToAxesScriptExists(): void
    {
        $path = dirname(__DIR__, 3) . '/scripts/remediate-misfiled-color-options-to-axes.php';
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('ColorAxisClassifier', $source);
        self::assertStringContainsString("attrIds['prop']", $source);
        self::assertStringContainsString('combination_key', $source);
        self::assertStringContainsString('--dry-run', $source);
        self::assertStringContainsString('--apply', $source);
        self::assertStringContainsString('targetAxes', $source);
    }
}
