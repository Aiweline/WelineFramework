<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Console\Server\StartupChangingArgsInspector;

final class StartupChangingArgsInspectorTest extends TestCase
{
    public function testDetectsPortAndTopologyChangingArgs(): void
    {
        $probe = new StartupChangingArgsInspectorProbe();

        self::assertTrue($probe->inspect(['r' => true, 'p' => 9987]));
        self::assertTrue($probe->inspect(['direct' => true]));
        self::assertTrue($probe->inspect(['ssl-cert' => '/tmp/cert.pem']));
    }

    public function testIgnoresRestartFlagAlone(): void
    {
        $probe = new StartupChangingArgsInspectorProbe();

        self::assertFalse($probe->inspect(['r' => true]));
        self::assertFalse($probe->inspect(['restart' => true, 'd' => true]));
    }
}

final class StartupChangingArgsInspectorProbe
{
    use StartupChangingArgsInspector;

    /**
     * @param array<string, mixed> $args
     */
    public function inspect(array $args): bool
    {
        return $this->hasStartupChangingArgs($args);
    }
}

final class StartRestartDelegationTest extends TestCase
{
    public function testRollingRestartDecisionRequiresRunningInstanceWithoutForceOrChangingArgs(): void
    {
        $probe = new StartRestartDelegationProbe();

        self::assertTrue($probe->shouldDelegateRollingRestart(
            instanceRunning: true,
            forceRestart: true,
            forceSwitch: false,
            args: ['r' => true],
        ));
        self::assertFalse($probe->shouldDelegateRollingRestart(
            instanceRunning: false,
            forceRestart: true,
            forceSwitch: false,
            args: ['r' => true],
        ));
        self::assertFalse($probe->shouldDelegateRollingRestart(
            instanceRunning: true,
            forceRestart: true,
            forceSwitch: true,
            args: ['r' => true, 'f' => true],
        ));
        self::assertFalse($probe->shouldDelegateRollingRestart(
            instanceRunning: true,
            forceRestart: true,
            forceSwitch: false,
            args: ['r' => true, 'p' => 9987],
        ));
    }

    public function testHelpDocumentsRollingRestartAndForceFullRestartSemantics(): void
    {
        $start = new Start();
        $help = (string) $start->help();

        self::assertStringContainsString('滚动排水重启', $help);
        self::assertStringContainsString('强制完整重启', $help);
        self::assertStringContainsString('-r -f', $help);
    }
}

/**
 * Mirrors the early-return guard in Start::execute() for rolling restart delegation.
 */
final class StartRestartDelegationProbe
{
    use StartupChangingArgsInspector;

    /**
     * @param array<string, mixed> $args
     */
    public function shouldDelegateRollingRestart(
        bool $instanceRunning,
        bool $forceRestart,
        bool $forceSwitch,
        array $args,
    ): bool {
        return $instanceRunning
            && $forceRestart
            && !$forceSwitch
            && !$this->hasStartupChangingArgs($args);
    }
}
