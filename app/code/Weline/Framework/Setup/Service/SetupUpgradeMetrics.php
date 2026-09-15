<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Service;

use Weline\Framework\Output\Cli\Printing;

/**
 * setup:upgrade 阶段计时与结束概览（墙钟 / 内存峰值 / CPU）。
 *
 * CPU「观测峰值」为各 mark 边界 Δcpu/Δwall 采样最大值，非 OS 硬件瞬时核利用率。
 */
final class SetupUpgradeMetrics
{
    private float $startedAt = 0.0;

    private float $lastMarkAt = 0.0;

    private float $lastCpuSeconds = 0.0;

    /** @var array<string, float> phase => seconds */
    private array $phases = [];

    private float $peakObservedCpuPercent = 0.0;

    private bool $started = false;

    private bool $overviewPrinted = false;

    private string $operationId = '';

    private bool $interrupted = false;

    public function __construct(
        private readonly ?Printing $printing = null,
    ) {
    }

    public function start(string $operationId = ''): void
    {
        $this->started = true;
        $this->overviewPrinted = false;
        $this->interrupted = false;
        $this->operationId = $operationId;
        $this->phases = [];
        $this->peakObservedCpuPercent = 0.0;
        $this->startedAt = $this->now();
        $this->lastMarkAt = $this->startedAt;
        $this->lastCpuSeconds = $this->cpuSeconds();
    }

    public function setInterrupted(bool $interrupted = true): void
    {
        $this->interrupted = $interrupted;
    }

    /**
     * 结束上一段并开始名为 $name 的下一段（或仅结束上一段当 $name 为空时不记名）。
     */
    public function mark(string $name): void
    {
        if (!$this->started) {
            $this->start();
        }

        $now = $this->now();
        $cpu = $this->cpuSeconds();
        $wallDelta = \max(0.0, $now - $this->lastMarkAt);
        $cpuDelta = \max(0.0, $cpu - $this->lastCpuSeconds);

        if ($wallDelta > 0.000001) {
            $sample = \min(100.0, 100.0 * ($cpuDelta / $wallDelta));
            if ($sample > $this->peakObservedCpuPercent) {
                $this->peakObservedCpuPercent = $sample;
            }
        }

        // 将上一段累计到「未命名间隙」不单独展示；调用方应成对 begin/end。
        // mark('x') 表示「x 刚结束」：把 last→now 记到 x。
        if ($name !== '') {
            $this->phases[$name] = ($this->phases[$name] ?? 0.0) + $wallDelta;
            $elapsedMs = (int)\round(($now - $this->startedAt) * 1000);
            $durationMs = (int)\round($wallDelta * 1000);
            $this->note(__(
                '   - 阶段耗时：%{name} %{duration}（累计 %{elapsed}）',
                [
                    'name' => $name,
                    'duration' => $this->formatDuration($wallDelta),
                    'elapsed' => $this->formatDuration($now - $this->startedAt) . " ({$elapsedMs} ms)",
                ]
            ));
            // silence unused in non-cli
            unset($durationMs);
        }

        $this->lastMarkAt = $now;
        $this->lastCpuSeconds = $cpu;
    }

