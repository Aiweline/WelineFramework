<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\CronTaskCpuBudgetInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * 计划任务 CPU 预算策略。
 *
 * 所有键都是 Weline_Cron 拥有的全局后台 SystemConfig 值；缺行时回落到下方的默认值。
 * 优先级（唯一权威顺序）：任务行（weline_cron_task.cpu_count / cpu_percent，非 0 生效）
 * > 统一配置中心默认值 > 能力接口常量 1 / 30。
 */
final class CronCpuBudgetPolicy
{
    public const MODULE = 'Weline_Cron';
    public const AREA = ConfigReader::area_BACKEND;

    public const KEY_DEFAULT_COUNT = 'cron/cpu/default_count';
    public const KEY_DEFAULT_PERCENT = 'cron/cpu/default_percent';
    public const KEY_ENFORCE = 'cron/cpu/enforce';

    public const MAX_CPU_COUNT = 64;

    public function __construct(
        private ?ConfigReader $reader = null,
        private readonly ?int $countOverride = null,
        private readonly ?int $percentOverride = null,
        private readonly ?bool $enforceOverride = null,
    ) {
    }

    /**
     * 懒取配置读取器。
     *
     * 容器对 `?ConfigReader $reader = null` 的可选参数会注入 null，而 ConfigReader 是无参
     * 构造的 final 类，这里显式取实例，保证后台配置真的被读到而不是永远走兜底常量。
     */
    private function reader(): ConfigReader
    {
        return $this->reader ??= ObjectManager::getInstance(ConfigReader::class);
    }

    public function defaultCpuCount(): int
    {
        return \max(1, \min(
            self::MAX_CPU_COUNT,
            $this->countOverride ?? $this->intValue(self::KEY_DEFAULT_COUNT, CronTaskCpuBudgetInterface::DEFAULT_CPU_COUNT),
        ));
    }

    public function defaultCpuPercent(): int
    {
        return \max(1, \min(
            100,
            $this->percentOverride ?? $this->intValue(self::KEY_DEFAULT_PERCENT, CronTaskCpuBudgetInterface::DEFAULT_CPU_PERCENT),
        ));
    }

    public function enforce(): bool
    {
        return $this->enforceOverride ?? $this->boolValue(self::KEY_ENFORCE, true);
    }

    /**
     * 解析任务最终生效的预算；$taskCount / $taskPercent 为 0 表示该任务行未声明。
     *
     * @return array{count:int,percent:int,budget:float,enforce:bool}
     */
    public function resolve(int $taskCount = 0, int $taskPercent = 0, ?bool $enforceOverride = null): array
    {
        $count = $this->normalizeCount($taskCount > 0 ? $taskCount : $this->defaultCpuCount());
        $percent = $this->normalizePercent($taskPercent > 0 ? $taskPercent : $this->defaultCpuPercent());

        return [
            'count' => $count,
            'percent' => $percent,
            'budget' => $count * $percent / 100,
            'enforce' => $enforceOverride ?? $this->enforce(),
        ];
    }

    private function normalizeCount(int $count): int
    {
        return \max(1, \min(self::MAX_CPU_COUNT, $count));
    }

    private function normalizePercent(int $percent): int
    {
        return \max(1, \min(100, $percent));
    }

    private function value(string $key, mixed $default): mixed
    {
        try {
            return $this->reader()->get($key, self::MODULE, self::AREA, $default, ConfigReader::SCOPE_GLOBAL);
        } catch (\Throwable) {
            // 配置库不可用（未安装/未升级/无表）时按默认值继续，绝不让调度因读配置失败而中断。
            return $default;
        }
    }

    private function boolValue(string $key, bool $default): bool
    {
        $value = \filter_var($this->value($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    private function intValue(string $key, int $default): int
    {
        $value = $this->value($key, $default);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
