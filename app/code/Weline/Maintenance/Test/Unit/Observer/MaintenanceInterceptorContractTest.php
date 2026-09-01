<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Observer\MaintenanceInterceptor;

final class MaintenanceInterceptorContractTest extends TestCase
{
    public function testMaintenanceWorkerAllowsStaticAssetsBeforeMaintenanceResponse(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');

        self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $source);
        self::assertStringContainsString('shouldServeMaintenanceResponse', $source);
        self::assertStringContainsString("if (!\$this->shouldServeMaintenanceResponse(\$pure_uri))", $source);
        self::assertStringContainsString("'/view/statics/'", $source);
        self::assertStringContainsString("'/static/'", $source);
    }

    public function testWhitelistedMaintenanceAssetPaths(): void
    {
        $interceptor = new MaintenanceInterceptor();
        $method = new \ReflectionMethod(MaintenanceInterceptor::class, 'isWhitelisted');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($interceptor, '/'));
        self::assertTrue($method->invoke($interceptor, '/Weline/Theme/view/statics/ui/pages/weline-maintenance.css'));
        self::assertTrue($method->invoke($interceptor, '/Weline/Theme/view/statics/ui/weline-ui.js'));
        self::assertTrue($method->invoke($interceptor, '/static/default/Weline/Theme/view/statics/ui/weline-foundation.css'));
        self::assertTrue($method->invoke($interceptor, '/pub/errors/maintenance/zh_Hans_CN.html'));
    }

    public function testWaitGiftApiPathIsWhitelistedInSource(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');
        self::assertStringContainsString("'/maintenance/frontend/wait-gift'", $source);
        self::assertStringContainsString('WaitGiftService::COOKIE_GATE', $source);
    }
}
