<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Memory\MemoryPressureStateMachine;

final class MemoryPressureStateMachineTest extends TestCase
{
    public function testRequiresThreeSamplesToUpgradeToYellow(): void
    {
        $fsm = new MemoryPressureStateMachine();
        $sample = $this->sample(0.75);

        $t1 = $fsm->tick($sample, 1.0);
        self::assertSame(MemoryPressureStateMachine::LEVEL_GREEN, $t1['level']);
        self::assertFalse($t1['changed']);

        $t2 = $fsm->tick($sample, 2.0);
        self::assertSame(MemoryPressureStateMachine::LEVEL_GREEN, $t2['level']);

        $t3 = $fsm->tick($sample, 3.0);
        self::assertSame(MemoryPressureStateMachine::LEVEL_YELLOW, $t3['level']);
        self::assertTrue($t3['changed']);
    }

    public function testGreenRecoverUsesHysteresisLine(): void
    {
        $fsm = new MemoryPressureStateMachine();
        // Force Yellow.
        for ($i = 0; $i < 3; $i++) {
            $fsm->tick($this->sample(0.75), (float)$i);
        }
        self::assertSame(MemoryPressureStateMachine::LEVEL_YELLOW, $fsm->getLevel());

        // Between 0.65 and 0.70: hold Yellow.
        $hold = $fsm->tick($this->sample(0.68), 10.0);
        self::assertSame(MemoryPressureStateMachine::LEVEL_YELLOW, $hold['level']);

        // Below 0.65: back to Green.
        $green = $fsm->tick($this->sample(0.60), 11.0);
        self::assertSame(MemoryPressureStateMachine::LEVEL_GREEN, $green['level']);
        self::assertTrue($green['changed']);
    }

    public function testGreenStreakAndRecoverFlag(): void
    {
        $fsm = new MemoryPressureStateMachine();
        $now = 100.0;
        for ($i = 0; $i < 9; $i++) {
            $tick = $fsm->tick($this->sample(0.20), $now + $i);
            self::assertFalse($tick['should_recover']);
        }
        $ready = $fsm->tick($this->sample(0.20), $now + 9);
        self::assertTrue($ready['should_recover']);
        self::assertGreaterThanOrEqual(10, $ready['green_streak']);
        $fsm->markRecover($now + 9);
        $after = $fsm->tick($this->sample(0.20), $now + 10);
        self::assertFalse($after['should_recover']);
    }

    public function testCriticalShrinkRequiresHoldAndCooldown(): void
    {
        $fsm = new MemoryPressureStateMachine();
        for ($i = 0; $i < 3; $i++) {
            $fsm->tick($this->sample(0.95), (float)$i);
        }
        self::assertSame(MemoryPressureStateMachine::LEVEL_CRITICAL, $fsm->getLevel());
        $first = $fsm->tick($this->sample(0.95), 10.0);
        self::assertTrue($first['should_shrink']);
        $fsm->markScaleDown(10.0);
        $second = $fsm->tick($this->sample(0.95), 20.0);
        self::assertFalse($second['should_shrink']);
        $third = $fsm->tick($this->sample(0.95), 45.0);
        self::assertTrue($third['should_shrink']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(float $pressure): array
    {
        return [
            'pressure_ratio' => $pressure,
            'pressure_source' => 'meminfo',
            'limit_mb' => 2048,
            'used_mb' => (int)\round(2048 * $pressure),
            'available_mb' => (int)\round(2048 * (1 - $pressure)),
            'mem_total_mb' => 2048,
            'swap_used_mb' => 0,
            'psi_some_avg10' => 0.0,
            'cgroup_max_mb' => null,
            'cgroup_current_mb' => null,
        ];
    }
}
