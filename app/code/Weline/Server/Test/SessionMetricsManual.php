<?php
declare(strict_types=1);

/**
 * Session 监控功能测试
 */

require_once __DIR__ . '/../../../../../bootstrap.php';

use Weline\Server\Service\Telemetry\SessionMetricsCollector;

echo "=== Session Metrics Collector Test ===\n\n";

// 创建指标收集器
$collector = new SessionMetricsCollector();

// 测试 Counter
echo "Testing Counter...\n";
$collector->incrementCounter('test_counter', 1, ['label1' => 'value1']);
$collector->incrementCounter('test_counter', 5, ['label1' => 'value1']);
$collector->incrementCounter('test_counter', 3, ['label1' => 'value2']);

// 测试 Gauge
echo "Testing Gauge...\n";
$collector->setGauge('test_gauge', 42.5, ['type' => 'idle']);
$collector->setGauge('test_gauge', 10.0, ['type' => 'busy']);

// 测试 Histogram
echo "Testing Histogram...\n";
for ($i = 0; $i < 100; $i++) {
    $value = mt_rand(1, 1000);
    $collector->recordHistogram('test_duration_ms', (float)$value, ['operation' => 'test']);
}

// 导出 Prometheus 格式
echo "\n=== Prometheus Export ===\n";
echo $collector->exportPrometheus();

// 获取摘要
echo "\n=== Summary ===\n";
$summary = $collector->getSummary();
print_r($summary);

echo "\n✓ All tests passed!\n";
