<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayRollbackActionContractTest extends TestCase
{
    public function testBothNativeBrokersOwnThePackageLockedRollbackPublication(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $posix = (string)\file_get_contents(
            $gateway . '/Native/posix/wls_gateway_broker.c',
        );
        $windows = (string)\file_get_contents(
            $gateway . '/Native/windows/wls_gateway_broker.c',
        );

        foreach ([$posix, $windows] as $source) {
            self::assertStringContainsString('ROLLBACK_REQUEST', $source);
            self::assertStringContainsString('package-install.lock', $source);
            self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/2', $source);
            self::assertStringContainsString(
                'upgrade-rollback.request.wls-backup-',
                $source,
            );
            self::assertStringContainsString('request_nonce=%32[0-9a-f]', $source);
            self::assertStringContainsString('binding->prepared_at', $source);
            self::assertStringContainsString('binding->total_deadline', $source);
            self::assertStringContainsString('request.request_sha256', $source);
        }
        self::assertStringContainsString('flock(fd, LOCK_EX | LOCK_NB)', $posix);
        self::assertStringContainsString('LockFileEx(', $windows);
        self::assertStringContainsString('UnlockFileEx(', $windows);
    }

    public function testControllerUsesOnlyTheBoundBrokerReceiptForRollback(): void
    {
        $server = \dirname(__DIR__, 5);
        $controller = (string)\file_get_contents(
            $server . '/bin/wls_gateway_controller.php',
        );
        $start = \strpos($controller, 'private function rollbackBinarySlot(): bool');
        $end = \strpos($controller, 'private function validateUpgradeIntentForRollback(', $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $rollback = \substr($controller, $start, $end - $start);

        self::assertStringContainsString("'ROLLBACK_REQUEST'", $rollback);
        self::assertStringContainsString('brokerActionsAvailable($channel)', $rollback);
        self::assertStringContainsString(
            "\\hash('sha256', \$requestEnvelope)",
            $rollback,
        );
        self::assertStringNotContainsString(
            "stateDir() . DIRECTORY_SEPARATOR . 'upgrade-rollback.request'",
            $rollback,
        );
        self::assertStringNotContainsString('$this->atomicWrite(', $rollback);
    }

    public function testPackageManagerCleansOnlyAFullyBoundCurrentRequestUnderInstallLock(): void
    {
        $manager = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $start = \strpos($manager, 'public function rollbackUpgradeActivation(');
        $end = \strpos($manager, 'public function rollbackActivation(', $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $rollback = \substr($manager, $start, $end - $start);

        self::assertStringContainsString('$this->withInstallLock(', $rollback);
        self::assertStringContainsString(
            'cleanupAtomicWriteRecoveryBackups(',
            $rollback,
        );
        self::assertStringContainsString('validateUpgradeRollbackRequest(', $rollback);
        self::assertStringContainsString("'prepared_at'", $manager);
        self::assertStringContainsString("'rollback_deadline'", $manager);
    }
}
