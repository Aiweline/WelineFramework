<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayAtomicCandidateRecoveryContractTest extends TestCase
{
    public function testBothBrokersInventoryOnlyTheirCompleteReusableTargetClosure(): void
    {
        [$posix, $windows] = $this->sources();
        $posixInventory = $this->functionBody(
            $posix,
            'static const struct wls_atomic_recovery_target wls_atomic_recovery_targets[',
            'static int wls_atomic_recovery_choice_valid(',
        );
        $windowsInventory = $this->functionBody(
            $windows,
            'static const struct wls_atomic_recovery_target wls_atomic_recovery_targets[',
            'static int wls_atomic_recovery_choice_valid(',
        );
        $sharedTargets = [
            'admin-stopped.intent',
            'upgrade-observing',
            'upgrade-healthy',
            'upgrade-rollback-healthy',
            'controller-process.identity',
            'process-attestation-candidate.fence',
            'process-attestation.receipt',
            'process-tree-retirement.receipt',
            'broker-fencing-token',
        ];

        foreach ($sharedTargets as $target) {
            self::assertStringContainsString('"' . $target . '"', $posixInventory);
            self::assertStringContainsString('L"' . $target . '"', $windowsInventory);
        }
        self::assertStringContainsString('L"broker-launch.receipt"', $windowsInventory);
        self::assertStringContainsString('L"nginx-process.identity"', $windowsInventory);

        self::assertStringContainsString(
            'WLS_ATOMIC_RECOVERY_TARGET_COUNT 9U',
            $posix,
        );
        self::assertStringContainsString(
            'WLS_ATOMIC_RECOVERY_TARGET_COUNT 11U',
            $windows,
        );
        self::assertStringNotContainsString(
            'upgrade-rollback.request",',
            $posixInventory,
        );
    }

    public function testDiscoveryIsBoundedExactAndRejectsMalformedReservedLeaves(): void
    {
        [$posix, $windows] = $this->sources();

        foreach ([$posix, $windows] as $source) {
            self::assertStringContainsString(
                'WLS_ATOMIC_RECOVERY_DIRECTORY_ENTRIES_MAXIMUM',
                $source,
            );
            self::assertStringContainsString(
                'WLS_ATOMIC_RECOVERY_CANDIDATES_MAXIMUM',
                $source,
            );
            self::assertStringContainsString('.candidate.', $source);
            self::assertStringContainsString('reserved_prefix', $source);
            self::assertStringContainsString('target_index == target_count', $source);
        }

        self::assertStringContainsString('O_NOFOLLOW', $posix);
        self::assertStringContainsString('S_ISREG(candidate_status.st_mode)', $posix);
        self::assertStringContainsString('candidate_status.st_nlink != 1', $posix);
        self::assertStringContainsString('candidate_status.st_uid', $posix);
        self::assertStringContainsString('candidate_status.st_mode & 0777', $posix);

        self::assertStringContainsString('FILE_FLAG_OPEN_REPARSE_POINT', $windows);
        self::assertStringContainsString('candidate_standard.NumberOfLinks != 1U', $windows);
        self::assertStringContainsString(
            'wls_private_acl_safe(candidate, "S-1-5-18") != 1',
            $windows,
        );
    }

    public function testEveryCommittedPairIsSemanticallyValidatedBeforeAnyDeletion(): void
    {
        [$posix, $windows] = $this->sources();
        $posixRecovery = $this->functionBody(
            $posix,
            'static int wls_recover_atomic_write_candidates_locked(',
            'static int wls_recover_atomic_write_candidates(',
        );
        $windowsRecovery = $this->functionBody(
            $windows,
            'static int wls_win_recover_atomic_write_candidates_locked(',
            'static int wls_win_recover_atomic_write_candidates(',
        );

        foreach ([$posixRecovery, $windowsRecovery] as $recovery) {
            self::assertStringContainsString('wls_atomic_recovery_committed_valid(', $recovery);
            self::assertStringContainsString('Phase 1: discover and validate', $recovery);
            self::assertStringContainsString('Phase 2: revalidate the complete closure', $recovery);
            self::assertStringContainsString('Phase 3: delete only after', $recovery);
        }

        self::assertLessThan(
            \strpos($posixRecovery, 'unlinkat('),
            \strpos($posixRecovery, 'Phase 2: revalidate the complete closure'),
        );
        self::assertLessThan(
            \strpos($windowsRecovery, 'SetFileInformationByHandle('),
            \strpos($windowsRecovery, 'Phase 2: revalidate the complete closure'),
        );
        self::assertStringContainsString('wls_atomic_recovery_payload_valid(', $posix);
        self::assertStringContainsString('wls_atomic_recovery_payload_valid(', $windows);
        self::assertStringContainsString('memchr(contents, \'\\0\'', $posix);
        self::assertStringContainsString('memchr(contents, \'\\0\'', $windows);
    }

    public function testRecoveryRunsInsidePackageAndLifecycleLocksBeforeNewFencingPublication(): void
    {
        [$posix, $windows] = $this->sources();
        $posixWrapper = $this->functionBody(
            $posix,
            'static int wls_recover_atomic_write_candidates(',
            'static int wls_serve(',
        );
        $windowsWrapper = $this->functionBody(
            $windows,
            'static int wls_win_recover_atomic_write_candidates(',
            'int wmain(',
        );
        $posixServe = $this->functionBody(
            $posix,
            'static int wls_serve(',
            'static int wls_self_test(',
        );
        $windowsMain = \substr($windows, (int)\strpos($windows, 'int wmain('));

        self::assertStringContainsString('wls_package_install_lock_acquire(', $posixWrapper);
        self::assertStringContainsString('flock(lock_fd, LOCK_UN)', $posixWrapper);
        self::assertStringContainsString(
            'wls_win_package_install_lock_acquire(',
            $windowsWrapper,
        );
        self::assertStringContainsString(
            'wls_win_package_install_lock_release(',
            $windowsWrapper,
        );

        self::assertLessThan(
            \strpos($posixServe, 'wls_write_fencing('),
            \strpos($posixServe, 'wls_recover_atomic_write_candidates('),
        );
        self::assertLessThan(
            \strpos($windowsMain, 'wls_write_fencing_file('),
            \strpos($windowsMain, 'wls_win_recover_atomic_write_candidates('),
        );
        self::assertStringContainsString('flock(lock_fd, LOCK_EX | LOCK_NB)', $posixServe);
        self::assertStringContainsString(
            'CreateMutexW(NULL, TRUE, L"Global\\\\WelineWlsGatewayV2Broker")',
            $windowsMain,
        );
    }

    /** @return array{string, string} */
    private function sources(): array
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/Native';
        return [
            (string)\file_get_contents($gateway . '/posix/wls_gateway_broker.c'),
            (string)\file_get_contents($gateway . '/windows/wls_gateway_broker.c'),
        ];
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