    /**
     * 包裹一段命名工作。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function measure(string $name, callable $fn): mixed
    {
        $this->begin($name);
        try {
            return $fn();
        } finally {
            $this->end($name);
        }
    }

    public function begin(string $name): void
    {
        if (!$this->started) {
            $this->start();
        }
        // 关闭上一段匿名间隙
        $now = $this->now();
        $cpu = $this->cpuSeconds();
        $wallDelta = \max(0.0, $now - $this->lastMarkAt);
        $cpuDelta = \max(0.0, $cpu - $this->lastCpuSeconds);
        if ($wallDelta > 0.000001) {
            $sample = \min(100.0, 100.0 * ($cpuDelta / $wallDelta));
            if ($sample > $this->peakObservedCpuPercent) {
                $this->peakObservedCpuPercent = $sample;
            }
        }
        $this->lastMarkAt = $now;
        $this->lastCpuSeconds = $cpu;
        // stash open phase name in phases with negative sentinel via parallel stack
        $this->openPhase = $name;
        $this->openPhaseAt = $now;
        $this->openPhaseCpu = $cpu;
    }

    private string $openPhase = '';

    private float $openPhaseAt = 0.0;

    private float $openPhaseCpu = 0.0;

    public function end(?string $name = null): void
    {
        $phase = $name ?? $this->openPhase;
        if ($phase === '') {
            return;
        }
        $now = $this->now();
        $cpu = $this->cpuSeconds();
        $started = $this->openPhase !== '' && ($name === null || $name === $this->openPhase)
            ? $this->openPhaseAt
            : $this->lastMarkAt;
        $cpuStarted = $this->openPhase !== '' && ($name === null || $name === $this->openPhase)
            ? $this->openPhaseCpu
            : $this->lastCpuSeconds;

        $wallDelta = \max(0.0, $now - $started);
        $cpuDelta = \max(0.0, $cpu - $cpuStarted);
        if ($wallDelta > 0.000001) {
            $sample = \min(100.0, 100.0 * ($cpuDelta / $wallDelta));
            if ($sample > $this->peakObservedCpuPercent) {
                $this->peakObservedCpuPercent = $sample;
            }
        }

        $this->phases[$phase] = ($this->phases[$phase] ?? 0.0) + $wallDelta;
        $this->note(__(
            '   - 阶段耗时：%{name} %{duration}（累计 %{elapsed}）',
            [
                'name' => $phase,
                'duration' => $this->formatDuration($wallDelta),
                'elapsed' => $this->formatDuration($now - $this->startedAt),
            ]
        ));

        if ($this->openPhase === $phase) {
            $this->openPhase = '';
        }
        $this->lastMarkAt = $now;
        $this->lastCpuSeconds = $cpu;
    }

    public function noteMemory(string $label): void
    {
        if (!\function_exists('memory_get_usage')) {
            return;
        }
        $cur = (int)\round(\memory_get_usage(true) / 1048576);
        $peak = (int)\round(\memory_get_peak_usage(true) / 1048576);
        $this->note(__(
            '   - 内存 [%{label}]：当前 %{cur}MB / 峰值 %{peak}MB',
            ['label' => $label, 'cur' => $cur, 'peak' => $peak]
        ));
    }

    public function printOverview(): void
    {
        if (!$this->started || $this->overviewPrinted) {
            return;
        }
        $this->overviewPrinted = true;

        // 收尾未关闭的 open phase
        if ($this->openPhase !== '') {
            $this->end($this->openPhase);
        } else {
            $this->sampleBoundary();
        }

        $wall = \max(0.0, $this->now() - $this->startedAt);
        $wallMs = (int)\round($wall * 1000);
        $cpuTotal = $this->cpuSeconds();
        $cpuParts = $this->cpuParts();
        $avg = $wall > 0.000001
            ? (int)\min(100, \round(100.0 * ($cpuTotal / $wall)))
            : 0;
        $memPeak = \function_exists('memory_get_peak_usage')
            ? (int)\round(\memory_get_peak_usage(true) / 1048576)
            : 0;
        $memCur = \function_exists('memory_get_usage')
            ? (int)\round(\memory_get_usage(true) / 1048576)
            : 0;

        $lines = [];
        $lines[] = '======== ' . __('系统更新耗时概览') . ' ========';
        if ($this->interrupted) {
            $lines[] = __('状态：中断/失败（部分阶段）');
        }
        $lines[] = __('总墙钟：%{wall} (%{ms} ms)', [
            'wall' => $this->formatDuration($wall),
            'ms' => $wallMs,
        ]);
        $lines[] = __('阶段 Top：');
        foreach ($this->topPhases(8) as $i => $row) {
            $lines[] = \sprintf(
                '  %d. %-36s %s',
                $i + 1,
                $row['name'],
                $this->formatDuration($row['seconds'])
            );
        }
        $other = $this->otherPhasesSeconds(8);
        if ($other > 0.05) {
            $lines[] = \sprintf('  %s %-36s %s', '-', __('其他'), $this->formatDuration($other));
        }
        $lines[] = __('内存峰值：%{peak} MB（real） / 当前 %{cur} MB', [
            'peak' => $memPeak,
            'cur' => $memCur,
        ]);
        if ($cpuParts === null) {
            $lines[] = __('CPU：不可用（无 getrusage）');
        } else {
            $lines[] = __(
                'CPU：用户 %{user}s + 系统 %{sys}s = %{total}s；平均占用 %{avg}%；观测峰值 %{peak}%',
                [
                    'user' => $this->formatSeconds1($cpuParts['user']),
                    'sys' => $this->formatSeconds1($cpuParts['sys']),
                    'total' => $this->formatSeconds1($cpuParts['user'] + $cpuParts['sys']),
                    'avg' => $avg,
                    'peak' => (int)\round($this->peakObservedCpuPercent),
                ]
            );
        }
        if ($this->operationId !== '') {
            $lines[] = __('operation：%{id}', ['id' => $this->operationId]);
        }
        $lines[] = '================================';

        foreach ($lines as $line) {
            $this->note($line);
        }
    }

    /**
     * @return list<array{name: string, seconds: float}>
     */
    public function topPhases(int $limit = 8): array
    {
        $copy = $this->phases;
        \arsort($copy, \SORT_NUMERIC);
        $out = [];
        $i = 0;
        foreach ($copy as $name => $seconds) {
            if ($i >= $limit) {
                break;
            }
            $out[] = ['name' => (string)$name, 'seconds' => (float)$seconds];
            $i++;
        }

        return $out;
    }

