<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * 复活前置 fence 的退避、reason 拆分与整组重启熔断契约。
 *
 * 背景：生产上 fence 重试原为固定 1 秒、整组重启冷却仅 10 秒且没有窗内熔断，
 * 故障持续时会演变成重启风暴；同时终止旧代次只杀根 PID，导致仍持有
 * listen socket 的子进程存活、端口永不释放。
 */
final class ServiceOrchestratorResurrectionFenceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', true);
        }
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    /**
     * @return ServiceOrchestrator&object{
     *     delayFor: callable,
     *     circuitOpenAt: callable,
     *     recordRestartAt: callable,
     *     treeTermination: callable
     * }
     */
    private function orchestrator(): ServiceOrchestrator
    {
        return new class extends ServiceOrchestrator {
            public function delayFor(int $fenceAttempt): float
            {
                return $this->fenceRetryDelaySeconds($fenceAttempt);
            }

            public function circuitOpenAt(float $now): bool
            {
                return $this->fullRestartCircuitOpen($now);
            }

            public function recordRestartAt(float $now): void
            {
                $this->recordFullRestartAt($now);
            }

            public function treeTermination(): bool
            {
                return $this->shouldTerminateResurrectionTree();
            }
        };
    }

    public function testFenceRetryDelayGrowsExponentiallyAndStaysWithinJitterBounds(): void
    {
        $orchestrator = $this->orchestrator();

        // 指数基线 1s → 2s → 4s → 8s，抖动不超过 ±10%。
        $expectedBaselines = [1 => 1.0, 2 => 2.0, 3 => 4.0, 4 => 8.0];
        foreach ($expectedBaselines as $attempt => $baseline) {
            $delay = $orchestrator->delayFor($attempt);
            self::assertGreaterThanOrEqual(
                $baseline * 0.9,
                $delay,
                "第 {$attempt} 次 fence 等待不应低于基线的 90%"
            );
            self::assertLessThanOrEqual(
                $baseline * 1.1,
                $delay,
                "第 {$attempt} 次 fence 等待不应高于基线的 110%"
            );
        }
    }

    public function testFenceRetryDelayIsStrictlyIncreasingBeforeTheCap(): void
    {
        $orchestrator = $this->orchestrator();

        $previous = 0.0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $delay = $orchestrator->delayFor($attempt);
            self::assertGreaterThan(
                $previous,
                $delay,
                "退避必须单调递增，第 {$attempt} 次未超过第 " . ($attempt - 1) . ' 次'
            );
            $previous = $delay;
        }
    }

    public function testFenceRetryDelayIsCappedAndNeverZero(): void
    {
        $orchestrator = $this->orchestrator();

        foreach ([1, 5, 10, 30, 1000] as $attempt) {
            $delay = $orchestrator->delayFor($attempt);
            self::assertGreaterThan(0.0, $delay, '退避必须为正，否则会形成热循环');
            self::assertLessThanOrEqual(33.0, $delay, '退避必须封顶在 30s（含抖动）');
        }

        // 到达上限后不再增长
        self::assertEqualsWithDelta($orchestrator->delayFor(50), $orchestrator->delayFor(500), 6.0);
    }

    public function testFullRestartCircuitOpensAfterWindowMaximumAndExpiresOutsideWindow(): void
    {
        $orchestrator = $this->orchestrator();
        $now = 1_000_000.0;

        self::assertFalse($orchestrator->circuitOpenAt($now), '初始不应处于熔断态');

        $orchestrator->recordRestartAt($now);
        $orchestrator->recordRestartAt($now + 1.0);
        self::assertFalse($orchestrator->circuitOpenAt($now + 2.0), '未达窗内上限时不应熔断');

        $orchestrator->recordRestartAt($now + 2.0);
        self::assertTrue($orchestrator->circuitOpenAt($now + 3.0), '达到窗内上限后必须熔断');

        // 窗口滑出后自动恢复
        self::assertFalse(
            $orchestrator->circuitOpenAt($now + 601.0),
            '窗口滑出后应自动关闭熔断，避免永久拒绝恢复'
        );
    }

    public function testResurrectionTerminatesTheWholeProcessTree(): void
    {
        // 只杀根 PID 会让仍持有 listen socket 的子进程存活，端口永不释放。
        self::assertTrue(
            $this->orchestrator()->treeTermination(),
            '复活前终止旧代次必须连带进程树'
        );
    }
}
