<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Observer\MaintenanceInterceptor;

final class MaintenanceInterceptorEarlyReturnContractTest extends TestCase
{
    public function testMaintenanceOffReturnsBeforeFullUriParse(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/MaintenanceInterceptor.php',
        );
        self::assertStringContainsString('关闭时尽早返回，不做完整 URI 解析', $source);
        self::assertMatchesRegularExpression(
            "/if\s*\(\s*!Env::system\('maintenance'\)\s*\)\s*\{\s*return;/s",
            $source,
        );
        self::assertTrue(class_exists(MaintenanceInterceptor::class));
    }
}
