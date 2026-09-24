<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductBrandAdminContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Missing file: ' . $relative);

        return $content;
    }

    public function testBrandShardEntityIsDeclared(): void
    {
        $key = $this->read('Model/ProductShardKey.php');
        $schema = $this->read('Service/ProductShardSchemaCatalog.php');
        $model = $this->read('Model/Shard/Brand.php');

        self::assertStringContainsString("'brand'", $key);
        self::assertStringContainsString("SCHEMA_VERSION = '4.10.0'", $schema);
        self::assertStringContainsString("'brand' => new TableSchema", $schema);
        self::assertStringContainsString("return 'brand';", $model);
        self::assertStringContainsString('schema_fields_CODE', $model);
        self::assertStringContainsString('STATUS_ACTIVE', $model);
    }

    public function testBrandAdminSurfaceExposesCrudAndMenu(): void
    {
        $menu = $this->read('etc/backend/menu.xml');
        $controller = $this->read('Controller/Backend/Catalog.php');
        $template = $this->read('view/templates/backend/catalog/brands.phtml');
        $service = $this->read('Service/ProductBrandAdminService.php');

        self::assertStringContainsString('commerce:catalog:brands', $menu);
        self::assertStringContainsString('backend/catalog/brands', $menu);
        self::assertStringContainsString('function brands()', $controller);
        self::assertStringContainsString('function postSaveBrand()', $controller);
        self::assertStringContainsString('function postDisableBrand()', $controller);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-brand')\"", $template);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-brand')\"", $template);
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertStringContainsString("@backend-url('*/backend/catalog/brands')", $template);
        self::assertStringNotContainsString('@backend-url(*/backend/catalog/', $template);
        self::assertStringContainsString('<w:d-table', $template);
        self::assertStringContainsString('mode="local"', $template);
        self::assertStringContainsString('local-data-el="#product-brand-local-rows"', $template);
        self::assertStringContainsString('model="Weline\Product\Model\Shard\Brand"', $template);
        self::assertStringContainsString('name="logo_url" type="image"', $template);
        self::assertStringContainsString('product-brand-disable-form', $template);
        self::assertStringContainsString('weline:datatable:row-action', $template);
        self::assertStringNotContainsString('<table class="w-table" data-testid="product-brand-table">', $template);
        self::assertStringContainsString('catalogOptions', $service);
        self::assertStringContainsString('resolveForProduct', $service);
        self::assertStringContainsString('name="supplier_ids"', $template);
        self::assertStringContainsString('w:product:catalog:select', $template);
        self::assertStringContainsString('multiple="true"', $template);
        self::assertStringContainsString('data-testid="product-brand-suppliers"', $template);
        self::assertStringContainsString("'image' => (string)(\$option['image_url'] ?? '')", $template);
        self::assertStringNotContainsString('name="supplier_ids[]"', $template);
        self::assertStringContainsString('data-testid="product-brand-logo"', $template);
        self::assertStringContainsString('data-media-identity-root="product_brand"', $template);
        self::assertStringContainsString('data-media-identity-scope=', $template);
        self::assertStringContainsString('identity_root=product_brand', $template);
        self::assertStringContainsString('media-identity-picker.js', $template);
        self::assertStringContainsString('replaceSuppliersForBrand', $this->read('Repository/SupplierBrandRepository.php'));
        $taglib = $this->read('Taglib/CatalogEntitySelect.php');
        self::assertStringContainsString('weline-product-catalog-select-chip-thumb', $taglib);
        self::assertStringContainsString('displayableImageUrl', $taglib);
    }

    public function testSaveBrandCsrfFieldAlignsWithWformAuto(): void
    {
        $controller = $this->read('Controller/Backend/Catalog.php');
        $template = $this->read('view/templates/backend/catalog/brands.phtml');

        self::assertMatchesRegularExpression(
            '/protected function csrf\(\): string\s*\{\s*return \'csrf\';/s',
            $controller
        );
        self::assertStringNotContainsString('FormKey::key_name', $controller);
        self::assertStringNotContainsString("return 'form_key';", $controller);
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertStringContainsString("'Weline_Product::commerce:catalog:brands:save'", $controller);
        self::assertStringContainsString("'Weline_Product::commerce:catalog:brands:disable'", $controller);
    }

    public function testCreateWizardSelectsBrandFromCatalog(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');
        $script = $this->read('view/statics/js/backend/product-admin.js');
        $reader = $this->read('Service/ProductAdminReadService.php');
        $command = $this->read('Service/ProductAdminCommandService.php');

        self::assertStringContainsString("id=\"product-create-brand\"", $index);
        self::assertStringContainsString('name="brand_id"', $index);
        self::assertStringContainsString('$createBrands', $index);
        self::assertStringContainsString("'brands' => \$this->brandAdmin->catalogOptions", $reader);
        self::assertStringContainsString('payload.brand_id = brandId', $script);
        self::assertStringContainsString('payloadWithResolvedBrand', $command);
        self::assertStringContainsString("'brand_code'", $command);
        self::assertStringContainsString('brand: true', $script);
        self::assertStringContainsString('brand_code: true', $script);
        self::assertMatchesRegularExpression(
            '/CREATE_EAV_SKIP_CODES\s*=\s*\{[^}]*\bbrand\s*:/',
            $script,
        );

        $bootstrap = $this->read('Service/ProductCatalogEavBootstrap.php');
        self::assertStringContainsString('DEDICATED_IDENTITY_ATTRIBUTE_CODES', $bootstrap);
        self::assertStringContainsString('detachDedicatedIdentityAttributesFromSets', $bootstrap);
        self::assertStringNotContainsString("['code' => 'brand', 'name' => '品牌']", $bootstrap);
    }
}