    public function otherPhasesSeconds(int $topLimit = 8): float
    {
        $copy = $this->phases;
        \arsort($copy, \SORT_NUMERIC);
        $i = 0;
        $other = 0.0;
        foreach ($copy as $seconds) {
            if ($i < $topLimit) {
                $i++;
                continue;
            }
            $other += (float)$seconds;
        }

        return $other;
    }

    /** @return array<string, float> */
    public function getPhases(): array
    {
        return $this->phases;
    }

    private function sampleBoundary(): void
    {
        $now = $this->now();
        $cpu = $this->cpuSeconds();
        $wallDelta = \max(0.0, $now - $this->lastMarkAt);
        $cpuDelta = \max(0.0, $cpu - $this->lastCpuSeconds);
        if ($wallDelta > 0.000001) {
            $sample = \min(100.0, 100.0 * ($cpuDelta / $wallDelta));
            if ($sample > $this->peakObservedCpuPercent) {
                $this->peakObservedCpuPercent = $sample;
            }
        }
        $this->lastMarkAt = $now;
        $this->lastCpuSeconds = $cpu;
    }

    private function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function cpuSeconds(): float
    {
        $parts = $this->cpuParts();
        if ($parts === null) {
            return 0.0;
        }

        return $parts['user'] + $parts['sys'];
    }

    /**
     * @return array{user: float, sys: float}|null
     */
    private function cpuParts(): ?array
    {
        if (!\function_exists('getrusage')) {
            return null;
        }
        $r = \getrusage(0); // RUSAGE_SELF
        if (!\is_array($r)) {
            return null;
        }
        $user = (float)($r['ru_utime.tv_sec'] ?? 0) + ((float)($r['ru_utime.tv_usec'] ?? 0) / 1_000_000);
        $sys = (float)($r['ru_stime.tv_sec'] ?? 0) + ((float)($r['ru_stime.tv_usec'] ?? 0) / 1_000_000);

        return ['user' => $user, 'sys' => $sys];
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return $this->formatSeconds1($seconds) . 's';
        }
        $m = (int)\floor($seconds / 60);
        $s = $seconds - ($m * 60);

        return $m . 'm' . $this->formatSeconds1($s) . 's';
    }

    private function formatSeconds1(float $seconds): string
    {
        return \rtrim(\rtrim(\number_format($seconds, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function note(string $message): void
    {
        if ($this->printing !== null) {
            $this->printing->note($message);
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                \fflush(\STDOUT);
            }

            return;
        }
        if (\PHP_SAPI === 'cli') {
            echo $message . \PHP_EOL;
        }
    }
}
