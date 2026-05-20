<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class StopForeignProjectScopeTest extends TestCase
{
    public function testFindWelineServerInstanceNameByPortReturnsNullForForeignScope(): void
    {
        $stop = $this->createStop([
            'pid_running' => true,
            'is_weline' => true,
            'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
            'scope' => 'pAAAAAAAA',
        ]);

        self::assertNull($stop->findByPort(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsScopeForForeignScopeOnly(): void
    {
        $stop = $this->createStop([
            'pid_running' => true,
            'is_weline' => true,
            'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
            'scope' => 'pAAAAAAAA',
        ]);

        self::assertSame('pAAAAAAAA', $stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullForOwnScope(): void
    {
        $own = MasterProcess::getProjectScopeToken();
        $stop = $this->createStop([
            'pid_running' => true,
            'is_weline' => true,
            'pname' => '--name=weline-wls-dispatcher-default-' . $own,
            'scope' => $own,
        ]);

        self::assertNull($stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullWhenPortFree(): void
    {
        $stop = $this->createStop([
            'pid_running' => false,
            'is_weline' => false,
            'pname' => '',
            'scope' => '',
        ]);

        self::assertNull($stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullForUnscopedProcess(): void
    {
        $stop = $this->createStop([
            'pid_running' => true,
            'is_weline' => true,
            'pname' => '--name=weline-wls-worker-default',
            'scope' => '',
        ]);

        self::assertNull($stop->findForeign(9981));
    }

    /**
     * @param array<string,mixed> $inspect
     */
    private function createStop(array $inspect): Stop
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function findRunningInstanceNameByPort(int $port): ?string
            {
                unset($port);
                return null;
            }
        };

        return new class($manager, $inspect) extends Stop {
            /** @param array<string,mixed> $inspect */
            public function __construct(private readonly ServerInstanceManager $manager, private readonly array $inspect)
            {
            }

            public function findByPort(int $port): ?string
            {
                return $this->findWelineServerInstanceNameByPort($port);
            }

            public function findForeign(int $port): ?string
            {
                return $this->findForeignWelineServerScopeByPort($port);
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);
                return $this->inspect;
            }

            protected function isSharedStateIndexedPort(int $port): bool
            {
                unset($port);
                return false;
            }
        };
    }
}
