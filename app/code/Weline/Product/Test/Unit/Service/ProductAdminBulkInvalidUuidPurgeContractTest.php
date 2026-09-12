<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Service\ProductAdminBulkService;

/**
 * Invalid UUID rows cannot enter ProductAdminCommand; archive bulk must
 * physically purge them when product_id is present.
 */
final class ProductAdminBulkInvalidUuidPurgeContractTest extends TestCase
{
    public function testBulkServicePurgesInvalidUuidViaPhysicalDelete(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminBulkService.php',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/backend/product-admin.js',
        );

        self::assertStringContainsString('isValidProductUuid', $source);
        self::assertStringContainsString('ProductPhysicalDeleteService', $source);
        self::assertStringContainsString('deleteByIds', $source);
        self::assertStringContainsString('ACTION_ARCHIVE', $source);
        self::assertMatchesRegularExpression(
            '/function bulkArchive\([\s\S]*?product_id:\s*item\.product_id/m',
            $js,
        );
    }

    public function testExecuteSkipsCommandForInvalidUuidAndRequiresProductId(): void
    {
        if (!function_exists('__')) {
            eval('function __(string $text, array $args = []): string { return $text; }');
        }

        $commands = $this->createMock(ProductAdminCommandInterface::class);
        $commands->expects(self::never())->method('execute');

        $service = new ProductAdminBulkService($commands, null);
        $result = $service->execute(
            0,
            'archive',
            hash('sha256', 'bulk-invalid-uuid-no-id'),
            [[
                'global_product_uuid' => 'e2e-delprod-1789143448634',
                'product_id' => 0,
                'expected_version' => 0,
                'local_version' => 1,
            ]],
        );

        self::assertTrue($result['success']);
        self::assertSame(0, $result['data']['succeeded']);
        self::assertSame(1, $result['data']['failed']);
        self::assertSame(
            'product_admin_product_uuid_invalid',
            $result['data']['items'][0]['error_code'],
        );
    }

    public function testExecuteStillDispatchesValidUuidToCommand(): void
    {
        if (!function_exists('__')) {
            eval('function __(string $text, array $args = []): string { return $text; }');
        }

        $commands = $this->createMock(ProductAdminCommandInterface::class);
        $commands->expects(self::once())
            ->method('execute')
            ->willReturn(ProductAdminResult::ok([], 'ok'));

        $service = new ProductAdminBulkService($commands, null);
        $result = $service->execute(
            0,
            'archive',
            hash('sha256', 'bulk-valid-uuid'),
            [[
                'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
                'product_id' => 10,
                'expected_version' => 1,
                'local_version' => 1,
            ]],
        );

        self::assertTrue($result['success']);
        self::assertSame(1, $result['data']['succeeded']);
    }
}
