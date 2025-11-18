<?php
declare(strict_types=1);

namespace Weline\Ai\Helper;

/**
 * 性能辅助类
 * 
 * 提供通用的性能相关工具方法
 * 
 * @package Weline_Ai
 */
class PerformanceHelper
{
    /**
     * 格式化字节大小
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * 计算性能统计
     *
     * @param array $times 时间数组（秒）
     * @return array 包含 min, max, avg, median, p95, p99 的统计数据
     */
    public static function calculateStats(array $times): array
    {
        if (empty($times)) {
            return [
                'min' => 0,
                'max' => 0,
                'avg' => 0,
                'median' => 0,
                'p95' => 0,
                'p99' => 0,
            ];
        }

        sort($times);
        $count = count($times);
        
        return [
            'min' => min($times),
            'max' => max($times),
            'avg' => array_sum($times) / $count,
            'median' => $times[floor($count / 2)],
            'p95' => $times[floor($count * 0.95)],
            'p99' => $times[min(floor($count * 0.99), $count - 1)],
        ];
    }

    /**
     * 格式化统计信息为字符串
     *
     * @param array $stats
     * @param string $unit
     * @return string
     */
    public static function formatStats(array $stats, string $unit = 's'): string
    {
        $lines = [];
        foreach ($stats as $key => $value) {
            $lines[] = sprintf('%s: %.4f%s', ucfirst($key), $value, $unit);
        }
        return implode(', ', $lines);
    }

    /**
     * 记录性能统计信息
     *
     * @param string $testName
     * @param array $stats
     * @param bool $printToConsole
     * @return string
     */
    public static function logPerformanceStats(
        string $testName,
        array $stats,
        bool $printToConsole = true
    ): string {
        $output = "\n--- {$testName} Performance ---\n";
        $output .= sprintf("Min:    %.4fs\n", $stats['min']);
        $output .= sprintf("Avg:    %.4fs\n", $stats['avg']);
        $output .= sprintf("Median: %.4fs\n", $stats['median']);
        $output .= sprintf("P95:    %.4fs\n", $stats['p95']);
        $output .= sprintf("P99:    %.4fs\n", $stats['p99']);
        $output .= sprintf("Max:    %.4fs\n", $stats['max']);

        if ($printToConsole) {
            echo $output;
        }

        return $output;
    }

    /**
     * 测量执行时间
     *
     * @param callable $callback
     * @return array ['duration' => float, 'result' => mixed]
     */
    public static function measure(callable $callback): array
    {
        $start = microtime(true);
        $result = $callback();
        $duration = microtime(true) - $start;

        return [
            'duration' => $duration,
            'result' => $result,
        ];
    }

    /**
     * 批量测量多次执行
     *
     * @param callable $callback
     * @param int $iterations
     * @return array
     */
    public static function benchmark(callable $callback, int $iterations = 100): array
    {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $measurement = self::measure($callback);
            $times[] = $measurement['duration'];
        }

        return self::calculateStats($times);
    }

    /**
     * 格式化持续时间
     *
     * @param float $seconds
     * @return string
     */
    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return round($seconds * 1000000, 2) . ' μs';
        } elseif ($seconds < 1) {
            return round($seconds * 1000, 2) . ' ms';
        } else {
            return round($seconds, 2) . ' s';
        }
    }
}

