#!/usr/bin/env php
<?php
/**
 * WLS PHP 8.4 性能基准测试脚本
 *
 * 用于对比优化前后的性能差异
 *
 * 使用方法：
 * php dev/ai/wls-performance-benchmark.php
 *
 * 测试项目：
 * 1. 数组访问性能（类型化 vs 非类型化）
 * 2. foreach 迭代性能
 * 3. 函数调用性能（类型化参数）
 * 4. 内存分配性能
 * 5. LRU 淘汰算法性能
 */

declare(strict_types=1);

// 测试配置
const ITERATIONS = 100000;
const ARRAY_SIZE = 1000;

// 颜色输出
function color(string $text, string $color): string
{
    $colors = [
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'red' => "\033[31m",
        'blue' => "\033[34m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function benchmark(string $name, callable $fn, int $iterations = ITERATIONS): array
{
    // 预热
    for ($i = 0; $i < 100; $i++) {
        $fn();
    }

    // 清理 GC
    gc_collect_cycles();

    $memBefore = memory_get_usage(true);
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }

    $elapsed = microtime(true) - $start;
    $memAfter = memory_get_usage(true);
    $memUsed = $memAfter - $memBefore;

    return [
        'time' => $elapsed,
        'ops_per_sec' => $iterations / $elapsed,
        'memory' => $memUsed,
        'avg_time_us' => ($elapsed / $iterations) * 1_000_000,
    ];
}

function formatNumber(float $num): string
{
    if ($num >= 1_000_000) {
        return number_format($num / 1_000_000, 2) . 'M';
    }
    if ($num >= 1_000) {
        return number_format($num / 1_000, 2) . 'K';
    }
    return number_format($num, 2);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function printResult(string $name, array $result, ?array $baseline = null): void
{
    echo "\n" . color("=== {$name} ===", 'blue') . "\n";
    echo "  Time: " . number_format($result['time'], 4) . "s\n";
    echo "  Ops/sec: " . color(formatNumber($result['ops_per_sec']), 'green') . "\n";
    echo "  Avg time: " . number_format($result['avg_time_us'], 3) . " μs\n";
    echo "  Memory: " . formatBytes($result['memory']) . "\n";

    if ($baseline !== null) {
        $speedup = $result['time'] > 0 ? $baseline['time'] / $result['time'] : 1.0;
        $memReduction = $baseline['memory'] > 0 ? (1 - $result['memory'] / $baseline['memory']) * 100 : 0.0;

        $speedupColor = $speedup > 1.05 ? 'green' : ($speedup < 0.95 ? 'red' : 'yellow');
        $memColor = $memReduction > 5 ? 'green' : ($memReduction < -5 ? 'red' : 'yellow');

        echo "  Speedup: " . color(number_format($speedup, 2) . 'x', $speedupColor) . "\n";
        if ($baseline['memory'] > 0) {
            echo "  Memory reduction: " . color(number_format($memReduction, 1) . '%', $memColor) . "\n";
        }
    }
}

echo color("\n╔════════════════════════════════════════════════════════════╗\n", 'blue');
echo color("║  WLS PHP 8.4 强类型优化性能基准测试                        ║\n", 'blue');
echo color("╚════════════════════════════════════════════════════════════╝\n", 'blue');

echo "\nPHP Version: " . color(PHP_VERSION, 'green') . "\n";
echo "Iterations: " . color(number_format(ITERATIONS), 'yellow') . "\n";
echo "Array Size: " . color(number_format(ARRAY_SIZE), 'yellow') . "\n";

// ========== 测试 1: 数组访问性能 ==========
echo color("\n\n【测试 1】数组访问性能", 'blue') . "\n";

// 非类型化数组
$untypedArray = [];
for ($i = 0; $i < ARRAY_SIZE; $i++) {
    $untypedArray[$i] = $i;
}

$result1a = benchmark('未优化：动态数组访问', function () use ($untypedArray) {
    $sum = 0;
    foreach ($untypedArray as $value) {
        $sum += $value;
    }
    return $sum;
});

// 类型化数组（通过 PHPDoc）
/** @var int[] */
$typedArray = [];
for ($i = 0; $i < ARRAY_SIZE; $i++) {
    $typedArray[$i] = $i;
}

$result1b = benchmark('优化后：类型化数组访问', function () use ($typedArray) {
    $sum = 0;
    foreach ($typedArray as $value) {
        $sum += $value;
    }
    return $sum;
});

printResult('未优化：动态数组访问', $result1a);
printResult('优化后：类型化数组访问', $result1b, $result1a);

// ========== 测试 2: 关联数组访问性能 ==========
echo color("\n\n【测试 2】关联数组访问性能", 'blue') . "\n";

// 非类型化关联数组
$untypedMap = [];
for ($i = 0; $i < ARRAY_SIZE; $i++) {
    $untypedMap["key_{$i}"] = ['value' => $i, 'timestamp' => time()];
}

$result2a = benchmark('未优化：动态关联数组', function () use ($untypedMap) {
    $sum = 0;
    foreach ($untypedMap as $entry) {
        $sum += $entry['value'];
    }
    return $sum;
});

// 类型化关联数组
/** @var array<string, array{value: int, timestamp: int}> */
$typedMap = [];
for ($i = 0; $i < ARRAY_SIZE; $i++) {
    $typedMap["key_{$i}"] = ['value' => $i, 'timestamp' => time()];
}

$result2b = benchmark('优化后：类型化关联数组', function () use ($typedMap) {
    $sum = 0;
    foreach ($typedMap as $entry) {
        $sum += $entry['value'];
    }
    return $sum;
});

printResult('未优化：动态关联数组', $result2a);
printResult('优化后：类型化关联数组', $result2b, $result2a);

// ========== 测试 3: LRU 淘汰算法性能 ==========
echo color("\n\n【测试 3】LRU 淘汰算法性能", 'blue') . "\n";

// 未优化：使用 uasort
function evictOldestUnoptimized(array $cache, int $targetFreeBytes): int
{
    $sorted = $cache;
    uasort($sorted, function ($a, $b) {
        return ($a['last_access'] ?? $a['created_at']) <=> ($b['last_access'] ?? $b['created_at']);
    });

    $freedBytes = 0;
    foreach (array_keys($sorted) as $key) {
        if ($freedBytes >= $targetFreeBytes) {
            break;
        }
        $freedBytes += strlen($cache[$key]['response']);
    }

    return $freedBytes;
}

// 优化后：使用 asort
function evictOldestOptimized(array $cache, int $targetFreeBytes): int
{
    /** @var array<string, int> */
    $accessTimes = [];
    foreach ($cache as $key => $entry) {
        $accessTimes[$key] = $entry['last_access'] ?? $entry['created_at'];
    }

    asort($accessTimes, SORT_NUMERIC);

    $freedBytes = 0;
    foreach (array_keys($accessTimes) as $key) {
        if ($freedBytes >= $targetFreeBytes) {
            break;
        }
        $freedBytes += strlen($cache[$key]['response']);
    }

    return $freedBytes;
}

// 准备测试数据
$testCache = [];
for ($i = 0; $i < 1000; $i++) {
    $testCache["key_{$i}"] = [
        'response' => str_repeat('x', 1024),
        'created_at' => time() - rand(0, 3600),
        'last_access' => time() - rand(0, 1800),
    ];
}

$result3a = benchmark('未优化：uasort LRU', function () use ($testCache) {
    return evictOldestUnoptimized($testCache, 100000);
}, 1000);

$result3b = benchmark('优化后：asort LRU', function () use ($testCache) {
    return evictOldestOptimized($testCache, 100000);
}, 1000);

printResult('未优化：uasort LRU', $result3a);
printResult('优化后：asort LRU', $result3b, $result3a);

// ========== 测试 4: 加权轮询算法性能 ==========
echo color("\n\n【测试 4】加权轮询算法性能", 'blue') . "\n";

// 未优化：使用 array_sum
function selectWeightedUnoptimized(array $weights, array &$currentWeights, array $ports): int
{
    $totalWeight = array_sum($weights);
    $selectedPort = $ports[0];
    $maxWeight = PHP_INT_MIN;

    foreach ($ports as $port) {
        $currentWeights[$port] += $weights[$port];

        if ($currentWeights[$port] > $maxWeight) {
            $maxWeight = $currentWeights[$port];
            $selectedPort = $port;
        }
    }

    $currentWeights[$selectedPort] -= $totalWeight;

    return $selectedPort;
}

// 优化后：手动累加
function selectWeightedOptimized(array $weights, array &$currentWeights, array $ports): int
{
    $totalWeight = 0;
    foreach ($weights as $weight) {
        $totalWeight += $weight;
    }

    $selectedPort = $ports[0];
    $maxWeight = PHP_INT_MIN;

    foreach ($ports as $port) {
        $currentWeights[$port] += $weights[$port];

        if ($currentWeights[$port] > $maxWeight) {
            $maxWeight = $currentWeights[$port];
            $selectedPort = $port;
        }
    }

    $currentWeights[$selectedPort] -= $totalWeight;

    return $selectedPort;
}

$testPorts = [9981, 9982, 9983, 9984];
$testWeights = [9981 => 5, 9982 => 3, 9983 => 2, 9984 => 1];
$testCurrentWeights1 = [9981 => 0, 9982 => 0, 9983 => 0, 9984 => 0];
$testCurrentWeights2 = [9981 => 0, 9982 => 0, 9983 => 0, 9984 => 0];

$result4a = benchmark('未优化：array_sum 加权轮询', function () use ($testWeights, &$testCurrentWeights1, $testPorts) {
    return selectWeightedUnoptimized($testWeights, $testCurrentWeights1, $testPorts);
}, 10000);

$result4b = benchmark('优化后：手动累加加权轮询', function () use ($testWeights, &$testCurrentWeights2, $testPorts) {
    return selectWeightedOptimized($testWeights, $testCurrentWeights2, $testPorts);
}, 10000);

printResult('未优化：array_sum 加权轮询', $result4a);
printResult('优化后：手动累加加权轮询', $result4b, $result4a);

// ========== 测试 5: 过期缓存清理性能 ==========
echo color("\n\n【测试 5】过期缓存清理性能", 'blue') . "\n";

// 未优化：迭代中删除
function cleanExpiredUnoptimized(array &$cache): int
{
    $count = 0;
    $now = time();

    foreach ($cache as $key => $entry) {
        if ($entry['ttl'] > 0 && ($now - $entry['created_at']) > $entry['ttl']) {
            unset($cache[$key]);
            $count++;
        }
    }

    return $count;
}

// 优化后：批量删除
function cleanExpiredOptimized(array &$cache): int
{
    $now = time();

    /** @var string[] */
    $expiredKeys = [];

    foreach ($cache as $key => $entry) {
        if ($entry['ttl'] > 0 && ($now - $entry['created_at']) > $entry['ttl']) {
            $expiredKeys[] = $key;
        }
    }

    foreach ($expiredKeys as $key) {
        unset($cache[$key]);
    }

    return count($expiredKeys);
}

// 准备测试数据
$testCache1 = [];
$testCache2 = [];
for ($i = 0; $i < 5000; $i++) {
    $entry = [
        'response' => 'test',
        'created_at' => time() - rand(0, 7200),
        'ttl' => 3600,
    ];
    $testCache1["key_{$i}"] = $entry;
    $testCache2["key_{$i}"] = $entry;
}

$result5a = benchmark('未优化：迭代中删除', function () use (&$testCache1) {
    return cleanExpiredUnoptimized($testCache1);
}, 100);

$result5b = benchmark('优化后：批量删除', function () use (&$testCache2) {
    return cleanExpiredOptimized($testCache2);
}, 100);

printResult('未优化：迭代中删除', $result5a);
printResult('优化后：批量删除', $result5b, $result5a);

// ========== 总结 ==========
echo color("\n\n╔════════════════════════════════════════════════════════════╗\n", 'blue');
echo color("║  性能优化总结                                               ║\n", 'blue');
echo color("╚════════════════════════════════════════════════════════════╝\n", 'blue');

$totalTests = 5;
$improvements = [
    $result1b['time'] / $result1a['time'],
    $result2b['time'] / $result2a['time'],
    $result3b['time'] / $result3a['time'],
    $result4b['time'] / $result4a['time'],
    $result5b['time'] / $result5a['time'],
];

$avgSpeedup = array_sum(array_map(fn($x) => 1 / $x, $improvements)) / $totalTests;

echo "\n平均性能提升: " . color(number_format($avgSpeedup, 2) . 'x', 'green') . "\n";
echo "最佳提升: " . color(number_format(max(array_map(fn($x) => 1 / $x, $improvements)), 2) . 'x', 'green') . "\n";
echo "最小提升: " . color(number_format(min(array_map(fn($x) => 1 / $x, $improvements)), 2) . 'x', 'yellow') . "\n";

echo "\n" . color("✓ 基准测试完成！", 'green') . "\n\n";
