<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

/**
 * 计划任务 CPU 上限治理器。
 *
 * 计量口径固定为 getrusage(0)（只算本进程）：getrusage(1) 会把子进程 CPU 也算进来，
 * 实测会把 30% 跑成 43.9%、60% 跑成 72.9%，因此禁止使用。
 *
 * 额度 = cpu_count × cpu_percent / 100 个核当量（budget）。每秒一个 SIGALRM 节拍，
 * 按累计公式 need = 已用CPU / budget - 已过墙钟 判断是否超预算；超了才睡 min(need, cap)，
 * 单次睡眠上限 cap = max(5.0, 2.0 / budget)，避免 10% 这类低额度任务一口气睡几十秒。
 *
 * 闹钟不与宿主抢：先 pcntl_alarm(0) 查占用，已被占用就复原并降级；用完在 finally 里
 * pcntl_alarm(0) + 还原 handler + 还原异步信号开关（范式对齐 WlsRuntime 的 homepage 预热）。
 */
final class CronCpuGovernor
{
    /** 单次睡眠上限的下限（秒）。 */
    public const MIN_SLEEP_CAP_SECONDS = 5.0;

    /** 节拍周期（秒）。 */
    public const TICK_SECONDS = 1;

    /**
     * 单次睡眠上限：额度越低，单次能睡越久，但不超过 2.0 / budget。
     */
    public static function dynamicCap(float $budget): float
    {
        $budget = $budget > 0.0 ? $budget : 1.0;

        return \max(self::MIN_SLEEP_CAP_SECONDS, 2.0 / $budget);
    }

    /**
     * 纯函数：按累计公式算出本次该睡多少秒（0 表示不用睡）。
     */
    public static function plannedSleepSeconds(float $cpuUsed, float $wallElapsed, float $budget, float $cap): float
    {
        if ($budget <= 0.0) {
            return 0.0;
        }
        $used = \max(0.0, $cpuUsed);
        $wall = \max(0.0, $wallElapsed);
        $need = $used / $budget - $wall;
        if ($need <= 0.0) {
            return 0.0;
        }

        return \min($need, \max(0.0, $cap));
    }

    /**
     * 本进程累计 CPU 秒数（用户态 + 内核态）。本机 getrusage(0) 的键名是带点的形式。
     */
    public function selfCpuSeconds(): float
    {
        if (!\function_exists('getrusage')) {
            return 0.0;
        }
        $usage = \getrusage(0);
        if (!\is_array($usage)) {
            return 0.0;
        }

        return (float) ($usage['ru_utime.tv_sec'] ?? 0)
            + (float) ($usage['ru_utime.tv_usec'] ?? 0) / 1000000.0
            + (float) ($usage['ru_stime.tv_sec'] ?? 0)
            + (float) ($usage['ru_stime.tv_usec'] ?? 0) / 1000000.0;
    }

    /**
     * 当前环境能不能真的限速。
     *
     * @return array{available:bool,reason:string}
     */
    public static function capability(): array
    {
        if (!\function_exists('pcntl_alarm')) {
            return ['available' => false, 'reason' => self::tr('pcntl_alarm 不可用（可能被 disable_functions 禁用）')];
        }
        if (!\function_exists('pcntl_signal')) {
            return ['available' => false, 'reason' => self::tr('pcntl_signal 不可用（可能被 disable_functions 禁用）')];
        }
        if (!\function_exists('pcntl_signal_get_handler')) {
            return ['available' => false, 'reason' => self::tr('pcntl_signal_get_handler 不可用，无法安全还原宿主闹钟')];
        }
        if (!\defined('SIGALRM')) {
            return ['available' => false, 'reason' => self::tr('当前平台未定义 SIGALRM（Windows 不支持进程闹钟）')];
        }

        return ['available' => true, 'reason' => ''];
    }

