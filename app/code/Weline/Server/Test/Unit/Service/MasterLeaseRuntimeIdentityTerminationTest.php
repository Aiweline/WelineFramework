<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterLeaseRuntimeIdentityTerminationTest extends TestCase
{
    public function testStableTerminatorRunsOnlyAfterExactBirthMatch(): void
    {
        $calls = [];
        $runtime = new MasterLeaseRuntimeIdentity(
            processInfoResolver: static fn (int $pid): array => [
                'exists' => true,
                'name' => 'php',
                'command' => 'php server:start',
                'start_time' => 'stable-start',
            ],
            pidNamespaceResolver: static fn (int $pid): ?string => '',
            stableProcessTerminator: static function (
                int $pid,
                string $birth,
                string $pidNamespaceId,
                float $graceSeconds,
            ) use (&$calls): array {
                $calls[] = [$pid, $birth, $pidNamespaceId, $graceSeconds];

                return [
                    'released' => true,
                    'terminated' => true,
                    'reason' => 'injected_stable_handle_released',
                ];
            },
        );
        $identity = $runtime->captureProcessIdentity(43210);

        $result = $runtime->terminateExactProcessIdentity(
            43210,
            $identity['birth'],
            $identity['pid_namespace_id'],
            0.25,
        );

        self::assertTrue($result['released']);
        self::assertTrue($result['terminated']);
        self::assertSame('injected_stable_handle_released', $result['reason']);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MATCH, $result['owner_state']);
        self::assertCount(1, $calls);
        self::assertSame(43210, $calls[0][0]);
        self::assertSame($identity['birth'], $calls[0][1]);
    }

    public function testIdentityMismatchReleasesLeaseWithoutCallingTerminator(): void
    {
        $called = false;
        $runtime = new MasterLeaseRuntimeIdentity(
            processInfoResolver: static fn (int $pid): array => [
                'exists' => true,
                'name' => 'php',
                'command' => 'php unrelated.php',
                'start_time' => 'reused-start',
            ],
            pidNamespaceResolver: static fn (int $pid): ?string => '',
            stableProcessTerminator: static function () use (&$called): array {
                $called = true;

                return [];
            },
        );

        $result = $runtime->terminateExactProcessIdentity(
            43211,
            \str_repeat('a', 64),
            '',
        );

        self::assertTrue($result['released']);
        self::assertFalse($result['terminated']);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_MISMATCH, $result['owner_state']);
        self::assertSame('process_identity_released_without_signal', $result['reason']);
        self::assertFalse($called);
    }

    public function testUnknownIdentityFailsClosedWithoutCallingTerminator(): void
    {
        $called = false;
        $runtime = new MasterLeaseRuntimeIdentity(
            processInfoResolver: static fn (int $pid): array => [],
            pidNamespaceResolver: static fn (int $pid): ?string => '',
            stableProcessTerminator: static function () use (&$called): array {
                $called = true;

                return [];
            },
        );

        $result = $runtime->terminateExactProcessIdentity(
            43212,
            \str_repeat('b', 64),
            '',
        );

        self::assertFalse($result['released']);
        self::assertFalse($result['terminated']);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_UNKNOWN, $result['owner_state']);
        self::assertSame('process_identity_unknown', $result['reason']);
        self::assertFalse($called);
    }

    public function testProductionImplementationUsesStableKernelHandles(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );

        self::assertStringContainsString('pidfd_open', $source);
        self::assertStringContainsString('pidfd_send_signal', $source);
        self::assertStringContainsString("\$ffi->cast('void *', 0)", $source);
        self::assertStringContainsString("\$ffi->cast('char *', \$buffer)", $source);
        self::assertStringContainsString('TerminateProcess', $source);
        self::assertStringContainsString('stable_process_handle_unavailable_on_darwin', $source);
    }

    public function testManagedNginxFallbackNeverSignalsARevalidatedNumericPid(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxProcessManager.php',
        );
        $start = (int)\strpos($source, 'private function killPid(');
        $end = (int)\strpos($source, "\n    }\n}", $start);
        self::assertGreaterThan(0, $start);
        self::assertGreaterThan($start, $end);
        $method = \substr($source, $start, $end - $start);

        self::assertStringContainsString('captureProcessIdentity(', $method);
        self::assertStringContainsString('terminateExactProcessIdentity(', $method);
        self::assertStringNotContainsString('Processer::killByPid(', $method);
        self::assertStringNotContainsString('posix_kill(', $method);
    }
}
