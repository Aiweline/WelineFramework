<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\CapacityBudgetGate;

final class CapacityBudgetGateTest extends TestCase
{
    public function testRejectsWhenPoolSmallerThanFiberMaxActive(): void
    {
        $env = [
            'wls' => ['fiber' => ['max_active' => 12]],
            'db' => ['master' => ['pool_size' => 4, 'max_connections' => 200]],
        ];
        $result = CapacityBudgetGate::checkScaleUp('default', 3, $env, 200);
        self::assertFalse($result['ok']);
        self::assertSame('db_budget', $result['reason']);
    }

    public function testRejectsWhenClusterConnectionsInsufficient(): void
    {
        $env = [
            'wls' => ['fiber' => ['max_active' => 12]],
            'db' => ['master' => ['pool_size' => 12, 'max_connections' => 20]],
        ];
        // need 3*12=36 + reserve 8 > 20
        $result = CapacityBudgetGate::checkScaleUp('default', 3, $env, 20);
        self::assertFalse($result['ok']);
        self::assertSame('db_budget', $result['reason']);
        self::assertSame('db_cluster', $result['details']['gate'] ?? null);
    }

    public function testFailClosedWhenPgMaxUnknown(): void
    {
        $env = [
            'wls' => ['fiber' => ['max_active' => 12]],
            'db' => ['master' => ['pool_size' => 12]],
        ];
        $result = CapacityBudgetGate::checkScaleUp('default', 2, $env, null);
        self::assertFalse($result['ok']);
        self::assertSame('db_budget', $result['reason']);
        self::assertSame('db_budget_unknown', $result['details']['gate'] ?? null);
    }

    public function testRejectsSidecarAcceptBudget(): void
    {
        $env = [
            'wls' => ['fiber' => ['max_active' => 12]],
            'db' => ['master' => ['pool_size' => 12, 'max_connections' => 200]],
        ];
        $result = CapacityBudgetGate::checkScaleUp(
            'default',
            4,
            $env,
            200,
            CapacityBudgetGate::DEFAULT_RESERVED_CONNECTIONS,
            2,
            1,
        );
        self::assertFalse($result['ok']);
        self::assertSame('sidecar_budget', $result['reason']);
    }

    public function testAllowsHealthyBudget(): void
    {
        $env = [
            'wls' => ['fiber' => ['max_active' => 12]],
            'db' => ['master' => ['pool_size' => 12, 'max_connections' => 200]],
        ];
        $result = CapacityBudgetGate::checkScaleUp('default', 4, $env, 200);
        self::assertTrue($result['ok']);
        self::assertNull($result['reason']);
    }
}
