<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Test\Unit\Controller\Backend\ImportExport;

use PHPUnit\Framework\TestCase;
use WeShop\ImportExport\Controller\Backend\ImportExport\Import;
use WeShop\ImportExport\Service\ImportExportService;
use Weline\Framework\Http\Request;

class ImportControllerTest extends TestCase
{
    public function testPostImportsUploadedCsvAndReturnsJsonSummary(): void
    {
        $service = $this->createMock(ImportExportService::class);
        $service->expects($this->once())
            ->method('importProducts')
            ->with('C:\\temp\\products.csv')
            ->willReturn([
                'success' => 3,
                'failed' => 1,
                'errors' => [['line' => 5, 'message' => 'missing sku']],
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback(static function (string $key, mixed $default = null) {
            return $key === 'entity' ? 'products' : $default;
        });
        $request->method('getFile')->willReturn([
            'tmp_name' => 'C:\\temp\\products.csv',
            'name' => 'products.csv',
        ]);

        $controller = $this->getMockBuilder(Import::class)
            ->setConstructorArgs([$service])
            ->onlyMethods(['fetchJson', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['code'] ?? null) === 200
                    && ($payload['data']['success'] ?? null) === 3
                    && ($payload['data']['failed'] ?? null) === 1;
            }))
            ->willReturn('import-json');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('import-json', $controller->post());
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
