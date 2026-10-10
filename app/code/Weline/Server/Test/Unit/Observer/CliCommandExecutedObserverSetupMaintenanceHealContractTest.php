<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * setup: 命令在 code reload 后须再确认关闭维护门禁（Direct 粘性门禁愈合）。
 */
final class CliCommandExecutedObserverSetupMaintenanceHealContractTest extends TestCase
{
    public function testSetupReloadReconcilesMaintenanceOff(): void
    {
        $path = dirname(__DIR__, 3) . '/Observer/CliCommandExecutedObserver.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('reconcileMaintenanceOffAfterSetupReload', $src);
        self::assertStringContainsString("str_starts_with(\$command, 'setup:')", $src);
        self::assertStringContainsString('setMaintenanceMode(false)', $src);
        self::assertStringContainsString('WLS 维护门禁已在代码重载后再次确认关闭', $src);
    }
}
