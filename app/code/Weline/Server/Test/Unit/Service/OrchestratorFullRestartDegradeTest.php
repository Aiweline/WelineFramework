<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * 回归：整组重启熔断时必须降级为单槽复活，不能静默放弃。
 *
 * 线上事故形态：worker 因 credential 断言连年失败被杀，restarts 累加到上限后
 * escalateRecoveryFailureOrQuarantine 只请求整组重启；整组重启被 10 分钟/3 次
 * 熔断直接 return，于是「既不整组重启、也不单槽复活」，worker 池永久为 0，
 * 而 Master 仍报告自愈可用 —— 只能靠外部人工重启恢复。
 */
final class OrchestratorFullRestartDegradeTest extends TestCase
{
    public function testRequestFullRestartReportsAcceptanceForDegradeDecision(): void
    {
        $method = new ReflectionMethod(ServiceOrchestrator::class, 'requestFullRestart');
        $method->setAccessible(true);

        $returnType = $method->getReturnType();
        self::assertNotNull($returnType, 'requestFullRestart must report acceptance');
        self::assertSame('bool', (string)$returnType, 'requestFullRestart must return bool');
    }

    public function testEscalationFallsBackToSlotResurrectionWhenFullRestartRejected(): void
    {
        $file = (new \ReflectionClass(ServiceOrchestrator::class))->getFileName();
        self::assertIsString($file);
        $source = (string)\file_get_contents($file);

        $needle = '整组重启不可用，降级为单槽复活';
        self::assertStringContainsString(
            $needle,
            $source,
            'critical slot escalation must degrade to slot resurrection when full restart is rejected'
        );

        self::assertStringContainsString(
            '整组重启不可用，且单槽复活不可用，槽位保持失败',
            $source,
            'degrade path must remain observable when slot resurrection is also unavailable'
        );

        // 关键契约：降级前必须重置该槽的重启预算，否则下一轮自检仍会立刻
        // 再次命中 restarts>=max 并重复请求整组重启，形成同一死循环。
        $degradePos = \strpos($source, $needle);
        self::assertIsInt($degradePos);
        $window = \substr($source, \max(0, $degradePos - 1200), 1600);
        self::assertStringContainsString(
            'restarts = 0',
            $window,
            'degrade path must reset the slot restart budget before rescheduling'
        );
        self::assertStringContainsString(
            'scheduleResurrectionWithDelay',
            $window,
            'degrade path must actually schedule slot resurrection'
        );
    }

    public function testDegradeOnlyWhenFullRestartReportsRejection(): void
    {
        $file = (new \ReflectionClass(ServiceOrchestrator::class))->getFileName();
        self::assertIsString($file);
        $source = (string)\file_get_contents($file);

        self::assertStringContainsString(
            'if ($this->requestFullRestart($reason)) {',
            $source,
            'escalation must branch on the acceptance result, not fire-and-forget'
        );
    }
}
