<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class StartCommandDaemonModeTest extends TestCase
{
    public function testFrontendModeForcesForegroundExecution(): void
    {
        $start = new class extends Start {
            /**
             * @param array<string, mixed> $config
             */
            public function daemonMode(array $config, bool $frontend): bool
            {
                return $this->resolveDaemonMode($config, $frontend);
            }
        };
        $start->__init();

        self::assertFalse($start->daemonMode(['daemon' => true], true));
        self::assertFalse($start->daemonMode(['daemon' => false], true));
        self::assertTrue($start->daemonMode(['daemon' => true], false));
    }

    public function testFrontendFlagRequiresExplicitTruthyValue(): void
    {
        $start = new class extends Start {
            public function frontendFlag(array $args): bool
            {
                return $this->resolveFrontendFlag($args);
            }
        };
        $start->__init();

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/w', 'server:start'];
        try {
            self::assertFalse($start->frontendFlag(['frontend' => false]));
            self::assertFalse($start->frontendFlag(['foreground' => 'false']));
            self::assertFalse($start->frontendFlag(['frontend' => '0']));
            self::assertTrue($start->frontendFlag(['frontend' => true]));
            self::assertTrue($start->frontendFlag([0 => '--frontend']));
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function testFrontendFlagFallsBackToRawArgv(): void
    {
        $start = new class extends Start {
            public function frontendFlag(array $args): bool
            {
                return $this->resolveFrontendFlag($args);
            }
        };
        $start->__init();

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/w', 'server:start', '--foreground'];
        try {
            self::assertTrue($start->frontendFlag([]));
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function testFrontendMasterBackgroundLaunchCarriesFrontendIdentity(): void
    {
        $start = new class extends Start {
            /**
             * @return list<string>
             */
            public function masterArgv(string $phpBinary, string $script, string $instanceName, string $masterName, bool $frontend): array
            {
                return $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $frontend);
            }

            public function masterCommand(string $phpBinary, string $script, string $instanceName, string $masterName, bool $frontend): string
            {
                return $this->buildMasterBackgroundCommand($phpBinary, $script, $instanceName, $masterName, $frontend);
            }
        };
        $start->__init();

        $masterName = MasterProcess::getMasterProcessName('default');
        $displayName = MasterProcess::getMasterProcessDisplayName('default', true);

        $argv = $start->masterArgv('php', 'bin/w', 'default', $masterName, true);
        $command = $start->masterCommand('php', 'bin/w', 'default', $masterName, true);

        self::assertContains('--frontend', $argv);
        self::assertContains('--name=' . $masterName, $argv);
        self::assertContains('--window-title=' . $displayName, $argv);
        self::assertStringContainsString('--frontend', $command);
        self::assertStringContainsString('--name=', $command);
        self::assertStringContainsString($masterName, $command);
        self::assertStringContainsString('--window-title=', $command);
        self::assertStringContainsString($displayName, $command);
    }

    public function testNonFrontendMasterBackgroundLaunchKeepsStableIdentityOnly(): void
    {
        $start = new class extends Start {
            /**
             * @return list<string>
             */
            public function masterArgv(string $phpBinary, string $script, string $instanceName, string $masterName, bool $frontend): array
            {
                return $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $frontend);
            }

            public function masterCommand(string $phpBinary, string $script, string $instanceName, string $masterName, bool $frontend): string
            {
                return $this->buildMasterBackgroundCommand($phpBinary, $script, $instanceName, $masterName, $frontend);
            }
        };
        $start->__init();

        $masterName = MasterProcess::getMasterProcessName('default');

        $argv = $start->masterArgv('php', 'bin/w', 'default', $masterName, false);
        $command = $start->masterCommand('php', 'bin/w', 'default', $masterName, false);

        self::assertContains('--master-only', $argv);
        self::assertContains('--name=' . $masterName, $argv);
        self::assertNotContains('--frontend', $argv);
        self::assertNotContains('--window-title=' . MasterProcess::getMasterProcessDisplayName('default', true), $argv);
        self::assertStringNotContainsString('--frontend', $command);
        self::assertStringNotContainsString('--window-title=', $command);
    }

    public function testPersistForegroundLauncherPidStoresWrapperPidFromProcessMetadata(): void
    {
        $manager = new class extends ServerInstanceManager {
            public array $saved = [];

            public function __construct()
            {
            }

            public function saveInstance(string $name, array $info): void
            {
                $this->saved[] = [$name, $info];
            }
        };

        $start = new class($manager) extends Start {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function getManagedProcessMetadata(string $command): array
            {
                unset($command);

                return ['launcher_pid' => 45678];
            }

            public function persistLauncherPid(string $instanceName, string $command, int $fallbackPid = 0): int
            {
                return $this->persistForegroundLauncherPid($instanceName, $command, $fallbackPid);
            }
        };
        $start->__init();

        self::assertSame(45678, $start->persistLauncherPid('default', 'php bin/w server:start --frontend'));
        self::assertSame([
            ['default', ['launcher_pid' => 45678]],
        ], $manager->saved);
    }
}
