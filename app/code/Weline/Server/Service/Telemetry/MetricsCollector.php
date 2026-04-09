<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

/**
 * WLS 指标收集器单例
 *
 * 封装 $GLOBALS['wls_metrics_collector'] 访问，提供全局统一的指标收集接口。
 * 采用单例模式，确保在整个 WLS 进程生命周期内只有一个指标收集器实例。
 *
 * @method static MetricsCollector getInstance() 获取单例实例
 */
class MetricsCollector
{
    private static ?self $instance = null;

    /** @var SessionMetricsCollector|null */
    private ?SessionMetricsCollector $collector = null;

    private function __construct()
    {
        $this->collector = $this->resolveCollector();
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 解析实际的收集器实例
     *
     * 优先使用全局收集器，兼容旧代码初始化方式
     */
    private function resolveCollector(): SessionMetricsCollector
    {
        if (isset($GLOBALS['wls_metrics_collector'])) {
            return $GLOBALS['wls_metrics_collector'];
        }

        return new SessionMetricsCollector();
    }

    /**
     * 递增计数器
     */
    public function incrementCounter(string $name, int $value, array $labels = []): void
    {
        $this->collector?->incrementCounter($name, $value, $labels);
    }

    /**
     * 设置仪表盘值
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $this->collector?->setGauge($name, $value, $labels);
    }

    /**
     * 记录直方图数据
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        $this->collector?->recordHistogram($name, $value, $labels);
    }

    /**
     * 导出 Prometheus 格式指标
     */
    public function exportPrometheus(): string
    {
        return $this->collector?->exportPrometheus() ?? '';
    }

    /**
     * 获取统计摘要（用于调试）
     */
    public function getSummary(): array
    {
        return $this->collector?->getSummary() ?? [
            'counters' => 0,
            'gauges' => 0,
            'histograms' => 0,
            'memory_usage_bytes' => 0,
        ];
    }

    /**
     * 初始化全局收集器（供旧代码兼容）
     *
     * 用于 SessionServer 等直接创建全局实例的旧代码
     */
    public static function initGlobal(): void
    {
        if (!isset($GLOBALS['wls_metrics_collector'])) {
            $GLOBALS['wls_metrics_collector'] = new SessionMetricsCollector();
        }
    }
}
