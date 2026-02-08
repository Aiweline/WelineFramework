<?php
declare(strict_types=1);

/**
 * WLS IPC 控制通道 - Master 复活逻辑
 *
 * 当子进程（Worker / Dispatcher / HTTP Redirect）检测到 Master TCP 连接异常断开
 * 且未收到 shutdown 命令时，根据复活优先级尝试重新启动 Master 进程。
 *
 * 复活优先级（延迟退避）：
 *   1 = HTTP Redirect Worker → 延迟 1 秒
 *   2 = Dispatcher            → 延迟 3 秒
 *   3 = Worker #1             → 延迟 6 秒
 *   0 = Worker #2+            → 不参与复活，只等重连
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

use Weline\Framework\System\Process\Processer;

class MasterResurrector
{
    /** 各优先级的延迟秒数 */
    private const DELAY_MAP = [
        ControlMessage::RESURRECTION_REDIRECT   => 1,
        ControlMessage::RESURRECTION_DISPATCHER  => 3,
        ControlMessage::RESURRECTION_WORKER      => 6,
    ];

    /** 复活优先级 */
    private int $priority;

    /** 实例名称 */
    private string $instanceName;

    /** 控制端口 host */
    private string $controlHost;

    /** 控制端口 port */
    private int $controlPort;

    /** 最大复活重试次数 */
    private int $maxRetries;

    /**
     * @param int    $priority     复活优先级（0 = 不参与）
     * @param string $instanceName WLS 实例名称
     * @param string $controlHost  Master 控制端口地址
     * @param int    $controlPort  Master 控制端口
     * @param int    $maxRetries   最大重试次数（默认 3）
     */
    public function __construct(
        int    $priority,
        string $instanceName,
        string $controlHost = '127.0.0.1',
        int    $controlPort = 0,
        int    $maxRetries = 3
    ) {
        $this->priority     = $priority;
        $this->instanceName = $instanceName;
        $this->controlHost  = $controlHost;
        $this->controlPort  = $controlPort;
        $this->maxRetries   = $maxRetries;
    }

    /**
     * 是否应该尝试复活 Master
     *
     * @param bool $receivedShutdown 是否收到过 shutdown 命令
     * @return bool
     */
    public function shouldResurrect(bool $receivedShutdown): bool
    {
        // 收到过 shutdown → 不复活
        if ($receivedShutdown) {
            return false;
        }

        // 优先级 0 → 不参与复活
        if ($this->priority === ControlMessage::RESURRECTION_NONE) {
            return false;
        }

        return true;
    }

    /**
     * 获取当前优先级对应的延迟秒数
     */
    public function getDelay(): int
    {
        return self::DELAY_MAP[$this->priority] ?? 10;
    }

    /**
     * 尝试复活 Master 进程
     *
     * 流程：
     * 1. 按优先级延迟
     * 2. 检查控制端口是否已被占用（更高优先级进程已复活 Master）
     * 3. 如果无人监听 → 启动新 Master 进程
     *
     * @return bool 是否成功启动了新 Master（或 Master 已被其他进程复活）
     */
    public function attemptResurrect(): bool
    {
        $delay = $this->getDelay();

        for ($retry = 0; $retry < $this->maxRetries; $retry++) {
            // 延迟等待（给更高优先级的进程机会）
            \sleep($delay);

            // 检查 Master 是否已被复活
            if ($this->isMasterAlive()) {
                return true; // 已被其他进程复活
            }

            // 尝试启动 Master
            if ($this->startMaster()) {
                // 等待 Master 启动完成
                \sleep(2);

                if ($this->isMasterAlive()) {
                    return true;
                }
            }

            // 失败，增加延迟后重试
            $delay = \min($delay * 2, 30);
        }

        return false;
    }

    /**
     * 检查 Master 是否存活（通过检测控制端口是否可连接）
     */
    public function isMasterAlive(): bool
    {
        if ($this->controlPort <= 0) {
            return false;
        }

        $errno  = 0;
        $errstr = '';
        $conn = @\stream_socket_client(
            "tcp://{$this->controlHost}:{$this->controlPort}",
            $errno,
            $errstr,
            1 // 1 秒超时
        );

        if ($conn) {
            @\fclose($conn);
            return true;
        }

        return false;
    }

    /**
     * 启动新 Master 进程
     *
     * 复用 server:start --master-only 命令，与正常后台启动 Master 相同。
     */
    private function startMaster(): bool
    {
        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = BP . 'bin' . DS . 'w';

        if (IS_WIN) {
            $bp = \str_replace("'", "''", BP);
            $phpBin = \str_replace("'", "''", $phpBinary);
            $scriptRel = 'bin' . DS . 'w';
            $instName = \str_replace("'", "''", $this->instanceName);
            $argList = "'{$scriptRel}','server:start','{$instName}','--master-only'";
            $psCmd = "Set-Location -LiteralPath '{$bp}'; Start-Process -FilePath '{$phpBin}' -ArgumentList {$argList} -WindowStyle Hidden -WorkingDirectory '{$bp}'";
            $fullCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . \str_replace('"', '\"', $psCmd) . '"';
            @\exec($fullCmd . ' 2>NUL');
            return true;
        }

        $cmd = \sprintf(
            '%s %s server:start %s --master-only',
            $phpBinary,
            \escapeshellarg($script),
            \escapeshellarg($this->instanceName)
        );

        Processer::create($cmd, false);
        return true;
    }
}
