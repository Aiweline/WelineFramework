<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 按 zone、按时间窗口的 purge 预算闸门。
 *
 * CDN 接口都有调用配额；这里只做「这个 zone 现在还能不能再发一次」的判定，
 * 超限就让调用方返回失败并在规划器里留下续跑点，绝不在超限时假装成功。
 *
 * 时钟可注入，测试里不用 sleep。
 */
final class PurgeBudgetGate
{
    /** @var array<string, array{window:int,used:int}> */
    private array $state = [];

    /** @param callable():int|null $clock 返回当前时间戳（秒） */
    public function __construct(
        private readonly int $budgetPerWindow,
        private readonly int $windowSeconds,
        private $clock = null,
    ) {
    }

    public function allow(string $zone): bool
    {
        $now = $this->now();
        $window = $this->windowSeconds > 0 ? \intdiv($now, $this->windowSeconds) : 0;
        $entry = $this->state[$zone] ?? null;
        if ($entry === null || $entry['window'] !== $window) {
            $this->state[$zone] = ['window' => $window, 'used' => 1];

            return $this->budgetPerWindow > 0;
        }
        if ($this->budgetPerWindow > 0 && $entry['used'] >= $this->budgetPerWindow) {
            return false;
        }
        $this->state[$zone]['used'] = $entry['used'] + 1;

        return true;
    }

    /** @return array<string, array{window:int,used:int}> */
    public function snapshot(): array
    {
        return $this->state;
    }

    public function reset(): void
    {
        $this->state = [];
    }

    private function now(): int
    {
        return $this->clock === null ? \time() : (int)($this->clock)();
    }
}
