<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayAtomicPendingLedgerContractTest extends TestCase
{
    public function testSecurityLedgerAuxiliaryFilesAreExactBoundedAtomicTargetsOnBothPlatforms(): void
    {
        $native = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/Native';
        $posix = (string)\file_get_contents(
            $native . '/posix/wls_gateway_broker.c',
        );
        $windows = (string)\file_get_contents(
            $native . '/windows/wls_gateway_broker.c',
        );

        $posixTargets = $this->targetTable(
            $posix,
            'static const struct wls_atomic_target_limit wls_atomic_targets[] = {',
            'static uint64_t wls_atomic_target_maximum(',
        );
        self::assertSame(1, \substr_count(
            $posixTargets,
            '{"state/security-ledger.pending.json", WLS_MAX_ATOMIC_STATE}',
        ));
        self::assertSame(1, \substr_count(
            $posix,
            '#define WLS_MAX_ATOMIC_SECURITY_DISTRUST 128U',
        ));
        self::assertSame(1, \substr_count(
            $posixTargets,
            '{"state/security-ledger.json.untrusted", WLS_MAX_ATOMIC_SECURITY_DISTRUST}',
        ));
        self::assertSame(1, \substr_count(
            $posixTargets,
            '{"state/security-anchor.json", WLS_MAX_REQUEST}',
        ));
        self::assertSame(1, \substr_count(
            $posixTargets,
            '{"state/wls-edge-2.initialized.json", WLS_MAX_REQUEST}',
        ));
        self::assertStringNotContainsString('{"trust/security-anchor.json",', $posixTargets);
        self::assertStringNotContainsString('{"trust/wls-edge-2.initialized.json",', $posixTargets);

        $windowsTargets = $this->targetTable(
            $windows,
            'static const struct wls_win_atomic_target_limit wls_win_atomic_targets[] = {',
            'static unsigned long long wls_win_atomic_target_maximum(',
        );
        self::assertSame(1, \substr_count(
            $windowsTargets,
            '{L"state\\\\security-ledger.pending.json", WLS_MAX_ATOMIC_STATE}',
        ));
        self::assertSame(1, \substr_count(
            $windows,
            '#define WLS_MAX_ATOMIC_SECURITY_DISTRUST 128ULL',
        ));
        self::assertSame(1, \substr_count(
            $windowsTargets,
            '{L"state\\\\security-ledger.json.untrusted", WLS_MAX_ATOMIC_SECURITY_DISTRUST}',
        ));
        self::assertSame(1, \substr_count(
            $windowsTargets,
            '{L"state\\\\security-anchor.json", WLS_MAX_REQUEST}',
        ));
        self::assertSame(1, \substr_count(
            $windowsTargets,
            '{L"state\\\\wls-edge-2.initialized.json", WLS_MAX_REQUEST}',
        ));
        self::assertStringNotContainsString('{L"trust\\\\security-anchor.json",', $windowsTargets);
        self::assertStringNotContainsString('{L"trust\\\\wls-edge-2.initialized.json",', $windowsTargets);

        foreach ([$posixTargets, $windowsTargets] as $targets) {
            self::assertStringNotContainsString('*', $targets);
            self::assertStringNotContainsString('security-ledger.*', $targets);
            self::assertStringNotContainsString('security-ledger/', $targets);
        }
    }

    private function targetTable(
        string $source,
        string $startNeedle,
        string $endNeedle,
    ): string {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start, 'Missing atomic target table.');
        $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
        self::assertIsInt($end, 'Missing atomic target table boundary.');
        return \substr($source, $start, $end - $start);
    }
}
