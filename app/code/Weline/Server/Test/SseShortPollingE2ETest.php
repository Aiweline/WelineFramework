<?php
declare(strict_types=1);

namespace Weline\Server\Test\E2E;

use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * SSE 短轮询 E2E 测试
 *
 * 模拟真实场景：
 * 1. 一个 SSE 长连接
 * 2. 多个并发的静态资源请求
 *
 * 预期结果：
 * - SSE 连接在 3 秒内完成
 * - 静态资源请求不被阻塞，正常返回
 */
class SseShortPollingE2ETest
{
    /**
     * 测试 SSE 短轮询不阻塞其他请求
     */
    public function testSseDoesNotBlockOtherRequests(): bool
    {
        echo "=== SSE 短轮询 E2E 测试 ===\n\n";

        // 1. 启动 SSE 连接（后台）
        echo "1. 启动 SSE 连接（后台）...\n";
        $sseStart = microtime(true);
        $ssePid = $this->startSseConnection();
        echo "   SSE 连接已启动 (PID: $ssePid)\n\n";

        // 等待 SSE 连接建立
        sleep(1);

        // 2. 并发加载静态资源
        echo "2. 并发加载 10 个静态资源...\n";
        $resourceStart = microtime(true);
        $results = $this->loadResourcesConcurrently(10);
        $resourceEnd = microtime(true);
        $resourceTime = $resourceEnd - $resourceStart;

        // 3. 等待 SSE 连接完成
        echo "\n3. 等待 SSE 连接完成...\n";
        $sseResult = $this->waitForSse($ssePid, 10);
        $sseEnd = microtime(true);
        $sseTime = $sseEnd - $sseStart;

        // 4. 分析结果
        echo "\n=== 测试结果 ===\n\n";

        // SSE 连接时间
        echo "SSE 连接:\n";
        echo "  - 总耗时: " . round($sseTime, 2) . " 秒\n";
        echo "  - 预期: < 5 秒\n";
        echo "  - 状态: " . ($sseTime < 5 ? "✅ 通过" : "❌ 失败") . "\n\n";

        // 静态资源加载
        $successCount = 0;
        $failCount = 0;
        $totalTime = 0;

        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
                $totalTime += $result['time'];
            } else {
                $failCount++;
            }
        }

        $avgTime = $successCount > 0 ? $totalTime / $successCount : 0;

        echo "静态资源加载:\n";
        echo "  - 成功: $successCount / 10\n";
        echo "  - 失败: $failCount / 10\n";
        echo "  - 平均耗时: " . round($avgTime, 2) . " 秒\n";
        echo "  - 总耗时: " . round($resourceTime, 2) . " 秒\n";
        echo "  - 预期: 成功率 > 90%, 平均耗时 < 2 秒\n";
        echo "  - 状态: " . ($successCount >= 9 && $avgTime < 2 ? "✅ 通过" : "❌ 失败") . "\n\n";

        // 总体结论
        $passed = ($sseTime < 5) && ($successCount >= 9) && ($avgTime < 2);
        echo "=== 总体结论 ===\n";
        echo $passed ? "✅ 测试通过\n" : "❌ 测试失败\n";
        echo "\n";

        if (!$passed) {
            echo "失败原因分析:\n";
            if ($sseTime >= 5) {
                echo "  - SSE 连接耗时过长 (" . round($sseTime, 2) . " 秒)\n";
                echo "    → SSE 短轮询可能没有生效\n";
                echo "    → 检查 AiSiteAgent.php 中的 \$maxPolls 是否为 3\n";
            }
            if ($successCount < 9) {
                echo "  - 静态资源加载失败率过高 ($failCount / 10)\n";
                echo "    → Worker 可能被 SSE 连接阻塞\n";
                echo "    → 检查 Worker 数量是否足够\n";
            }
            if ($avgTime >= 2) {
                echo "  - 静态资源加载耗时过长 (" . round($avgTime, 2) . " 秒)\n";
                echo "    → Worker 可能被占用，请求排队等待\n";
            }
        }

        // 返回测试结果
        return $passed;
    }

    /**
     * 启动 SSE 连接（后台进程）
     */
    private function startSseConnection(): int
    {
        $url = "https://p11005ce4.weline.test/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/pagebuilder/backend/ai-site-agent/stream-sse?public_id=test&last_event_id=0";

        $cmd = sprintf(
            'curl -k "%s" -H "Accept: text/event-stream" -N -s > /dev/null 2>&1 & echo $!',
            $url
        );

        $pid = (int)shell_exec($cmd);
        return $pid;
    }

    /**
     * 并发加载多个静态资源
     */
    private function loadResourcesConcurrently(int $count): array
    {
        $results = [];
        $pids = [];

        // 启动所有请求
        for ($i = 1; $i <= $count; $i++) {
            $url = "https://p11005ce4.weline.test/";
            $outputFile = sys_get_temp_dir() . "/sse_test_resource_$i.txt";

            $cmd = sprintf(
                'curl -k "%s" -o /dev/null -s -w "%%{http_code} %%{time_total}" --max-time 3 > %s 2>&1 & echo $!',
                $url,
                $outputFile
            );

            $pid = (int)shell_exec($cmd);
            $pids[$i] = ['pid' => $pid, 'output' => $outputFile];
        }

        // 等待所有请求完成
        foreach ($pids as $i => $info) {
            pcntl_waitpid($info['pid'], $status);

            $output = @file_get_contents($info['output']);
            @unlink($info['output']);

            if ($output && preg_match('/^(\d+)\s+([\d.]+)$/', trim($output), $matches)) {
                $httpCode = (int)$matches[1];
                $time = (float)$matches[2];

                $results[$i] = [
                    'success' => $httpCode === 200,
                    'http_code' => $httpCode,
                    'time' => $time,
                ];
            } else {
                $results[$i] = [
                    'success' => false,
                    'http_code' => 0,
                    'time' => 3.0,
                ];
            }
        }

        return $results;
    }

    /**
     * 等待 SSE 连接完成
     */
    private function waitForSse(int $pid, int $timeout): bool
    {
        $start = time();

        while (time() - $start < $timeout) {
            // 检查进程是否还在运行
            $result = shell_exec("ps -p $pid -o pid=");
            if (empty(trim($result))) {
                // 进程已结束
                return true;
            }

            sleep(1);
        }

        // 超时，强制终止
        shell_exec("kill $pid 2>/dev/null");
        return false;
    }
}

// 运行测试
if (PHP_SAPI === 'cli') {
    $test = new SseShortPollingE2ETest();
    $passed = $test->testSseDoesNotBlockOtherRequests();
    exit($passed ? 0 : 1);
}
