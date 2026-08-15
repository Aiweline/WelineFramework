<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayLauncherRecoveryContractTest extends TestCase
{
    public function testPersistentRecoveryPolicyAndSupervisorRetryRemainAligned(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $ledger = (string)\file_get_contents(
            $gateway . '/Native/wls_launcher_recovery_ledger.h',
        );
        self::assertStringContainsString(
            '#define WLS_RECOVERY_WINDOW_MILLISECONDS 900000ULL',
            $ledger,
        );
        self::assertStringContainsString(
            '#define WLS_RECOVERY_FAILURE_LIMIT 10U',
            $ledger,
        );
        self::assertStringContainsString(
            '#define WLS_RECOVERY_BASE_DELAY_SECONDS 5ULL',
            $ledger,
        );
        self::assertStringContainsString(
            '#define WLS_RECOVERY_MAX_DELAY_SECONDS 300ULL',
            $ledger,
        );
        self::assertStringContainsString(
            '#define WLS_RECOVERY_HEALTH_MILLISECONDS 15000ULL',
            $ledger,
        );
        self::assertStringContainsString('INCOMPLETE_ATTEMPT', $ledger);
        self::assertStringContainsString(
            'wls_recovery_initialize_invalid(',
            $ledger,
        );

        $systemd = (string)\file_get_contents(
            $gateway . '/../../../env/gateway/systemd.service.template',
        );
        self::assertStringContainsString('StartLimitIntervalSec=0', $systemd);
        self::assertStringContainsString('RestartSec=5', $systemd);
        self::assertStringContainsString('PrivateTmp=false', $systemd);
        self::assertStringNotContainsString('PrivateTmp=true', $systemd);
        self::assertStringContainsString(
            'CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_DAC_READ_SEARCH CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL CAP_FOWNER',
            $systemd,
        );
        self::assertStringContainsString(
            'AmbientCapabilities=CAP_NET_BIND_SERVICE CAP_DAC_READ_SEARCH CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL CAP_FOWNER',
            $systemd,
        );

        $installer = (string)\file_get_contents(
            $gateway . '/GatewayPlatformServiceInstaller.php',
        );
        self::assertStringContainsString("'reset=',\n            '0'", $installer);
        self::assertStringContainsString(
            'restart/5000/restart/5000/restart/5000',
            $installer,
        );
    }

    public function testPosixHealthProofBindsPidBirthAndExecutableDigestWithoutSignallingIt(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $start = \strpos(
            $source,
            "static int wls_recovery_attested_process_live(\n"
                . "    const struct wls_process_attestation_receipt *receipt,\n"
                . "    const char *home,\n"
                . "    char active_slot\n"
                . ') {',
        );
        $end = \strpos(
            $source,
            '/* 1=exact live data-plane attestation',
            $start === false ? 0 : $start,
        );
        self::assertIsInt($start);
        self::assertIsInt($end);
        $proof = \substr($source, $start, $end - $start);
        self::assertSame(3, \substr_count(
            $proof,
            'wls_recovery_process_identity(',
        ));
        self::assertStringContainsString(
            'start_before != receipt->start_id',
            $proof,
        );
        self::assertStringContainsString(
            'start_after != start_before',
            $proof,
        );
        self::assertStringContainsString(
            'sodium_memcmp(binary_digest, receipt->binary_digest, 64U)',
            $proof,
        );
        self::assertStringContainsString('kill(pid, 0)', $proof);
        self::assertDoesNotMatchRegularExpression(
            '/kill\(pid,\s*(?:SIG[A-Z]+|[1-9][0-9]*)\)/',
            $proof,
        );

        $cmake = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/CMakeLists.txt',
        );
        self::assertStringContainsString(
            'target_link_libraries(wls-gateway-launcher PRIVATE proc)',
            $cmake,
        );
    }

    public function testLauncherProjectionCannotBecomeControlAuthority(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayHostManager.php',
        );
        self::assertStringContainsString(
            "'source' => 'launcher_diagnostic_projection'",
            $source,
        );
        self::assertStringContainsString("'authoritative' => false", $source);
        self::assertStringContainsString("'ready' => false", $source);
    }

    public function testPosixSystemdGuardianUsesDedicatedDefinitionAndVerifiesFixedLink(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $targetStart = \strpos($source, 'static int wls_guardian_platform_target(');
        $verificationStart = \strpos(
            $source,
            'static int wls_guardian_systemd_fixed_link_verify(',
            $targetStart === false ? 0 : $targetStart,
        );
        self::assertIsInt($targetStart);
        self::assertIsInt($verificationStart);
        $target = \substr(
            $source,
            $targetStart,
            $verificationStart - $targetStart,
        );
        self::assertStringContainsString(
            '/etc/weline-gateway/weline-wls-gateway-v2.service',
            $target,
        );
        self::assertStringNotContainsString(
            '/etc/systemd/system/weline-wls-gateway-v2.service',
            $target,
        );
        $liveStart = \strpos(
            $source,
            'static int wls_guardian_platform_live_verify(',
        );
        self::assertIsInt($liveStart);
        $verification = \substr(
            $source,
            $verificationStart,
            $liveStart - $verificationStart,
        );
        self::assertStringContainsString(
            '/etc/systemd/system/weline-wls-gateway-v2.service',
            $verification,
        );
        self::assertStringContainsString('readlink(', $verification);
        self::assertStringContainsString('geteuid()', $verification);
        self::assertStringContainsString('getegid()', $verification);

        $liveEnd = \strpos(
            $source,
            '/* Return with guardian-generation-head.lock still held.',
            $liveStart,
        );
        self::assertIsInt($liveEnd);
        $live = \substr($source, $liveStart, $liveEnd - $liveStart);
        self::assertStringContainsString(
            'wls_guardian_systemd_fixed_link_verify(definition)',
            $live,
        );
    }

    public function testPosixCapacityKeepsIndependentPlatformFilesystemReserve(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $anchorStart = \strpos($source, 'static int wls_capacity_anchor_proof(');
        $anchorEnd = \strpos(
            $source,
            'static int wls_capacity_leaf(',
            $anchorStart === false ? 0 : $anchorStart,
        );
        self::assertIsInt($anchorStart);
        self::assertIsInt($anchorEnd);
        $anchor = \substr($source, $anchorStart, $anchorEnd - $anchorStart);
        self::assertStringContainsString(
            'struct wls_capacity_platform_anchor',
            $anchor,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_requires_distinct_reserve(',
            $anchor,
        );

        self::assertStringContainsString(
            'static int wls_capacity_platform_device_self_test(void)',
            $source,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_requires_distinct_reserve((dev_t)7, (dev_t)8) == 1',
            $source,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_create(',
            $source,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_verify(',
            $source,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_release(',
            $source,
        );
        self::assertStringContainsString(
            'static int wls_capacity_platform_reserve_absent(',
            $source,
        );
        self::assertStringContainsString(
            'WLS_CAPACITY_PLATFORM_INODES == 2U',
            $source,
        );

        $beginReleaseStart = \strpos(
            $source,
            'if (strcmp(operation, "begin-release") == 0)',
        );
        $completeReleaseStart = \strpos(
            $source,
            'if (held_present) {',
            $beginReleaseStart === false ? 0 : $beginReleaseStart,
        );
        self::assertIsInt($beginReleaseStart);
        self::assertIsInt($completeReleaseStart);
        $beginRelease = \substr(
            $source,
            $beginReleaseStart,
            $completeReleaseStart - $beginReleaseStart,
        );
        $transition = \strpos(
            $beginRelease,
            'WLS_CAPACITY_CONTROL_TRANSITION',
        );
        $release = \strpos(
            $beginRelease,
            'wls_capacity_platform_reserve_release(',
        );
        $finish = \strpos(
            $beginRelease,
            'wls_capacity_finish_release_control(',
        );
        self::assertIsInt($transition);
        self::assertIsInt($release);
        self::assertIsInt($finish);
        self::assertLessThan($release, $transition);
        self::assertLessThan($finish, $release);
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_absent(',
            $beginRelease,
        );
    }
}
