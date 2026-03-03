<?php
declare(strict_types=1);

/**
 * WLS Layer4 管道捕获器
 *
 * 使用 proc_open 启动子进程并通过管道捕获其 stdout/stderr。
 * 能够捕获：
 * - Parse Error（语法错误导致脚本无法执行）
 * - Segfault（段错误）
 * - 任何写入 stdout/stderr 的内容
 *
 * @author Aiweline
 */

namespace Weline\Server\Log\Master;

class PipeCapture
{
    /**
     * 进程资源 [id => proc_open resource]
     * @var resource[]
     */
    private array $processes = [];

    /**
     * 管道资源 [id => ['stdin' => resource, 'stdout' => resource, 'stderr' => resource]]
     * @var array<string, array<string, resource>>
     */
    private array $pipes = [];

    /**
     * 进程 PID [id => pid]
     * @var array<string, int>
     */
    private array $pids = [];

    /**
     * 工作目录
     */
    private string $workingDirectory;

    public function __construct(?string $workingDirectory = null)
    {
        $this->workingDirectory = $workingDirectory ?? (\defined('BP') ? BP : \getcwd());
    }

    /**
     * 启动进程并捕获其输出
     *
     * @param string $id 进程标识（如 'worker-1', 'dispatcher'）
     * @param string $command 完整命令
     * @return int 进程 PID，失败返回 0
     */
    public function startProcess(string $id, string $command): int
    {
        // 清理已存在的同 ID 进程
        if (isset($this->processes[$id])) {
            $this->closeProcess($id);
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @\proc_open(
            $command,
            $descriptorspec,
            $pipes,
            $this->workingDirectory,
            null
        );

        if (!\is_resource($process)) {
            return 0;
        }

        // 设置管道为非阻塞
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        // 获取 PID
        $status = @\proc_get_status($process);
        $pid = $status['pid'] ?? 0;

        // 保存引用
        $this->processes[$id] = $process;
        $this->pipes[$id] = [
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
        $this->pids[$id] = $pid;

        return $pid;
    }

    /**
     * 轮询所有进程的输出
     *
     * @return array<string, array{stdout: string, stderr: string}> [id => ['stdout' => ..., 'stderr' => ...]]
     */
    public function pollAll(): array
    {
        $outputs = [];

        foreach ($this->pipes as $id => $pipes) {
            $stdout = $this->readPipe($pipes['stdout']);
            $stderr = $this->readPipe($pipes['stderr']);

            if ($stdout !== '' || $stderr !== '') {
                $outputs[$id] = [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];
            }
        }

        return $outputs;
    }

    /**
     * 轮询单个进程的输出
     *
     * @return array{stdout: string, stderr: string}
     */
    public function poll(string $id): array
    {
        if (!isset($this->pipes[$id])) {
            return ['stdout' => '', 'stderr' => ''];
        }

        $pipes = $this->pipes[$id];

        return [
            'stdout' => $this->readPipe($pipes['stdout']),
            'stderr' => $this->readPipe($pipes['stderr']),
        ];
    }

    /**
     * 检查所有进程的状态，返回已结束的进程信息
     *
     * @return array<string, array{exit_code: int, signal: int, reason: string}>
     */
    public function checkProcesses(): array
    {
        $dead = [];

        foreach ($this->processes as $id => $process) {
            $status = @\proc_get_status($process);

            if (!$status['running']) {
                // 读取剩余输出
                $finalOutput = $this->poll($id);

                $dead[$id] = [
                    'exit_code' => $status['exitcode'],
                    'signal' => $status['termsig'] ?: $status['stopsig'],
                    'reason' => $this->getDeathReason($status),
                    'pid' => $this->pids[$id] ?? 0,
                    'final_stdout' => $finalOutput['stdout'],
                    'final_stderr' => $finalOutput['stderr'],
                ];

                // 清理资源
                $this->closeProcess($id);
            }
        }

        return $dead;
    }

    /**
     * 检查进程是否还在运行
     */
    public function isRunning(string $id): bool
    {
        if (!isset($this->processes[$id])) {
            return false;
        }

        $status = @\proc_get_status($this->processes[$id]);
        return $status['running'] ?? false;
    }

    /**
     * 获取进程 PID
     */
    public function getPid(string $id): int
    {
        return $this->pids[$id] ?? 0;
    }

    /**
     * 向进程 stdin 写入数据
     */
    public function write(string $id, string $data): int
    {
        if (!isset($this->pipes[$id]['stdin'])) {
            return 0;
        }

        $written = @\fwrite($this->pipes[$id]['stdin'], $data);
        return $written !== false ? $written : 0;
    }

    /**
     * 关闭进程
     */
    public function closeProcess(string $id): void
    {
        // 关闭管道
        if (isset($this->pipes[$id])) {
            foreach ($this->pipes[$id] as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            unset($this->pipes[$id]);
        }

        // 关闭进程
        if (isset($this->processes[$id])) {
            @\proc_close($this->processes[$id]);
            unset($this->processes[$id]);
        }

        unset($this->pids[$id]);
    }

    /**
     * 终止进程
     */
    public function terminateProcess(string $id, int $signal = 15): bool
    {
        if (!isset($this->processes[$id])) {
            return false;
        }

        $result = @\proc_terminate($this->processes[$id], $signal);
        return $result;
    }

    /**
     * 关闭所有进程
     */
    public function closeAll(): void
    {
        foreach (\array_keys($this->processes) as $id) {
            $this->closeProcess($id);
        }
    }

    /**
     * 获取所有进程 ID
     *
     * @return string[]
     */
    public function getProcessIds(): array
    {
        return \array_keys($this->processes);
    }

    /**
     * 获取进程数量
     */
    public function count(): int
    {
        return \count($this->processes);
    }

    /**
     * 读取管道内容（非阻塞）
     */
    private function readPipe($pipe): string
    {
        if (!\is_resource($pipe)) {
            return '';
        }

        $output = '';

        while (($chunk = @\fread($pipe, 8192)) !== false && $chunk !== '') {
            $output .= $chunk;
        }

        return $output;
    }

    /**
     * 根据进程状态判断死亡原因
     */
    private function getDeathReason(array $status): string
    {
        $exitCode = $status['exitcode'];
        $signal = $status['termsig'] ?: $status['stopsig'];

        // 常见信号
        if ($signal === 9) {
            return 'SIGKILL (可能是 OOM Killed)';
        }
        if ($signal === 11) {
            return 'SIGSEGV (段错误)';
        }
        if ($signal === 15) {
            return 'SIGTERM (正常终止)';
        }
        if ($signal === 6) {
            return 'SIGABRT (异常中止)';
        }

        // 常见退出码
        if ($exitCode === 0) {
            return '正常退出';
        }
        if ($exitCode === 1) {
            return '一般错误';
        }
        if ($exitCode === 255) {
            return '未捕获异常';
        }

        if ($signal > 0) {
            return "信号终止 (signal={$signal})";
        }

        return "退出 (exit_code={$exitCode})";
    }

    /**
     * 析构时清理所有资源
     */
    public function __destruct()
    {
        $this->closeAll();
    }
}
