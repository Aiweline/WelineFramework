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

    public function testWindowFlagRequiresExplicitTruthyValue(): void
    {
        $start = new class extends Start {
            public function windowFlag(array $args): bool
            {
                return $this->resolveWindowModeFlag($args);
            }
        };
        $start->__init();

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/w', 'server:start'];
        try {
            self::assertFalse($start->windowFlag(['win' => false]));
            self::assertFalse($start->windowFlag(['frontend' => true]));
            self::assertFalse($start->windowFlag(['win' => '0']));
            self::assertTrue($start->windowFlag(['win' => true]));
            self::assertTrue($start->windowFlag([0 => '--win']));
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function testWindowFlagFallsBackToRawArgv(): void
    {
        $start = new class extends Start {
            public function windowFlag(array $args): bool
            {
                return $this->resolveWindowModeFlag($args);
            }
        };
        $start->__init();

        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/w', 'server:start', '--win'];
        try {
            self::assertTrue($start->windowFlag([]));
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
            public function masterArgv(
                string $phpBinary,
                string $script,
                string $instanceName,
                string $masterName,
                bool $foregroundMode,
                bool $windowMode
            ): array {
                return $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $foregroundMode, $windowMode);
            }

        };
        $start->__init();

        $masterName = MasterProcess::getMasterProcessName('default');
        $displayName = MasterProcess::getMasterProcessDisplayName('default', true);

        $argv = $start->masterArgv('php', 'bin/w', 'default', $masterName, false, true);

        self::assertContains('--win', $argv);
        self::assertContains('--name=' . $masterName, $argv);
        self::assertContains('--window-title=' . $displayName, $argv);
    }

    public function testNonFrontendMasterBackgroundLaunchKeepsStableIdentityOnly(): void
    {
        $start = new class extends Start {
            /**
             * @return list<string>
             */
            public function masterArgv(
                string $phpBinary,
                string $script,
                string $instanceName,
                string $masterName,
                bool $foregroundMode,
                bool $windowMode
            ): array {
                return $this->buildMasterBackgroundArgv($phpBinary, $script, $instanceName, $masterName, $foregroundMode, $windowMode);
            }

        };
        $start->__init();

        $masterName = MasterProcess::getMasterProcessName('default');

        $argv = $start->masterArgv('php', 'bin/w', 'default', $masterName, false, false);

        self::assertContains('--master-only', $argv);
        self::assertContains('--name=' . $masterName, $argv);
        self::assertNotContains('--win', $argv);
        self::assertNotContains('--window-title=' . MasterProcess::getMasterProcessDisplayName('default', true), $argv);
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

        self::assertSame(45678, $start->persistLauncherPid('default', 'php bin/w server:start --win'));
        self::assertSame([
            ['default', ['launcher_pid' => 45678]],
        ], $manager->saved);
    }
}
