<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * 主机负载闸门：只拦「自动调度整轮」，不拦显式运行。
 *
 * 判定口径：loadavg_1 / CPU 核数 × 100，超过后台 `cron/load/max_percent`（默认 70%）时，
 * crontab 触发的本轮调度直接跳过，不派子进程、不改任务行状态，下一轮重新判定。
 * 显式入口（-f / 指定任务名 / -p 受管子进程 / 后台手动 SSE）不经过本闸门。
 * 取不到负载样本（Windows 无 sys_getloadavg 等）时放行，避免「测不到就把调度停死」。
 */
final class CronHostLoadGate
{
    public const MODULE = 'Weline_Cron';
    public const AREA = ConfigReader::area_BACKEND;

    public const KEY_MAX_PERCENT = 'cron/load/max_percent';
    public const DEFAULT_MAX_PERCENT = 70;

    /**
     * @param null|\Closure(): ?array{cores?:int,load1?:float} $loadSampleProvider 仅测试使用；返回 null 表示取不到样本
     */
    public function __construct(
        private ?ConfigReader $reader = null,
        private readonly ?\Closure $loadSampleProvider = null,
        private readonly ?int $maxPercentOverride = null,
    ) {
    }

    /**
     * 懒取配置读取器：容器对 `?ConfigReader $reader = null` 会注入 null，
     * 这里显式取实例，保证后台阈值真的被读到而不是永远走兜底常量。
     */
    private function reader(): ConfigReader
    {
        return $this->reader ??= ObjectManager::getInstance(ConfigReader::class);
    }

    public function maxPercent(): int
    {
        return \max(1, \min(100, $this->maxPercentOverride ?? $this->configuredMaxPercent()));
    }

    /**
     * 配置库不可用（未安装/未升级/无表）时按默认阈值继续，绝不让调度因读配置失败而停摆。
     */
    private function configuredMaxPercent(): int
    {
        try {
            $value = $this->reader()->get(
                self::KEY_MAX_PERCENT,
                self::MODULE,
                self::AREA,
                self::DEFAULT_MAX_PERCENT,
                ConfigReader::SCOPE_GLOBAL,
            );
        } catch (\Throwable) {
            return self::DEFAULT_MAX_PERCENT;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int)$value;
        }

        return self::DEFAULT_MAX_PERCENT;
    }

    /**
     * @return array{available:bool,percent:float,cores:int,load1:float}
     */
    public function measure(): array
    {
        $sample = $this->loadSampleProvider !== null
            ? $this->normalizeSample(($this->loadSampleProvider)())
            : $this->hostSample();
        if ($sample === null) {
            return ['available' => false, 'percent' => 0.0, 'cores' => 1, 'load1' => 0.0];
        }

        // 不夹紧到 100%：报数要能看出「超了多少」（例：4 核 load1=20 即 500%）。
        return [
            'available' => true,
            'percent' => \round($sample['load1'] / $sample['cores'] * 100, 2),
            'cores' => $sample['cores'],
            'load1' => $sample['load1'],
        ];
    }

    /**
     * @return null|array{cores:int,load1:float}
     */
    private function hostSample(): ?array
    {
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $avg = \sys_getloadavg();
        if (!\is_array($avg) || !isset($avg[0]) || !\is_numeric($avg[0])) {
            return null;
        }

        return ['cores' => self::detectCores(), 'load1' => (float)$avg[0]];
    }

    /**
     * 宿主逻辑核数：macOS 没有 /proc/cpuinfo，必须走 sysctl，否则会退化成分母 1 把百分比放大 N 倍。
     */
    private static function detectCores(): int
    {
        foreach (['WLS_HOST_NPROC', 'WLS_CPU_NUM'] as $env) {
            $n = (int)(\getenv($env) ?: 0);
            if ($n > 0) {
                return $n;
            }
        }
        if (\function_exists('swoole_cpu_num')) {
            $n = (int)\swoole_cpu_num();
            if ($n > 0) {
                return $n;
            }
        }
        if (\is_readable('/proc/cpuinfo')) {
            $n = (int)\preg_match_all('/^processor\s*:/m', (string)\file_get_contents('/proc/cpuinfo'));
            if ($n > 0) {
                return $n;
            }
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $n = (int)(\getenv('NUMBER_OF_PROCESSORS') ?: 0);
            if ($n > 0) {
                return $n;
            }
        }
        foreach (['hw.logicalcpu', 'hw.ncpu'] as $key) {
            // crontab 的 PATH 通常只有 /usr/bin:/bin，sysctl 在 /usr/sbin，必须先试绝对路径。
            foreach (['/usr/sbin/sysctl -n ' . $key, 'sysctl -n ' . $key] as $command) {
                $n = (int)\trim((string)@\shell_exec($command . ' 2>/dev/null'));
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return 1;
    }

    /**
     * @param null|array{cores?:int,load1?:float} $sample
     *
     * @return null|array{cores:int,load1:float}
     */
    private function normalizeSample(?array $sample): ?array
    {
        if ($sample === null) {
            return null;
        }

        return [
            'cores' => \max(1, (int)($sample['cores'] ?? 1)),
            'load1' => \max(0.0, (float)($sample['load1'] ?? 0.0)),
        ];
    }

    /**
     * 返回 null 表示可以自动运行；返回数组表示本轮应跳过（附实测值供日志使用）。
     *
     * @return null|array{percent:float,threshold:int,cores:int,load1:float}
     */
    public function blocksAutomation(): ?array
    {
        $sample = $this->measure();
        if (!$sample['available']) {
            return null;
        }
        $threshold = $this->maxPercent();
        if ($sample['percent'] <= $threshold) {
            return null;
        }

        return [
            'percent' => $sample['percent'],
            'threshold' => $threshold,
            'cores' => $sample['cores'],
            'load1' => $sample['load1'],
        ];
    }
}
