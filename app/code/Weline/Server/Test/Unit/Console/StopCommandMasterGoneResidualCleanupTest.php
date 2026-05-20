<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\MasterProcess;

final class StopCommandMasterGoneResidualCleanupTest extends TestCase
{
    public function testResolveMasterGoneResidualIncludeSharedStateIsAlwaysTrue(): void
    {
        $stop = new class extends Stop {
            public function resolve(ServerInstanceInfo $info): bool
            {
                return $this->resolveMasterGoneResidualIncludeSharedState($info);
            }
        };

        $info = new ServerInstanceInfo(
            'default',
            0,
            0,
            '127.0.0.1',
            443,
            false,
            false,
            0,
            0,
            0,
            '',
            0,
            []
        );

        self::assertTrue($stop->resolve($info));
    }

    public function testMasterGoneResidualPrefixesIncludeSessionAndMemory(): void
    {
        $stop = new Stop();
        $method = new \ReflectionMethod(Stop::class, 'collectResidualCleanupPrefixes');
        $method->setAccessible(true);
        /** @var list<string> $prefixes */
        $prefixes = $method->invoke($stop, 'default', true);

        self::assertContains(MasterProcess::buildScopedProcessName('weline-wls-session', 'default'), $prefixes);
        self::assertContains(MasterProcess::buildScopedProcessName('weline-wls-memory', 'default'), $prefixes);
        self::assertContains('weline-wls-session-default', $prefixes);
        self::assertContains('weline-wls-memory-default', $prefixes);
    }

    public function testTerminateKnownSubprocessesAfterMasterGoneUsesDirectForceCandidates(): void
    {
        $stop = new class extends Stop {
            public int $terminateCalls = 0;

            protected function terminateDirectForceStopCandidatePids(ServerInstanceInfo $info): int
            {
                unset($info);
                $this->terminateCalls++;

                return 2;
            }
        };

        $info = new ServerInstanceInfo(
            'default',
            0,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            0,
            '2026-05-20 01:00:00',
            1776675600,
            [
                new ServiceInfo('session_server', 'Session Server', 1, 9688, 26422, 'ready'),
                new ServiceInfo('worker', 'HTTP Worker', 1, 47640, 16895, 'ready'),
            ]
        );

        $stop->__init();
        $method = new \ReflectionMethod($stop, 'terminateKnownSubprocessesAfterMasterGone');
        $method->setAccessible(true);
        \ob_start();
        try {
            $method->invoke($stop, $info);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(1, $stop->terminateCalls);
    }
}
