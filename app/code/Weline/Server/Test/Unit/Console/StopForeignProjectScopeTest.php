<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * 验证 Stop 命令对端口归属做严格的项目作用域校验，不会冒充自家实例名去停外项目。
 *
 * @group server-cross-project-isolation
 */
final class StopForeignProjectScopeTest extends TestCase
{
    public function testFindWelineServerInstanceNameByPortReturnsNullForForeignScope(): void
    {
        $stop = $this->createStop(
            inspect: [
                'pid_running' => true,
                'is_weline' => true,
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertNull(
            $stop->findByPort(9981),
            '外项目作用域的 WLS 占用端口时，不得以"自家实例名"返回，避免 -r -f 流程误停外项目。'
        );
    }

    public function testFindForeignWelineServerScopeByPortReturnsScopeForForeignScopeOnly(): void
    {
        $stop = $this->createStop(
            inspect: [
                'pid_running' => true,
                'is_weline' => true,
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertSame('pAAAAAAAA', $stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullForOwnScope(): void
    {
        $own = MasterProcess::getProjectScopeToken();
        $stop = $this->createStop(
            inspect: [
                'pid_running' => true,
                'is_weline' => true,
                'pname' => '--name=weline-wls-dispatcher-default-' . $own,
                'scope' => $own,
            ]
        );

        self::assertNull($stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullWhenPortFree(): void
    {
        $stop = $this->createStop(
            inspect: [
                'pid_running' => false,
                'is_weline' => false,
                'pname' => '',
                'scope' => '',
            ]
        );

        self::assertNull($stop->findForeign(9981));
    }

    public function testFindForeignWelineServerScopeByPortReturnsNullForLegacyScopelessProcess(): void
    {
        $stop = $this->createStop(
            inspect: [
                'pid_running' => true,
                'is_weline' => true,
                'pname' => '--name=weline-master-default-worker-1',
                'scope' => '',
            ]
        );

        self::assertNull(
            $stop->findForeign(9981),
            '老版本无作用域段的 weline 进程不算外项目，留给现有兼容路径处理。'
        );
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
