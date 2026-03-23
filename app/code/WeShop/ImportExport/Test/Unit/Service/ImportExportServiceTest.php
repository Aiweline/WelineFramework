<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\ImportExport\Service\ImportExportService;
use WeShop\Order\Model\Order;
use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute\Set as AttributeSet;

class ImportExportServiceTest extends TestCase
{
    private string $exportDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exportDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weshop-importexport-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->exportDirectory)) {
            $files = glob($this->exportDirectory . DIRECTORY_SEPARATOR . '*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->exportDirectory);
        }

        parent::tearDown();
    }

    public function testExportProductsWritesCsvWithRequestedColumns(): void
    {
        $whereCalls = [];
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset', 'where', 'order', 'select', 'fetchArray'])
            ->getMock();
        $product->method('reset')->willReturnSelf();
        $product->method('where')->willReturnCallback(function (string $field, mixed $value, string $operator = '=') use (&$whereCalls, $product) {
            $whereCalls[] = [$field, $value, $operator];
            return $product;
        });
        $product->method('order')->willReturnSelf();
        $product->method('select')->willReturnSelf();
        $product->method('fetchArray')->willReturn([
            [
                Product::schema_fields_ID => 8,
                Product::schema_fields_sku => 'SKU-8',
                Product::schema_fields_spu => 'SPU-8',
                Product::schema_fields_name => 'Demo Product',
                Product::schema_fields_price => 99.5,
                Product::schema_fields_cost => 55.4,
                Product::schema_fields_stock => 12,
                Product::schema_fields_status => 1,
                Product::schema_fields_weight => 1.2,
                Product::schema_fields_short_description => 'Short',
                Product::schema_fields_description => 'Long',
                Product::schema_fields_image => 'cover.jpg',
                Product::schema_fields_images => '["cover.jpg"]',
                Product::schema_fields_parent_id => 0,
                Product::schema_fields_set_id => 3,
                Product::schema_fields_meta_name => 'Meta',
                Product::schema_fields_meta_description => 'Meta Desc',
                Product::schema_fields_meta_keywords => 'one,two',
                Product::schema_fields_HANDLE => 'demo-product',
            ],
        ]);

        $service = new ImportExportService(
            $product,
            $this->createStub(Order::class),
            $this->createStub(AttributeSet::class),
            $this->exportDirectory
        );

        $path = $service->exportProducts([
            'status' => 1,
            'sku' => 'SKU-8',
            'name' => 'Demo',
        ]);

        $this->assertFileExists($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);
        $header = ltrim((string) $lines[0], "\xEF\xBB\xBF");
        $this->assertSame(
            'product_id,sku,spu,name,price,cost,stock,status,weight,short_description,description,image,images,parent_id,set_id,meta_name,meta_description,meta_keywords,handle',
            $header
        );
        $this->assertStringContainsString('SKU-8', (string) $lines[1]);
        $this->assertContains([Product::schema_fields_status, 1, '='], $whereCalls);
        $this->assertContains([Product::schema_fields_sku, ['like', '%SKU-8%'], '='], $whereCalls);
        $this->assertContains([Product::schema_fields_name, ['like', '%Demo%'], '='], $whereCalls);
    }

    public function testImportProductsCreatesOrUpdatesRowsWithNormalizedDefaults(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'weshop-import-');
        $this->assertNotFalse($file);
        file_put_contents(
            $file,
            "sku,name,price,cost,stock,set_id\nSKU-NEW,New Product,19.95,9.10,7,4\n"
        );

        $savedData = [];
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getId', 'clearData', 'setData', 'save'])
            ->addMethods(['reset'])
            ->getMock();
        $product->method('reset')->willReturnSelf();
        $product->method('load')->willReturnSelf();
        $product->method('getId')->willReturn(0);
        $product->method('clearData')->willReturnSelf();
        $product->method('setData')->willReturnCallback(function (string $key, mixed $value) use (&$savedData, $product) {
            $savedData[$key] = $value;
            return $product;
        });
        $product->expects($this->once())->method('save')->willReturn(1);

        $service = new ImportExportService(
            $product,
            $this->createStub(Order::class),
            $this->createStub(AttributeSet::class),
            $this->exportDirectory
        );

        $result = $service->importProducts($file);

        @unlink($file);

        $this->assertSame(['success' => 1, 'failed' => 0, 'errors' => []], $result);
        $this->assertSame('SKU-NEW', $savedData[Product::schema_fields_sku] ?? null);
        $this->assertSame('SKU-NEW', $savedData[Product::schema_fields_spu] ?? null);
        $this->assertSame('New Product', $savedData[Product::schema_fields_meta_name] ?? null);
        $this->assertSame('New Product', $savedData[Product::schema_fields_short_description] ?? null);
        $this->assertSame('New Product', $savedData[Product::schema_fields_description] ?? null);
        $this->assertSame(4, $savedData[Product::schema_fields_set_id] ?? null);
        $this->assertSame(7, $savedData[Product::schema_fields_stock] ?? null);
        $this->assertSame(1, $savedData[Product::schema_fields_status] ?? null);
        $this->assertSame(0.0, $savedData[Product::schema_fields_weight] ?? null);
        $this->assertSame('', $savedData[Product::schema_fields_image] ?? null);
    }

    public function testExportOrdersWritesCsvWithOrderSummaryColumns(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset', 'where', 'order', 'select', 'fetchArray'])
            ->getMock();
        $order->method('reset')->willReturnSelf();
        $order->method('where')->willReturnSelf();
        $order->method('order')->willReturnSelf();
        $order->method('select')->willReturnSelf();
        $order->method('fetchArray')->willReturn([
            [
                Order::schema_fields_ID => 11,
                Order::schema_fields_increment_id => '202603230001',
                Order::schema_fields_customer_id => 5,
                Order::schema_fields_status => 'processing',
                Order::schema_fields_total => 288.8,
                Order::schema_fields_created_at => '2026-03-23 10:00:00',
                Order::schema_fields_updated_at => '2026-03-23 10:30:00',
            ],
        ]);

        $service = new ImportExportService(
            $this->createStub(Product::class),
            $order,
            $this->createStub(AttributeSet::class),
            $this->exportDirectory
        );

        $path = $service->exportOrders(['status' => 'processing']);

        $this->assertFileExists($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);
        $header = ltrim((string) $lines[0], "\xEF\xBB\xBF");
        $this->assertSame('order_id,increment_id,customer_id,status,total,created_at,updated_at', $header);
        $this->assertStringContainsString('202603230001', (string) $lines[1]);
    }
}
