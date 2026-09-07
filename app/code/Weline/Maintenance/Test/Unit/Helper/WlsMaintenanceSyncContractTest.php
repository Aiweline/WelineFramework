<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * CLI 维护开关必须完整同步 WLS 维护模式，禁止仅 Dispatcher 分流。
 */
final class WlsMaintenanceSyncContractTest extends TestCase
{
    public function testCliToggleUsesFullDeploymentMaintenanceControl(): void
    {
        $path = \dirname(__DIR__, 3) . '/Helper/WlsMaintenanceSync.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString(
            'RuntimeDeploymentControlInterface',
            $src,
            'CLI maintenance sync must resolve RuntimeDeploymentControlInterface'
        );
        self::assertStringContainsString(
            'setMaintenanceMode(',
            $src,
            'CLI maintenance sync must call full setMaintenanceMode'
        );
        self::assertStringNotContainsString(
            'setMaintenanceRoutingOnly(',
            $src,
            'CLI must not use dispatcher_only routing sync on Direct topologies'
        );
        self::assertStringNotContainsString(
            'MaintenanceRoutingBroadcasterInterface',
            $src,
            'CLI sync must not depend on routing-only broadcaster'
        );
    }
}
