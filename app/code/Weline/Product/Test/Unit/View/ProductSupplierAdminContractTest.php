<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductSupplierAdminContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Missing file: ' . $relative);

        return $content;
    }

    public function testSupplierShardEntitiesAreDeclared(): void
    {
        $key = $this->read('Model/ProductShardKey.php');
        $schema = $this->read('Service/ProductShardSchemaCatalog.php');
        $supplier = $this->read('Model/Shard/Supplier.php');
        $link = $this->read('Model/Shard/ProductSupplier.php');

        self::assertStringContainsString("'supplier'", $key);
        self::assertStringContainsString("'product_supplier'", $key);
        self::assertStringContainsString("SCHEMA_VERSION = '4.10.0'", $schema);
        self::assertStringContainsString("'supplier' => new TableSchema", $schema);
        self::assertStringContainsString("'product_supplier' => new TableSchema", $schema);
        self::assertStringContainsString("'supplier_brand' => new TableSchema", $schema);
        self::assertStringContainsString('store_url', $schema);
        self::assertStringContainsString('supplier_product_url', $schema);
        self::assertStringContainsString('unit_price_minor', $schema);
        self::assertStringContainsString("return 'supplier';", $supplier);
        self::assertStringContainsString("return 'product_supplier';", $link);
    }

    public function testSupplierAdminSurfaceExposesCrudAndMenu(): void
    {
        $menu = $this->read('etc/backend/menu.xml');
        $controller = $this->read('Controller/Backend/Catalog.php');
        $template = $this->read('view/templates/backend/catalog/suppliers.phtml');
        $service = $this->read('Service/ProductSupplierAdminService.php');

        self::assertStringContainsString('commerce:catalog:suppliers', $menu);
        self::assertStringContainsString('backend/catalog/suppliers', $menu);
        self::assertStringContainsString('function suppliers()', $controller);
        self::assertStringContainsString('function postSaveSupplier()', $controller);
        self::assertStringContainsString('function postDisableSupplier()', $controller);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-supplier')\"", $template);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-supplier')\"", $template);
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertStringContainsString('name="store_url"', $template);
        self::assertStringContainsString('<w:d-table', $template);
        self::assertStringContainsString('mode="local"', $template);
        self::assertStringContainsString('local-data-el="#product-supplier-local-rows"', $template);
        self::assertStringContainsString('model="Weline\Product\Model\Shard\Supplier"', $template);
        self::assertStringContainsString('name="image_url" type="image"', $template);
        self::assertStringContainsString('product-supplier-disable-form', $template);
        self::assertStringContainsString('weline:datatable:row-action', $template);
        self::assertStringNotContainsString('<table class="w-table" data-testid="product-supplier-table">', $template);
        self::assertStringContainsString('catalogOptions', $service);
        self::assertStringContainsString('upsertPrimaryFromPayload', $service);
        self::assertStringContainsString('replaceBrandsForSupplier', $this->read('Repository/SupplierBrandRepository.php'));
        self::assertStringContainsString('name="brand_ids"', $template);
        self::assertStringContainsString('w:product:catalog:select', $template);
        self::assertStringContainsString('multiple="true"', $template);
        self::assertStringContainsString('data-testid="product-supplier-brands"', $template);
        self::assertStringContainsString("'image' => (string)(\$option['logo_url'] ?? '')", $template);
        self::assertStringContainsString("'image_url' => (string)(\$row['image_url'] ?? '')", $service);
        self::assertStringNotContainsString('name="brand_ids[]"', $template);
        self::assertStringContainsString('data-testid="product-supplier-image"', $template);
        self::assertStringContainsString('data-media-identity-root="product_supplier"', $template);
        self::assertStringContainsString('data-media-identity-scope=', $template);
        self::assertStringContainsString('identity_root=product_supplier', $template);
        self::assertStringContainsString('media-identity-picker.js', $template);
    }

    public function testCreateWizardSelectsSupplierFromCatalog(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');
        $script = $this->read('view/statics/js/backend/product-admin.js');
        $reader = $this->read('Service/ProductAdminReadService.php');
        $command = $this->read('Service/ProductAdminCommandService.php');

        self::assertStringContainsString('id="product-create-supplier"', $index);
        self::assertStringContainsString('name="supplier_id"', $index);
        self::assertStringContainsString('id="product-create-brand"', $index);
        self::assertStringContainsString('name="brand_id"', $index);
        self::assertStringContainsString('w:product:catalog:select', $index);
        self::assertStringContainsString('product-create-supplier-brand-map', $index);
        self::assertStringContainsString("'image' => (string)(\$supplierOption['image_url'] ?? '')", $index);
        self::assertStringContainsString('syncCreateSupplierBrandCascade', $script);
        self::assertStringContainsString('product-create-supplier-offer', $index);
        self::assertStringContainsString('name="supplier_product_url"', $index);
        self::assertStringContainsString('$createSuppliers', $index);
        self::assertStringContainsString("'suppliers' => \$this->supplierAdmin->catalogOptions", $reader);
        self::assertStringContainsString('payload.supplier_id = supplierId', $script);
        self::assertStringContainsString('bindCreateSupplierOfferPanel', $script);
        self::assertStringContainsString('upsertPrimaryFromPayload', $command);
    }
}
