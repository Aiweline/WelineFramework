<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

/**
 * Maintenance recovery probes must not consume one-time OAuth code/state.
 */
final class SocialLoginMaintenanceProbeContractTest extends TestCase
{
    public function testStartAndCallbackSkipMaintenanceRecoveryProbes(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Account/SocialLogin.php'
        );

        self::assertStringContainsString('isMaintenanceRecoveryProbe', $src);
        self::assertStringContainsString('respondOAuthProbeSkip', $src);
        self::assertStringContainsString('_maintenance_recovery_probe', $src);
        self::assertStringContainsString('HTTP_X_MAINTENANCE_RECOVERY_CHECK', $src);
        self::assertStringContainsString("\$method === 'HEAD'", $src);

        $startPos = strpos($src, 'public function getStart()');
        $callbackPos = strpos($src, 'public function getCallback()');
        self::assertNotFalse($startPos);
        self::assertNotFalse($callbackPos);

        $startChunk = substr($src, $startPos, 400);
        $callbackChunk = substr($src, $callbackPos, 500);
        self::assertStringContainsString('isMaintenanceRecoveryProbe()', $startChunk);
        self::assertStringContainsString('respondOAuthProbeSkip()', $startChunk);
        self::assertStringContainsString('isMaintenanceRecoveryProbe()', $callbackChunk);
        self::assertStringContainsString('respondOAuthProbeSkip()', $callbackChunk);
    }
}
