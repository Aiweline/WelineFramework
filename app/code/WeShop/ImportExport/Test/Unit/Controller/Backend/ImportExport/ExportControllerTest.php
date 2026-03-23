<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Test\Unit\Controller\Backend\ImportExport;

use PHPUnit\Framework\TestCase;
use WeShop\ImportExport\Controller\Backend\ImportExport\Export;
use WeShop\ImportExport\Service\ImportExportService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class ExportControllerTest extends TestCase
{
    public function testIndexStreamsProductExportFileToResponse(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'weshop-export-');
        $this->assertNotFalse($file);
        file_put_contents($file, "sku,name\nSKU-1,Demo\n");

        $service = $this->createMock(ImportExportService::class);
        $service->expects($this->once())
            ->method('exportProducts')
            ->with(['status' => '1'])
            ->willReturn($file);

        $response = $this->createMock(Response::class);
        $response->expects($this->exactly(3))
            ->method('setHeader')
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setBody')
            ->with("sku,name\nSKU-1,Demo\n")
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null) {
            return match ($key) {
                'entity' => 'products',
                'status' => '1',
                default => $default,
            };
        });
        $request->method('getResponse')->willReturn($response);

        $controller = $this->getMockBuilder(Export::class)
            ->setConstructorArgs([$service])
            ->onlyMethods(['exception'])
            ->getMock();
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame("sku,name\nSKU-1,Demo\n", $controller->index());

        @unlink($file);
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
