<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

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

    public function testHelpMentionsMaintenanceForForceSwitch(): void
    {
        $start = new Start();
        $help = (string) $start->help();

        self::assertStringContainsString('维护模式', $help);
        self::assertStringContainsString('停机型更新', $help);
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
            'weline-wls-session',
            'weline-wls-memory',
            'weline-wls-redirect',
            'weline-wls-worker',
            'weline-wls-maintenance',
        ] as $expectedPrefix) {
            self::assertStringContainsString($expectedPrefix, $joined);
        }
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
