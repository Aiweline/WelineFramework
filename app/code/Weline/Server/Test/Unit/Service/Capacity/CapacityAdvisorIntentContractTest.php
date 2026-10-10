<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\CapacityAdvisor;
use Weline\Server\Service\Capacity\CapacityAdvisorConfigService;
use Weline\Server\Service\Capacity\CapacityIntent;

final class CapacityAdvisorIntentContractTest extends TestCase
{
    protected function setUp(): void
    {
        CapacityAdvisor::resetRingsForTests();
    }

    public function testHostPressureForcesNoopEvenWhenBusy(): void
    {
        $advisor = new CapacityAdvisor();
        $config = CapacityAdvisorConfigService::defaults();
        $config['sustained_soft_sample_ratio'] = 0.5;
        $config['soft_pressure'] = 0.70;
        $now = 1_000_000.0;
        $intent = null;
        for ($i = 0; $i < 6; $i++) {
            $intent = $advisor->evaluateIntent(
                'default',
                [[
                    'report' => [
                        'memory_percent' => 0.85,
                        'connections' => 2,
                        'active_requests' => 4,
                        'compact_soft_count' => 0,
                        'compact_hard_count' => 0,
                    ],
                    'at' => $now + $i,
                ]],
                0.95,
                0.05,
                $config,
                $now + $i,
                [
                    'current_desired' => 2,
                    'baseline' => 2,
                    'max_workers' => 8,
                    'auto_scale_enabled' => true,
                ],
            );
        }
        self::assertInstanceOf(CapacityIntent::class, $intent);
        self::assertSame(CapacityIntent::ACTION_NOOP, $intent->action);
        self::assertSame('host_pressure', $intent->reason);
    }

    public function testBusyWithHostHeadroomProducesScaleTo(): void
    {
        CapacityAdvisor::resetRingsForTests();
        $advisor = new CapacityAdvisor();
        $config = CapacityAdvisorConfigService::defaults();
        $config['sustained_soft_sample_ratio'] = 0.5;
        $config['soft_pressure'] = 0.70;
        $now = 2_000_000.0;
        $intent = null;
        for ($i = 0; $i < 6; $i++) {
            $intent = $advisor->evaluateIntent(
                'default',
                [[
                    'report' => [
                        'memory_percent' => 0.85,
                        'connections' => 2,
                        'active_requests' => 4,
                        'compact_soft_count' => 0,
                        'compact_hard_count' => 0,
                    ],
                    'at' => $now + $i,
                ]],
                0.2,
                0.5,
                $config,
                $now + $i,
                [
                    'current_desired' => 2,
                    'baseline' => 2,
                    'max_workers' => 8,
                    'auto_scale_enabled' => true,
                ],
            );
        }
        self::assertInstanceOf(CapacityIntent::class, $intent);
        self::assertTrue($intent->isScale());
        self::assertSame(3, $intent->target);
        self::assertSame('workers_busy_host_headroom', $intent->reason);
    }

    public function testRunnerStillMustNeverCallScaleApis(): void
    {
        $serverRoot = \dirname(__DIR__, 4);
        $runner = (string)\file_get_contents($serverRoot . '/Service/Capacity/CapacityAdviseRunner.php');
        $advisor = (string)\file_get_contents($serverRoot . '/Service/Capacity/CapacityAdvisor.php');
        self::assertStringNotContainsString('updateRuntimeDesiredWorkerCount', $runner);
        self::assertStringNotContainsString('updateDesiredWorkerCount', $runner);
        self::assertStringNotContainsString('CapacityScaleApplier', $runner);
        self::assertStringNotContainsString('updateRuntimeDesiredWorkerCount', $advisor);
        self::assertStringNotContainsString('desiredState', $advisor);
    }
}
