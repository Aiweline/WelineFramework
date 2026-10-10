<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * setup:upgrade 关闭维护须以 WLS 同步结果判定，且不得在维护仍开时触发重载。
 */
final class UpgradeMaintenanceCloseContractTest extends TestCase
{
    public function testCleanupRequiresWlsSyncBeforeClaimingClosed(): void
    {
        $path = dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('private function syncWlsMaintenanceMode(bool $enabled): bool', $src);
        self::assertStringContainsString('$wlsOk = $this->syncWlsMaintenanceMode(false)', $src);
        self::assertStringContainsString('框架维护标志已关闭，但 WLS Worker 门禁未完全同步', $src);
        self::assertStringNotContainsString('$this->notifyWlsReload()', $src);
    }
}