    /**
     * 框架翻译函数在 CLI 引导完整时可用；单元测试/极简引导下退回占位符替换，
     * 保证降级说明永远是可读文本而不是致命错误。
     *
     * @param list<int|float|string> $args
     */
    private static function tr(string $message, array $args = []): string
    {
        if (\function_exists('__')) {
            return (string) __($message, $args);
        }
        foreach ($args as $index => $value) {
            $message = \str_replace('%{' . ($index + 1) . '}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * 在 CPU 额度内执行 $work；额度只是一个上限，未超额时完全不干预。
     *
     * @param array{count:int,percent:int,budget:float,enforce:bool} $budget
     * @param callable(string):void|null $onDegrade 降级原因回调（用于在后台面板/日志里给出可见说明）
     * @return mixed $work 的返回值
     */
    public function run(array $budget, \Closure $work, ?callable $onDegrade = null): mixed
    {
        if (!($budget['enforce'] ?? true)) {
            // 总开关关闭：仍然前後各取一次 getrusage(0) 做计量并写 lastStats，
            // 只是不装闹钟、不睡眠。这样后台/日志能说明「实际用了多少 CPU、本可让出多少」。
            $this->degrade($onDegrade, self::tr('CPU 限速总开关已关闭（只计量，不让出）'));

            return $this->measureOnly($budget, $work);
        }
        $capability = self::capability();
        if (!$capability['available']) {
            $this->degrade($onDegrade, $capability['reason']);

            return $work();
        }
        $resolved = (float) ($budget['budget'] ?? 0.0);
        if ($resolved <= 0.0) {
            $this->degrade($onDegrade, self::tr('CPU 额度解析为 0，按不限速执行'));

            return $work();
        }

        // 宿主可能已经装了闹钟（如 WLS 预热/超时）；先查占用，被占用就不抢。
        $prevAlarm = \pcntl_alarm(0);
        if ($prevAlarm > 0) {
            \pcntl_alarm($prevAlarm);
            $this->degrade($onDegrade, self::tr('进程闹钟已被宿主占用（剩余 %{1} 秒），本次不抢用', [$prevAlarm]));

            return $work();
        }

        $prevHandler = \pcntl_signal_get_handler(\SIGALRM);
        $prevAsync = \function_exists('pcntl_async_signals') ? \pcntl_async_signals() : null;

        $cpuStart = $this->selfCpuSeconds();
        $wallStart = \microtime(true);
        $cap = self::dynamicCap($resolved);
        $inHandler = false;
        $context = [
            'budget' => $resolved,
            'cap' => $cap,
            'cpuStart' => $cpuStart,
            'wallStart' => $wallStart,
            'ticks' => 0,
            'slept' => 0.0,
        ];

        $handler = function () use (&$inHandler, &$context): void {
            if ($inHandler) {
                // 上一次节拍还在 usleep 里，直接续一个节拍，避免重入叠加睡眠。
                \pcntl_alarm(self::TICK_SECONDS);

                return;
            }
            $inHandler = true;
            try {
                $context['ticks']++;
                $cpuUsed = $this->selfCpuSeconds() - $context['cpuStart'];
                $wallElapsed = \microtime(true) - $context['wallStart'];
                $seconds = self::plannedSleepSeconds($cpuUsed, $wallElapsed, $context['budget'], $context['cap']);
                if ($seconds > 0.0) {
                    $context['slept'] += $seconds;
                    \usleep((int) \round($seconds * 1000000));
                }
                \pcntl_alarm(self::TICK_SECONDS);
            } finally {
                $inHandler = false;
            }
        };

        try {
            if ($prevAsync !== true && \function_exists('pcntl_async_signals')) {
                \pcntl_async_signals(true);
            }
            $installed = @\pcntl_signal(\SIGALRM, $handler);
            if ($installed === false) {
                @\pcntl_signal(\SIGALRM, $prevHandler);
                $this->degrade($onDegrade, self::tr('安装 SIGALRM 处理器失败'));

                return $work();
            }
            \pcntl_alarm(self::TICK_SECONDS);

            return $work();
        } finally {
            \pcntl_alarm(0);
            @\pcntl_signal(\SIGALRM, $prevHandler);
            if ($prevAsync !== null && \function_exists('pcntl_async_signals')) {
                \pcntl_async_signals($prevAsync);
            }
            $this->lastStats = [
                'ticks' => (int) $context['ticks'],
                'slept' => (float) $context['slept'],
            ];
        }
    }

    /**
     * 上一次执行的可观测统计，便于后台/日志给出「实际限速了多少」。
     *
     * ticks/slept 恒有；enforce=0 的只计量路径另外带 cpu/wall/budget/wouldYaw。
     *
     * @return array{ticks:int,slept:float,cpu?:float,wall?:float,budget?:float,wouldYaw?:float}
     */
    public function lastStats(): array
    {
        return $this->lastStats;
    }

    /** @var array{ticks:int,slept:float,cpu?:float,wall?:float,budget?:float,wouldYaw?:float} */
    private array $lastStats = ['ticks' => 0, 'slept' => 0.0];

    /**
     * 只计量不节流：不碰任何闹钟/信号，只记前后 CPU 差与墙钟，用于 enforce=0 时仍能观测。
     *
     * @param array{count:int,percent:int,budget:float,enforce:bool} $budget
     */
    private function measureOnly(array $budget, \Closure $work): mixed
    {
        $cpuStart = $this->selfCpuSeconds();
        $wallStart = \microtime(true);
        $budgetQuota = (float) ($budget['budget'] ?? 0.0);
        try {
            return $work();
        } finally {
            $cpuUsed = $this->selfCpuSeconds() - $cpuStart;
            $wallElapsed = \microtime(true) - $wallStart;
            // 复用同一套公式：本例本可睡眠的秒数就是「本可让出」的量。
            $this->lastStats = [
                'ticks' => 0,
                'slept' => 0.0,
                'cpu' => $cpuUsed,
                'wall' => $wallElapsed,
                'budget' => $budgetQuota,
                'wouldYaw' => $budgetQuota > 0.0
                    ? \max(0.0, self::plannedSleepSeconds($cpuUsed, $wallElapsed, $budgetQuota, self::dynamicCap($budgetQuota)))
                    : 0.0,
            ];
        }
    }

    private function degrade(?callable $onDegrade, string $reason): void
    {
        $this->lastStats = ['ticks' => 0, 'slept' => 0.0];
        if ($onDegrade !== null) {
            $onDegrade($reason);
        }
    }
}
