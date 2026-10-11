<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\CapacityAdvisor;
use Weline\Server\Service\Capacity\CapacityAdvisorConfigService;

final class CapacityAdvisorEvaluateContractTest extends TestCase
{
    protected function setUp(): void
    {
        CapacityAdvisor::resetRingsForTests();
    }

    public function testSustainedSoftTriggersAdviceWithoutScaleApi(): void
    {
        $advisor = new CapacityAdvisor();
        $config = CapacityAdvisorConfigService::defaults();
        $config['sustained_soft_sample_ratio'] = 0.5;
        $config['soft_pressure'] = 0.70;
        $now = 1_000_000.0;
        $result = null;
        for ($i = 0; $i < 6; $i++) {
            $result = $advisor->evaluate(
                'default',
                [[
                    'report' => [
                        'memory_percent' => 0.85,
                        'connections' => 2,
                        'active_requests' => 1,
                        'compact_soft_count' => 0,
                        'compact_hard_count' => 0,
                    ],
                    'at' => $now + $i,
                ]],
                0.2,
                0.5,
                $config,
                $now + $i,
            );
        }
        self::assertNotNull($result);
        self::assertTrue($result['triggered']);
        self::assertContains('sustained_soft', $result['reason_codes']);
        self::assertContains('raise_worker_memory_limit', $result['advice']);
    }

    public function testRunnerSourceDoesNotCallScale(): void
    {
        $serverRoot = \dirname(__DIR__, 4);
        $runnerFile = $serverRoot . '/Service/Capacity/CapacityAdviseRunner.php';
        $advisorFile = $serverRoot . '/Service/Capacity/CapacityAdvisor.php';
        self::assertFileExists($runnerFile);
        self::assertFileExists($advisorFile);
        $runner = (string)\file_get_contents($runnerFile);
        $advisor = (string)\file_get_contents($advisorFile);
        self::assertStringNotContainsString('ACTION_SCALE_WORKERS', $runner);
        self::assertStringNotContainsString('scaleDown', $runner);
        self::assertStringNotContainsString('scaleUp', $runner);
        self::assertStringNotContainsString('ACTION_SCALE_WORKERS', $advisor);
        self::assertStringNotContainsString('Env::set', $runner);
        self::assertStringNotContainsString('env.php', $runner);
    }

    public function testConfigServiceDefaultsHaveFloorInterval(): void
    {
        $svc = new CapacityAdvisorConfigService(null);
        $cfg = $svc->get();
        self::assertGreaterThanOrEqual(60, (int)$cfg['interval_sec']);
        self::assertTrue((bool)$cfg['enabled']);
    }

    public function testFiberAdmissionSaturatedAdvisesRaiseWorkerCount(): void
    {
        $advisor = new CapacityAdvisor();
        $config = CapacityAdvisorConfigService::defaults();
        $result = $advisor->evaluate(
            'default',
            [[
                'report' => [
                    'last_pressure' => 0.40,
                    'connections' => 4,
                    'active_requests' => 2,
                    'compact_soft_count' => 0,
                    'compact_hard_count' => 0,
                    'fiber_admission_saturated' => 1,
                ],
                'at' => 1_000_000.0,
            ]],
            0.2,
            0.5,
            $config,
            1_000_000.0,
        );
        self::assertTrue($result['triggered']);
        self::assertContains('fiber_admission_saturated', $result['reason_codes']);
        self::assertContains('raise_worker_count', $result['advice']);
        self::assertSame(1, $result['sample']['fiber_admission_saturated_workers']);
    }
}
