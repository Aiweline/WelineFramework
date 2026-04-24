<?php
declare(strict_types=1);

namespace Weline\Server\Test\E2E;

/**
 * SSE 短轮询 E2E 手动校验脚本（Linux only）
 *
 * 注意：
 *   - 本文件 **不是** PHPUnit 测试。之前以 `*Test.php` 后缀命名导致 PHPUnit 试图加载，
 *     且底部 auto-run 在 include 时直接执行，在非 Linux 主机上 `pcntl_waitpid` 缺失即整批失败。
 *   - 现已改为 `*Check.php` 以脱离 PHPUnit 默认发现范围；并加入"仅当直接 CLI 入口"守护，
 *     即使被 require 也不会自动触发测试。
 *
 * 运行方式（Linux 主机）：
 *   ```bash
 *   php app/code/Weline/Server/Test/SseShortPollingE2ECheck.php
 *   ```
 *
 * 已知限制：
 *   - 使用 `pcntl_waitpid` / `ps` / `kill` / `/dev/null`，Windows 不兼容。
 *   - URL 硬编码指向作者本地开发环境，需要在当前机器上提前部署对应实例。
 */
class SseShortPollingE2ECheck
{
    /**
     * 测试 SSE 短轮询不阻塞其他请求
     */
    public function testSseDoesNotBlockOtherRequests(): bool
    {
        if (!\function_exists('pcntl_waitpid')) {
            echo "[SKIP] 需要 pcntl 扩展（Linux only）。\n";
            return true;
        }

        echo "=== SSE 短轮询 E2E 测试 ===\n\n";

        // 1. 启动 SSE 连接（后台）
        echo "1. 启动 SSE 连接（后台）...\n";
        $sseStart = microtime(true);
        $ssePid = $this->startSseConnection();
        echo "   SSE 连接已启动 (PID: $ssePid)\n\n";

        sleep(1);

        echo "2. 并发加载 10 个静态资源...\n";
        $resourceStart = microtime(true);
        $results = $this->loadResourcesConcurrently(10);
        $resourceEnd = microtime(true);
        $resourceTime = $resourceEnd - $resourceStart;

        echo "\n3. 等待 SSE 连接完成...\n";
        $this->waitForSse($ssePid, 10);
        $sseEnd = microtime(true);
        $sseTime = $sseEnd - $sseStart;

        echo "\n=== 测试结果 ===\n\n";

        echo "SSE 连接:\n";
        echo "  - 总耗时: " . round($sseTime, 2) . " 秒\n";
        echo "  - 预期: < 5 秒\n";
        echo "  - 状态: " . ($sseTime < 5 ? "[OK] 通过" : "[FAIL] 失败") . "\n\n";

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
        echo "  - 状态: " . ($successCount >= 9 && $avgTime < 2 ? "[OK] 通过" : "[FAIL] 失败") . "\n\n";

        $passed = ($sseTime < 5) && ($successCount >= 9) && ($avgTime < 2);
        echo "=== 总体结论 ===\n";
        echo $passed ? "[OK] 测试通过\n" : "[FAIL] 测试失败\n";
        echo "\n";

        if (!$passed) {
            echo "失败原因分析:\n";
            if ($sseTime >= 5) {
                echo "  - SSE 连接耗时过长 (" . round($sseTime, 2) . " 秒)\n";
                echo "    -> SSE 短轮询可能没有生效\n";
                echo "    -> 检查 AiSiteAgent.php 中的 \$maxPolls 是否为 3\n";
            }
            if ($successCount < 9) {
                echo "  - 静态资源加载失败率过高 ($failCount / 10)\n";
                echo "    -> Worker 可能被 SSE 连接阻塞\n";
                echo "    -> 检查 Worker 数量是否足够\n";
            }
            if ($avgTime >= 2) {
                echo "  - 静态资源加载耗时过长 (" . round($avgTime, 2) . " 秒)\n";
                echo "    -> Worker 可能被占用，请求排队等待\n";
            }
        }

        return $passed;
    }

    private function startSseConnection(): int
    {
        $url = "https://p11005ce4.weline.test/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/pagebuilder/backend/ai-site-agent/stream-sse?public_id=test&last_event_id=0";

        $cmd = sprintf(
            'curl -k "%s" -H "Accept: text/event-stream" -N -s > /dev/null 2>&1 & echo $!',
            $url
        );

        return (int) shell_exec($cmd);
    }

    /**
     * @return array<int, array{success: bool, http_code: int, time: float}>
     */
    private function loadResourcesConcurrently(int $count): array
    {
        $results = [];
        $pids = [];

        for ($i = 1; $i <= $count; $i++) {
            $url = "https://p11005ce4.weline.test/";
            $outputFile = sys_get_temp_dir() . "/sse_test_resource_$i.txt";

            $cmd = sprintf(
                'curl -k "%s" -o /dev/null -s -w "%%{http_code} %%{time_total}" --max-time 3 > %s 2>&1 & echo $!',
                $url,
                $outputFile
            );

            $pid = (int) shell_exec($cmd);
            $pids[$i] = ['pid' => $pid, 'output' => $outputFile];
        }

        foreach ($pids as $i => $info) {
            \pcntl_waitpid($info['pid'], $status);

            $output = @file_get_contents($info['output']);
            @unlink($info['output']);

            if ($output && preg_match('/^(\d+)\s+([\d.]+)$/', trim($output), $matches)) {
                $httpCode = (int) $matches[1];
                $time = (float) $matches[2];

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

    private function waitForSse(int $pid, int $timeout): bool
    {
        $start = time();

        while (time() - $start < $timeout) {
            $result = shell_exec("ps -p $pid -o pid=");
            if (empty(trim((string) $result))) {
                return true;
            }
            sleep(1);
        }

        shell_exec("kill $pid 2>/dev/null");
        return false;
    }
}

// 仅当本脚本被**直接 CLI 执行**时才自运行，避免被 PHPUnit / Composer 等 include 时触发。
if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && \realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    $test = new SseShortPollingE2ECheck();
    $passed = $test->testSseDoesNotBlockOtherRequests();
    exit($passed ? 0 : 1);
}
