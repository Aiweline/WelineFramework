<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Direct Worker READY 必须继承 Master 维护 epoch（含 false），禁止只推 true。
 */
final class ServiceOrchestratorDirectMaintenanceEpochOnReadyContractTest extends TestCase
{
    public function testReadyHandlerPushesMasterMaintenanceEpochIncludingFalse(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ServiceOrchestrator.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString(
            'ControlMessage::setMaintenanceMode($this->maintenanceMode, \'\', true)',
            $src,
            'Direct READY must push Master maintenanceMode (true or false)'
        );
        self::assertStringNotContainsString(
            "ControlMessage::setMaintenanceMode(true, '', true)\n            );",
            $src,
            'Direct READY must not hardcode maintenance true-only push'
        );
        self::assertStringContainsString(
            'broadcastDirectMaintenanceMode(false)',
            $src,
            'reloadAll finally must heal sticky Direct gates when Master is already off'
        );
    }
}
