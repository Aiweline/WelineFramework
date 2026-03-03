<?php
declare(strict_types=1);

/**
 * WLS Layer5 进程监控器
 *
 * 检测进程存活状态，捕获无法通过其他方式获取的进程死亡：
 * - OOM Killed (SIGKILL)
 * - 其他无输出的突然死亡
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Master;

use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;

class ProcessMonitor
{
    /**
     * 监控的进程列表 [id => ['pid' => int, 'start_time' => int, 'tag' => string]]
     */
    private array $processes = [];

    /**
     * 进程死亡回调
     * @var callable|null
     */
    private $onDeathCallback = null;

    /**
     * 添加进程到监控列表
     *
     * @param string $id 进程标识
     * @param int $pid 进程 PID
     * @param string $tag 进程标签（用于日志）
     */
    public function addProcess(string $id, int $pid, string $tag = ''): void
    {
        $this->processes[$id] = [
            'pid' => $pid,
            'start_time' => \time(),
            'tag' => $tag ?: $id,
            'last_check' => \time(),
        ];
    }

    /**
     * 移除进程
     */
    public function removeProcess(string $id): void
    {
        unset($this->processes[$id]);
    }

    /**
     * 更新进程 PID（重启后调用）
     */
    public function updatePid(string $id, int $pid): void
    {
        if (isset($this->processes[$id])) {
            $this->processes[$id]['pid'] = $pid;
            $this->processes[$id]['start_time'] = \time();
            $this->processes[$id]['last_check'] = \time();
        }
    }

    /**
     * 设置进程死亡回调
     *
     * @param callable $callback function(string $id, int $pid, string $reason): void
     */
    public function onDeath(callable $callback): void
    {
        $this->onDeathCallback = $callback;
    }

    /**
     * 检查所有进程状态
     *
     * @return array<string, array{pid: int, reason: string, exit_code: int|null, signal: int|null, runtime: int}>
     */
    public function checkAll(): array
    {
        $dead = [];

        foreach ($this->processes as $id => $info) {
            $pid = $info['pid'];

            if (!$this->isProcessRunning($pid)) {
                $exitInfo = $this->getExitInfo($pid);
                $runtime = \time() - $info['start_time'];

                $deathInfo = [
                    'pid' => $pid,
                    'tag' => $info['tag'],
                    'reason' => $exitInfo['reason'],
                    'exit_code' => $exitInfo['exit_code'],
                    'signal' => $exitInfo['signal'],
                    'runtime' => $runtime,
                ];

                $dead[$id] = $deathInfo;

                // 记录日志
                $this->logDeath($id, $deathInfo);

                // 触发回调
                if ($this->onDeathCallback !== null) {
                    \call_user_func($this->onDeathCallback, $id, $pid, $exitInfo['reason']);
                }
            }

            $this->processes[$id]['last_check'] = \time();
        }

        return $dead;
    }

    /**
     * 检查单个进程状态
     */
    public function check(string $id): ?array
    {
        if (!isset($this->processes[$id])) {
            return null;
        }

        $info = $this->processes[$id];
        $pid = $info['pid'];

        if ($this->isProcessRunning($pid)) {
            return null;
        }

        $exitInfo = $this->getExitInfo($pid);
        $runtime = \time() - $info['start_time'];

        return [
            'pid' => $pid,
            'tag' => $info['tag'],
            'reason' => $exitInfo['reason'],
            'exit_code' => $exitInfo['exit_code'],
            'signal' => $exitInfo['signal'],
            'runtime' => $runtime,
        ];
    }

    /**
     * 检查进程是否在运行
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Windows
            $output = [];
            @\exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);
            foreach ($output as $line) {
                if (\str_contains($line, (string)$pid)) {
                    return true;
                }
            }
            return false;
        } else {
            // Linux/Unix
            // kill -0 不发送信号，只检查进程是否存在
            return \posix_kill($pid, 0);
        }
    }

    /**
     * 获取进程退出信息
     */
    private function getExitInfo(int $pid): array
    {
        // 尝试使用 pcntl_waitpid 获取退出状态（仅 Linux/Unix）
        if (\function_exists('pcntl_waitpid')) {
            $status = 0;
            $result = @\pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                if (\pcntl_wifexited($status)) {
                    $exitCode = \pcntl_wexitstatus($status);
                    return [
                        'exit_code' => $exitCode,
                        'signal' => null,
                        'reason' => $this->getExitCodeReason($exitCode),
                    ];
                }

                if (\pcntl_wifsignaled($status)) {
                    $signal = \pcntl_wtermsig($status);
                    return [
                        'exit_code' => null,
                        'signal' => $signal,
                        'reason' => $this->getSignalReason($signal),
                    ];
                }
            }
        }

        // 无法获取详细信息
        return [
            'exit_code' => null,
            'signal' => null,
            'reason' => '进程已结束（无法获取详细信息）',
        ];
    }

    /**
     * 根据退出码获取原因描述
     */
    private function getExitCodeReason(int $exitCode): string
    {
        return match ($exitCode) {
            0 => '正常退出',
            1 => '一般错误',
            2 => '命令使用错误',
            126 => '权限不足',
            127 => '命令未找到',
            128 => '无效退出参数',
            130 => 'SIGINT (Ctrl+C)',
            137 => 'SIGKILL (可能是 OOM Killed)',
            139 => 'SIGSEGV (段错误)',
            143 => 'SIGTERM (正常终止)',
            255 => '未捕获异常',
            default => "退出码 {$exitCode}",
        };
    }

    /**
     * 根据信号获取原因描述
     */
    private function getSignalReason(int $signal): string
    {
        return match ($signal) {
            1 => 'SIGHUP (终端断开)',
            2 => 'SIGINT (Ctrl+C)',
            3 => 'SIGQUIT (退出)',
            6 => 'SIGABRT (异常中止)',
            9 => 'SIGKILL (强制终止，可能是 OOM Killed)',
            11 => 'SIGSEGV (段错误)',
            13 => 'SIGPIPE (管道破裂)',
            14 => 'SIGALRM (定时器)',
            15 => 'SIGTERM (正常终止)',
            default => "信号 {$signal}",
        };
    }

    /**
     * 记录进程死亡日志
     */
    private function logDeath(string $id, array $info): void
    {
        $message = \sprintf(
            '[ProcessMonitor] 进程死亡: %s (PID: %d) - %s, 运行时间: %ds',
            $info['tag'],
            $info['pid'],
            $info['reason'],
            $info['runtime']
        );

        try {
            WlsLogger::getInstance()->log(LogLevel::ERROR, $message, [
                'process_id' => $id,
                'exit_code' => $info['exit_code'],
                'signal' => $info['signal'],
            ]);
        } catch (\Throwable $e) {
            // 日志失败不应影响监控
            @w_log_info($message);
        }
    }

    /**
     * 获取所有监控的进程
     */
    public function getProcesses(): array
    {
        return $this->processes;
    }

    /**
     * 获取监控的进程数量
     */
    public function count(): int
    {
        return \count($this->processes);
    }

    /**
     * 清空所有监控
     */
    public function clear(): void
    {
        $this->processes = [];
    }
}
