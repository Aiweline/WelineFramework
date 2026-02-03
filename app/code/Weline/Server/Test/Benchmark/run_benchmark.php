<?php
declare(strict_types=1);

/**
 * Weline Server 压力测试脚本（简化版）
 * 
 * 用法:
 *   php run_benchmark.php [host] [port] [concurrency] [requests]
 * 
 * 示例:
 *   php run_benchmark.php 127.0.0.1 8888 10 100
 */

// 确保在 CLI 模式下运行
if (PHP_SAPI !== 'cli') {
    exit('Only CLI mode is supported');
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8888);
$concurrency = (int) ($argv[3] ?? 10);
$totalRequests = (int) ($argv[4] ?? 100);
$path = $argv[5] ?? '/';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║              Weline Server 压力测试                               ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  目标: http://{$host}:{$port}{$path}\n";
echo "║  并发: {$concurrency}\n";
echo "║  总请求: {$totalRequests}\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$results = [];
$errors = 0;
$startTime = microtime(true);

// 使用 curl_multi 进行并发压测
$url = "http://{$host}:{$port}{$path}";

$mh = curl_multi_init();
$handles = [];
$completed = 0;
$requestsSent = 0;

// 初始化并发请求
$batchSize = min($concurrency, $totalRequests);
for ($i = 0; $i < $batchSize; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[(int)$ch] = ['handle' => $ch, 'start' => microtime(true)];
    $requestsSent++;
}

$running = null;
$lastProgress = 0;

do {
    // 执行请求
    do {
        $status = curl_multi_exec($mh, $running);
    } while ($status == CURLM_CALL_MULTI_PERFORM);
    
    // 检查完成的请求
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $key = (int)$ch;
        
        if (isset($handles[$key])) {
            $elapsed = (microtime(true) - $handles[$key]['start']) * 1000; // ms
            
            if ($info['result'] === CURLE_OK) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 200 && $httpCode < 400) {
                    $results[] = $elapsed;
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
            
            $completed++;
            
            // 显示进度
            $progress = (int)($completed / $totalRequests * 100);
            if ($progress >= $lastProgress + 10) {
                echo "进度: {$progress}% ({$completed}/{$totalRequests})\n";
                $lastProgress = $progress;
            }
            
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);
            
            // 添加新请求
            if ($requestsSent < $totalRequests) {
                $newCh = curl_init($url);
                curl_setopt_array($newCh, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                ]);
                curl_multi_add_handle($mh, $newCh);
                $handles[(int)$newCh] = ['handle' => $newCh, 'start' => microtime(true)];
                $requestsSent++;
            }
        }
    }
    
    // 等待活动
    if ($running > 0) {
        curl_multi_select($mh, 0.01);
    }
    
} while ($running > 0 || count($handles) > 0);

curl_multi_close($mh);

$endTime = microtime(true);
$totalTime = $endTime - $startTime;

// 计算统计数据
$successCount = count($results);
$totalCompleted = $successCount + $errors;

if (!empty($results)) {
    sort($results);
    
    $avgTime = array_sum($results) / count($results);
    $minTime = min($results);
    $maxTime = max($results);
    $medianTime = $results[(int)(count($results) / 2)];
    $p95Index = min((int)(count($results) * 0.95), count($results) - 1);
    $p99Index = min((int)(count($results) * 0.99), count($results) - 1);
    $p95Time = $results[$p95Index];
    $p99Time = $results[$p99Index];
} else {
    $avgTime = $minTime = $maxTime = $medianTime = $p95Time = $p99Time = 0;
}

$qps = $totalTime > 0 ? $successCount / $totalTime : 0;
$errorRate = $totalCompleted > 0 ? ($errors / $totalCompleted) * 100 : 0;

// 打印报告
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                       压测结果报告                                ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
printf("║  总请求数:     %-40s ║\n", $totalCompleted);
printf("║  成功请求:     %-40s ║\n", $successCount);
printf("║  失败请求:     %-40s ║\n", $errors);
printf("║  错误率:       %-40s ║\n", round($errorRate, 2) . '%');
echo "╠══════════════════════════════════════════════════════════════════╣\n";
printf("║  总耗时:       %-40s ║\n", round($totalTime, 3) . ' 秒');
printf("║  QPS:          %-40s ║\n", round($qps, 2));
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  延迟统计 (毫秒)                                                  ║\n";
echo "║  ────────────────────────────────────────────────────────────────║\n";
printf("║  平均:         %-40s ║\n", round($avgTime, 3));
printf("║  最小:         %-40s ║\n", round($minTime, 3));
printf("║  最大:         %-40s ║\n", round($maxTime, 3));
printf("║  中位数:       %-40s ║\n", round($medianTime, 3));
printf("║  P95:          %-40s ║\n", round($p95Time, 3));
printf("║  P99:          %-40s ║\n", round($p99Time, 3));
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 保存报告
$report = [
    'total_requests' => $totalCompleted,
    'success_count' => $successCount,
    'error_count' => $errors,
    'error_rate' => round($errorRate, 2),
    'total_time_seconds' => round($totalTime, 3),
    'qps' => round($qps, 2),
    'latency_ms' => [
        'avg' => round($avgTime, 3),
        'min' => round($minTime, 3),
        'max' => round($maxTime, 3),
        'median' => round($medianTime, 3),
        'p95' => round($p95Time, 3),
        'p99' => round($p99Time, 3),
    ],
];

$reportDir = dirname(__DIR__, 2) . '/var/log';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0755, true);
}
$reportFile = $reportDir . '/benchmark_report_' . date('Ymd_His') . '.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
echo "报告已保存: {$reportFile}\n";
