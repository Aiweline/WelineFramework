<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartCrossProjectIsolationTest extends TestCase
{
    public function testIsPortOccupiedByWelineProcessIgnoresForeignProjectScope(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pBBBBBBBB',
            inspect: [
                'in_use' => true,
                'pid' => 11111,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertFalse($start->checkOccupied(9981));
    }

    public function testIsPortOccupiedByWelineProcessTreatsOwnProjectScopeAsOccupied(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pAAAAAAAA',
            inspect: [
                'in_use' => true,
                'pid' => 22222,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertTrue($start->checkOccupied(9981));
    }

    public function testIsPortOccupiedByWelineProcessIgnoresUnscopedProcess(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pAAAAAAAA',
            inspect: [
                'in_use' => true,
                'pid' => 33333,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-worker-default',
                'scope' => '',
            ]
        );

        self::assertFalse($start->checkOccupied(9981));
    }

    public function testHasRestartCleanupResidueIgnoresForeignProjectPorts(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pBBBBBBBB',
            inspect: [
                'in_use' => true,
                'pid' => 44444,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertFalse($start->checkResidue('default', 9981, 4, 19981));
    }

    public function testHasRestartCleanupResidueDetectsOwnProjectPortResidue(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pAAAAAAAA',
            inspect: [
                'in_use' => true,
                'pid' => 55555,
                'pid_running' => true,
                'is_weline' => true,
                'state' => 'weline',
                'pname' => '--name=weline-wls-dispatcher-default-pAAAAAAAA',
                'scope' => 'pAAAAAAAA',
            ]
        );

        self::assertTrue($start->checkResidue('default', 9981, 4, 19981));
    }

    public function testHasRestartCleanupResidueIgnoresFreeNonWelinePorts(): void
    {
        $start = $this->createStartWithInspect(
            scopeToken: 'pAAAAAAAA',
            inspect: [
                'in_use' => false,
                'pid' => 0,
                'pid_running' => false,
                'is_weline' => false,
                'state' => 'free',
                'pname' => '',
                'scope' => '',
            ]
        );

        self::assertFalse($start->checkResidue('default', 9981, 4, 19981));
    }

    /**
     * @param array<string,mixed> $inspect
     */
    private function createStartWithInspect(string $scopeToken, array $inspect): Start
    {
        return new class($scopeToken, $inspect) extends Start {
            /** @param array<string,mixed> $inspect */
            public function __construct(private readonly string $scopeToken, private readonly array $inspect)
            {
            }

            public function checkOccupied(int $port): bool
            {
                return $this->isPortOccupiedByWelineProcess($port);
            }

            public function checkResidue(
                string $instanceName,
                int $mainPort,
                int $workerCount,
                int $workerPort = 0
            ): bool {
                return $this->hasRestartCleanupResidue($instanceName, $mainPort, $workerCount, $workerPort);
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);
                return $this->inspect;
            }

            protected function getCurrentProjectScopeToken(): string
            {
                return $this->scopeToken;
            }

            protected function getRestartCleanupProcessPrefixes(string $instanceName): array
            {
                unset($instanceName);
                return [];
            }

            protected function resolvePreferredControlPort(int $mainPort): int
            {
                return $mainPort + 10000;
            }
        };
    }
}
