<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;

final class StartCommandDaemonModeTest extends TestCase
{
    public function testFrontendModeDoesNotForceForegroundExecution(): void
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

        self::assertTrue($start->daemonMode(['daemon' => true], true));
        self::assertFalse($start->daemonMode(['daemon' => false], true));
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
}
