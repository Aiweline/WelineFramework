<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 停机协议与退出证明的不变量。
 *
 * 背景：原实现无论强制终止是否成功，都会清理 PID 文件并把槽位标记为
 * STOPPED / 清零 PID 树。未编译 pidfd 的 Linux 上终止常常拿不到退出证明，
 * 于是台账说"已停止"、OS 里进程仍在监听端口，槽位被判定可重新拉起，
 * 造成端口冲突、重复代次与整组重启风暴。
 */
final class ServiceOrchestratorStopExitProofTest extends TestCase
{
    private function source(): string
    {
        return (string)\file_get_contents(
            \dirname(__DIR__, 7) . '/app/code/Weline/Server/Service/ServiceOrchestrator.php',
        );
    }

    /** 按方法签名起点截取到下一个方法声明，避免行号漂移导致断言失效。 */
    private function methodSource(string $source, string $signature): string
    {
        $start = \strpos($source, $signature);
        self::assertIsInt($start, "未找到方法：{$signature}");

        $end = \strpos($source, "\n    private function ", $start + \strlen($signature));
        if ($end === false) {
            $end = \strlen($source);
        }

        return \substr($source, $start, $end - $start);
    }

    public function testStopProtocolRequiresExitProofBeforeReportingStopped(): void
    {
        $method = $this->methodSource($this->source(), 'private function stopInstanceWithProtocol(');

        $guardPos = \strpos($method, 'if (!$exitProven)');
        $cleanupPos = \strpos($method, '$this->cleanupInstancePidFile($instance);');
        self::assertIsInt($guardPos, '停机协议必须依据退出证明分支');
        self::assertIsInt($cleanupPos, '停机协议应清理 PID 文件');
        self::assertLessThan(
            $cleanupPos,
            $guardPos,
            '必须先判定退出证明，再清理 PID 文件并标记已停止',
        );
        self::assertStringContainsString('termination_unproven_at', $method);
    }

    public function testWaitForInstanceExitReturnsProofAndInvalidatesLivenessCache(): void
    {
        $method = $this->methodSource($this->source(), 'private function waitForInstanceExit(');

        self::assertStringContainsString('): bool', $method, '等待退出必须返回退出证明');
        // 终止后必须失效存活缓存，否则 5 秒 TTL 内会把已退出进程当作存活。
        self::assertStringContainsString(
            'unset($this->processRunningCache[$trackingPid]);',
            $method,
            '终止后必须失效存活缓存',
        );
        self::assertStringContainsString('return $released && !$this->isProcessRunning($trackingPid);', $method);
    }

    public function testQuarantineKeepsPidEvidenceWhenTerminationIsUnproven(): void
    {
        $method = $this->methodSource(
            $this->source(),
            'private function escalateRecoveryFailureOrQuarantine(',
        );

        self::assertStringContainsString('$terminationProven = $this->killInstanceProcess($instance);', $method);
        self::assertStringContainsString('if ($terminationProven) {', $method);
        self::assertStringContainsString('termination_unproven_pid', $method);

        // 清零 PID 树必须只在已获退出证明的分支里发生。
        $provenPos = \strpos($method, 'if ($terminationProven) {');
        $clearPos = \strpos($method, '$instance->setProcessTreePids(0, 0, 0);');
        self::assertIsInt($provenPos);
        self::assertIsInt($clearPos);
        self::assertLessThan($clearPos, $provenPos, '未获退出证明时不得清零 PID 树');
    }
}
