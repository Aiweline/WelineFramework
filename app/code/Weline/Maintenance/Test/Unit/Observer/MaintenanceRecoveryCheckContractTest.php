<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class MaintenanceRecoveryCheckContractTest extends TestCase
{
    public function testInterceptorShortCircuitsRecoveryProbesBeforeBusinessRouting(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/MaintenanceInterceptor.php'
        );

        self::assertStringContainsString('tryHandleRecoveryCheck', $source);
        self::assertStringContainsString('isRecoveryCheckRequest', $source);
        self::assertStringContainsString('/maintenance/frontend/recovery-check', $source);
        self::assertStringContainsString('HTTP_X_MAINTENANCE_RECOVERY_CHECK', $source);
        self::assertStringContainsString('_maintenance_recovery_probe', $source);
        self::assertStringContainsString('X-Weline-Maintenance-Recovery', $source);
        self::assertMatchesRegularExpression(
            '/tryHandleRecoveryCheck\\(\\$pureEarly\\).*tryHandleWaitGiftApi\\(\\$pureEarly\\)/s',
            $source
        );
    }

    public function testFrontendWaitModalUsesLightweightRecoveryCheck(): void
    {
        $asyncJs = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/maintenance-async-wait.js'
        );
        $welineJs = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline.js'
        );

        self::assertStringContainsString('/maintenance/frontend/recovery-check', $asyncJs);
        self::assertStringContainsString('X-Maintenance-Recovery-Check', $asyncJs);
        self::assertStringNotContainsString(
            "fetch(window.location.pathname + window.location.search",
            $asyncJs
        );
        // Framework only lazy-loads the Maintenance module on 503.
        self::assertStringContainsString('maintenanceAsyncWait', $welineJs);
        self::assertStringContainsString('installMaintenanceHandlerLazyBridge', $welineJs);
        self::assertStringNotContainsString('weline-maintenance-wait-modal', $welineJs);
    }
}
