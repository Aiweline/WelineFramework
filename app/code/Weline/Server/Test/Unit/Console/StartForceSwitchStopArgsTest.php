<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

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
        self::assertTrue((bool) ($args['force'] ?? false));
        self::assertTrue((bool) ($args['f'] ?? false));
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

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
