<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayAdminStoppedTransactionContractTest extends TestCase
{
    public function testBothNativeStopWritersUseTheSharedPackageTransactionLock(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $posix = (string)\file_get_contents(
            $gateway . '/Native/posix/wls_gateway_broker.c',
        );
        $windows = (string)\file_get_contents(
            $gateway . '/Native/windows/wls_gateway_broker.c',
        );

        $posixStop = $this->functionBody(
            $posix,
            'static int wls_write_admin_stopped(',
            'static int wls_upgrade_boot_id(',
        );
        $windowsStop = $this->functionBody(
            $windows,
            'static int wls_write_admin_stopped(',
            'static int wls_upgrade_boot_id(',
        );

        self::assertStringContainsString('wls_package_install_lock_acquire(', $posixStop);
        self::assertStringContainsString('flock(lock_fd, LOCK_UN)', $posixStop);
        self::assertStringContainsString('wls_win_package_install_lock_acquire(', $windowsStop);
        self::assertStringContainsString('wls_win_package_install_lock_release(', $windowsStop);
        self::assertGreaterThanOrEqual(2, \substr_count($posix, '"STOP"'));
        self::assertGreaterThanOrEqual(2, \substr_count($windows, '"STOP"'));
    }

    public function testPhpClearAndRestoreShareTheLockAndRestoreIsCompareAbsent(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Service/Edge/Gateway/GatewayHostManager.php',
        );
        $transaction = $this->functionBody(
            $source,
            'private function withAdminStoppedIntentTransaction(',
            'private function clearAdminStoppedIntent(',
        );
        $clear = $this->functionBody(
            $source,
            'private function clearAdminStoppedIntent(',
            'private function restoreAdminStoppedIntent(',
        );
        $restore = $this->functionBody(
            $source,
            'private function restoreAdminStoppedIntent(',
            'private function assertAdminStoppedIntent(',
        );

        self::assertStringContainsString('package-install.lock', $transaction);
        self::assertStringContainsString('withAdminStoppedIntentTransaction(', $clear);
        self::assertStringContainsString('cleanupAtomicWriteRecoveryBackups(', $clear);
        self::assertStringContainsString('withAdminStoppedIntentTransaction(', $restore);
        self::assertStringContainsString('hasAtomicWriteRecoveryBackups(', $restore);
        self::assertStringContainsString('if (\\file_exists($file) || \\is_link($file))', $restore);
    }

    public function testBothNativeBootstrapClientsUseTheProtocolSecretVerbatim(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $posix = (string)\file_get_contents(
            $gateway . '/Native/posix/wls_gateway_broker.c',
        );
        $windows = (string)\file_get_contents(
            $gateway . '/Native/windows/wls_gateway_broker.c',
        );
        $posixBootstrap = $this->functionBody(
            $posix,
            'static int wls_bootstrap_once(',
            'static int wls_bootstrap_once_serialized(',
        );
        $windowsBootstrap = $this->functionBody(
            $windows,
            'static int wls_bootstrap_once(',
            'static int wls_bootstrap_once_serialized(',
        );

        self::assertStringNotContainsString('sodium_hex2bin(', $posixBootstrap);
        self::assertSame(
            2,
            \substr_count($posixBootstrap, '(const unsigned char *)token'),
        );
        self::assertStringNotContainsString(
            'wls_hex_decode_fixed(token',
            $windowsBootstrap,
        );
        self::assertSame(
            2,
            \substr_count($windowsBootstrap, '(const unsigned char *)token'),
        );
    }

    private function functionBody(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start, 'Expected function start was not found: ' . $startNeedle);
        $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
        self::assertIsInt($end, 'Expected function end was not found: ' . $endNeedle);
        return \substr($source, $start, $end - $start);
    }
}
