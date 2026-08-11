<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

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
            self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/3', $source);
            self::assertStringContainsString(
                'upgrade-rollback.request.wls-backup-',
                $source,
            );
            self::assertStringContainsString('request_nonce=%32[0-9a-f]', $source);
            self::assertStringContainsString('host_boot_id=%64[0-9a-f]', $source);
            self::assertStringContainsString('binding->prepared_monotonic', $source);
            self::assertStringContainsString(
                'binding->total_deadline_monotonic',
                $source,
            );
            self::assertStringContainsString('binding->intent_boot_id', $source);
            self::assertStringContainsString('request.request_sha256', $source);
        }
        $posixActionStart = \strpos(
            $posix,
            'static int wls_rollback_request_action_v3(',
        );
        $posixActionEnd = \strpos(
            $posix,
            'static int wls_process_identity(',
            $posixActionStart,
        );
        $windowsActionStart = \strpos(
            $windows,
            'static int wls_win_rollback_request_action_v3(',
        );
        $windowsActionEnd = \strpos(
            $windows,
            'static int wls_win_read_path_contents(',
            $windowsActionStart,
        );
        self::assertIsInt($posixActionStart);
        self::assertIsInt($posixActionEnd);
        self::assertIsInt($windowsActionStart);
        self::assertIsInt($windowsActionEnd);
        foreach ([
            \substr($posix, $posixActionStart, $posixActionEnd - $posixActionStart),
            \substr(
                $windows,
                $windowsActionStart,
                $windowsActionEnd - $windowsActionStart,
            ),
        ] as $action) {
            self::assertStringContainsString('requested_monotonic', $action);
            self::assertStringNotContainsString('time(NULL)', $action);
        }
        self::assertStringContainsString('flock(fd, LOCK_EX | LOCK_NB)', $posix);
        self::assertStringContainsString('mach_absolute_time()', $posix);
        self::assertStringContainsString('LockFileEx(', $windows);
        self::assertStringContainsString('UnlockFileEx(', $windows);
        self::assertStringContainsString('QueryPerformanceCounter(', $windows);
        self::assertStringContainsString(
            'wls_protocol_monotonic_milliseconds(&monotonic_now)',
            $windows,
        );
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

        self::assertStringContainsString(
            '$this->verifiedRollbackTargetManifest($previous)',
            $rollback,
        );
        self::assertLessThan(
            \strpos($rollback, "'ROLLBACK_REQUEST'"),
            \strpos($rollback, 'verifiedRollbackTargetManifest($previous)'),
        );
        self::assertStringContainsString(
            '$this->stableFileHash(',
            $rollback,
        );
        self::assertStringContainsString(
            '$previousManifest[\'components\']',
            $rollback,
        );
        self::assertStringContainsString("'ROLLBACK_REQUEST'", $rollback);
        self::assertStringContainsString('brokerActionsAvailable($channel)', $rollback);
        self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/3', $rollback);
        self::assertStringContainsString('requested_monotonic_ms=', $rollback);
        self::assertStringContainsString("\hrtime(true)", $rollback);
        self::assertStringContainsString(
            "\\hash('sha256', \$requestEnvelope)",
            $rollback,
        );
        self::assertStringNotContainsString(
            "stateDir() . DIRECTORY_SEPARATOR . 'upgrade-rollback.request'",
            $rollback,
        );
        self::assertStringNotContainsString('$this->atomicWrite(', $rollback);

        $targetStart = \strpos(
            $controller,
            'private function verifiedRollbackTargetManifest(',
        );
        self::assertIsInt($targetStart);
        $targetEnd = \strpos(
            $controller,
            'private function validateUpgradeIntentForRollback(',
            $targetStart,
        );
        self::assertIsInt($targetEnd);
        $target = \substr($controller, $targetStart, $targetEnd - $targetStart);
        self::assertStringContainsString(
            'self::DURABLE_STATE_CONTRACT',
            $target,
        );
        self::assertStringContainsString(
            '$expectedRuntimeGeneration',
            $target,
        );
        self::assertStringContainsString(
            "'host_gateway'",
            $target,
        );
        self::assertStringContainsString(
            "'stable_launcher_rollback_target_proof'",
            $target,
        );
        self::assertStringContainsString(
            "(\$capabilities['stable_launcher_rollback_target_proof'] ?? null)",
            $target,
        );
        self::assertStringContainsString(
            '!== true',
            $target,
        );
    }

    public function testControllerRollbackContractMatchesThePackageContractExactly(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php',
        );
        $start = \strpos(
            $controller,
            'private const DURABLE_STATE_CONTRACT = [',
        );
        self::assertIsInt($start);
        $end = \strpos($controller, '    ];', $start);
        self::assertIsInt($end);
        $constant = \substr($controller, $start, $end - $start);

        self::assertSame(
            \count(HostGatewayPackageManager::DURABLE_STATE_CONTRACT),
            \substr_count($constant, ' => '),
        );
        foreach (HostGatewayPackageManager::DURABLE_STATE_CONTRACT as $field => $value) {
            $encoded = \is_string($value) ? "'" . $value . "'" : (string)$value;
            self::assertStringContainsString(
                "'" . $field . "' => " . $encoded,
                $constant,
            );
        }
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
        self::assertStringContainsString("'prepared_monotonic_ms'", $manager);
        self::assertStringContainsString(
            "'rollback_deadline_monotonic_ms'",
            $manager,
        );
    }
}
