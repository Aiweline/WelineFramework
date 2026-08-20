<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;

final class StartForceSwitchStopArgsTest extends TestCase
{
    public function testBuildStopExistingServerArgsAddsFastLocalFlagWhenRequested(): void
    {
        $start = new Start();

        $args = $this->invokeProtected($start, 'buildStopExistingServerArgs', 'default', true);

        self::assertTrue((bool) ($args['fast-local'] ?? false));
        self::assertTrue((bool) ($args['force'] ?? false));
        self::assertTrue((bool) ($args['f'] ?? false));
        self::assertSame('default', $args[1] ?? null);
    }

    public function testBuildStopExistingServerArgsKeepsDefaultStopCallForNormalRestart(): void
    {
        $start = new Start();

        $args = $this->invokeProtected($start, 'buildStopExistingServerArgs', 'default', false);

        self::assertArrayNotHasKey('fast-local', $args);
        self::assertArrayNotHasKey('force', $args);
        self::assertArrayNotHasKey('f', $args);
    }

    public function testFastLocalRestartWaitsForResidueToClear(): void
    {
        $start = new class extends Start {
            public int $checks = 0;

            public function waitForFastLocalCleanup(): bool
            {
                return $this->waitForRestartCleanupComplete('default', 9981, 4, 0, true);
            }

            protected function hasRestartCleanupResidue(
                string $instanceName,
                int $mainPort,
                int $workerCount,
                int $workerPort = 0,
                bool $fastLocal = false
            ): bool {
                unset($instanceName, $mainPort, $workerCount, $workerPort, $fastLocal);
                $this->checks++;

                return $this->checks < 2;
            }
        };

        self::assertTrue($start->waitForFastLocalCleanup());
        self::assertGreaterThanOrEqual(2, $start->checks);
    }

    public function testRestartHandoffIgnoresStalePortRowWhenBindProbeSucceeds(): void
    {
        $start = new class extends Start {
            public function blocked(int $port): bool
            {
                $this->restartHandoffPorts = [$port];

                return $this->isRestartHandoffPortBlocked($port);
            }
        };

        $port = 9986;
        $socket = @\stream_socket_server(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN
        );
        if (!\is_resource($socket)) {
            self::markTestSkipped('Unable to bind the restart handoff test port: ' . $errstr);
        }

        try {
            self::assertTrue($start->blocked($port));
        } finally {
            @\fclose($socket);
            \Weline\Framework\System\Process\Processer::clearPortCache($port);
        }

        self::assertFalse($start->blocked($port));
    }

    public function testMaintenanceModeHelpersSyncFrameworkAndWlsForTargetInstance(): void
    {
        $start = new class extends Start {
            public array $calls = [];

            protected function setFrameworkMaintenanceMode(bool $enabled): void
            {
                $this->calls[] = ['framework', $enabled];
            }

            protected function syncWlsMaintenanceMode(?string $instanceName, bool $enabled): void
            {
                $this->calls[] = ['wls', $instanceName, $enabled];
            }
        };

        $this->invokeProtected($start, 'enableMaintenanceMode', 'api');
        $this->invokeProtected($start, 'disableMaintenanceMode', 'api');

        self::assertSame(
            [
                ['framework', true],
                ['wls', 'api', true],
                ['framework', false],
                ['wls', 'api', false],
            ],
            $start->calls
        );
    }

    public function testHelpMentionsForceFullRestartSemantics(): void
    {
        $start = new Start();
        $help = (string) $start->help();

        self::assertStringContainsString('强制完整重启', $help);
        self::assertStringContainsString('跳过排水', $help);
        self::assertStringContainsString('-r -f', $help);
    }

    public function testPreferredControlPortMatchesMasterPortFormula(): void
    {
        $start = new Start();
        $mainPort = 443;

        self::assertSame(
            20000 + $mainPort + MasterProcess::getProjectPortOffset(),
            $this->invokeProtected($start, 'resolvePreferredControlPort', $mainPort)
        );
    }

    public function testRestartCleanupPrefixesCoverAllWlsRoles(): void
    {
        $start = new Start();
        $prefixes = $this->invokeProtected($start, 'getRestartCleanupProcessPrefixes', 'default');
        $joined = implode("\n", $prefixes);

        foreach ([
            'weline-wls-master',
            'weline-wls-dispatcher',
            'weline-wls-redirect',
            'weline-wls-worker',
            'weline-wls-maintenance',
            'weline-wls-runtime-watchdog',
        ] as $expectedPrefix) {
            self::assertStringContainsString($expectedPrefix, $joined);
        }
        self::assertStringNotContainsString('weline-wls-session', $joined);
        self::assertStringNotContainsString('weline-wls-memory', $joined);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
