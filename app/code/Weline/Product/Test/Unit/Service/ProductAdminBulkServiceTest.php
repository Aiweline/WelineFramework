<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Service\ProductAdminBulkService;

final class ProductAdminBulkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('__')) {
            eval('function __(string $text, array $args = []): string { return $text; }');
        }
    }

    public function testExecuteDispatchesPerItemHashes(): void
    {
        $commands = $this->createMock(ProductAdminCommandInterface::class);
        $commands->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function ($command): bool {
                return $command->action === 'archive'
                    && $command->websiteId === 2
                    && $command->globalProductUuid === '10000000-0000-4000-8000-000000000001'
                    && preg_match('/^[a-f0-9]{64}$/', $command->requestHash) === 1;
            }))
            ->willReturn(ProductAdminResult::ok([], 'ok'));

        $service = new ProductAdminBulkService($commands);
        $baseHash = hash('sha256', 'bulk-test');
        $result = $service->execute(
            2,
            'archive',
            $baseHash,
            [[
                'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
                'expected_version' => 3,
                'local_version' => 1,
            ]],
        );

        self::assertTrue($result['success']);
        self::assertSame(1, $result['data']['succeeded']);
        self::assertSame('archive', $result['data']['action']);
    }

    public function testExecuteAllowsDefaultWebsiteIdZero(): void
    {
        $commands = $this->createMock(ProductAdminCommandInterface::class);
        $commands->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function ($command): bool {
                return $command->action === 'archive'
                    && $command->websiteId === 0
                    && $command->globalProductUuid === '10000000-0000-4000-8000-000000000001';
            }))
            ->willReturn(ProductAdminResult::ok([], 'ok'));

        $service = new ProductAdminBulkService($commands);
        $result = $service->execute(
            0,
            'archive',
            hash('sha256', 'bulk-website-zero'),
            [[
                'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
                'expected_version' => 1,
                'local_version' => 1,
            ]],
        );

        self::assertTrue($result['success']);
        self::assertSame(1, $result['data']['succeeded']);
    }

    public function testExecuteRejectsNegativeWebsiteId(): void
    {
        $service = new ProductAdminBulkService(
            $this->createStub(ProductAdminCommandInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_admin_website_invalid');
        $service->execute(
            -1,
            'archive',
            hash('sha256', 'bulk-website-negative'),
            [[
                'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
            ]],
        );
    }
}
