<?php
declare(strict_types=1);

/**
 * Weline Server - 停止命令
 * 
 * 发送停止信号给 Master，由 Orchestrator 统一处理所有子进程的停止。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:stop - 停止常驻内存服务器
 * 
 * 架构：命令只负责发送信号，所有停止逻辑由 Orchestrator 处理
 */
class Stop extends CommandAbstract
{
    /** IPC 等待超时（秒）- 与 Windows 一致，不长时间等待，超时后强制杀进程 */
    private const IPC_TIMEOUT = 15;
    
    /** 子进程全部退出后等待 Master 退出的最大时间（秒）*/
    private const MASTER_EXIT_TIMEOUT = 5;
    
    /** IPC 消息颜色常量 */
    private const IPC_COLOR_TAG = 'Blue';       // [IPC] 标签颜色
    private const IPC_COLOR_SUCCESS = 'Green';  // 上报成功：进程排水完成、已退出、已断开
    private const IPC_COLOR_DRAIN = 'Yellow';   // 通知重载/排水：广播 DRAIN、RELOAD
    private const IPC_COLOR_STOP = 'Red';       // 通知停止：广播 SHUTDOWN、强制终止
    private const IPC_COLOR_INFO = 'Blue';      // 一般信息：连接中、等待中
    private const IPC_COLOR_ERROR = 'Red';      // 错误/失败消息
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $stopAll = isset($args['all']) || isset($args['a']);
        $force = isset($args['force']) || isset($args['f']);
        
        if ($stopAll) {
            $this->stopAllInstances($force);
            return;
        }
        
        $this->stopInstance($instanceName, $force);
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        
        return $positionalArgs[0] ?? 'default';
    }

    /**
     * 按端口查找占用该端口的 Weline Server 实例名
     */
    public function findWelineServerInstanceNameByPort(int $port): ?string
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            return null;
        }
        $files = \glob($instanceDir . '*.json');
        foreach ($files as $file) {
            $name = \basename($file, '.json');
            $data = \json_decode(\file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }
            $instancePort = (int) ($data['port'] ?? 0);
            $count = (int) ($data['count'] ?? 4);
            if ($instancePort <= $port && $port < $instancePort + $count) {
                return $name;
            }
            $httpRedirectPort = (int) ($data['http_redirect_port'] ?? 0);
            if ($httpRedirectPort > 0 && $port === $httpRedirectPort) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 若指定端口被 Weline Server 占用则停止该实例
     */
    public function stopWelineServerOnPort(int $port): bool
    {
        $name = $this->findWelineServerInstanceNameByPort($port);
        if ($name === null) {
            return false;
        }
        $this->stopInstance($name, true);
        return true;
    }
    
    /**
     * 停止单个实例
     * 
     * 策略：
     * 1. 通过 IPC 发送 STOP 命令给 Master
     * 2. Master 的 Orchestrator 会：广播 DRAIN → 广播 SHUTDOWN → 等待退出 → 清理
     * 3. 如果 IPC 超时，强制杀死 Master（Orchestrator 会处理残留）
     */
    protected function stopInstance(string $name, bool $force = false): void
    {
        // CLI 服务器委托给专用处理
        $nameLower = strtolower($name);
        if ($nameLower === 'cli' || $nameLower === 'cli-server') {
            $this->stopCliServer($force);
            return;
        }

        // 通过 ServerInstanceManager 获取实例信息（统一入口）
        $manager = $this->getInstanceManager();
        $instanceInfo = $manager->getInstanceInfo($name);
        
        if ($instanceInfo === null) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            $this->printer->note(__('使用 server:listing 查看所有实例'));
            // 清理可能残留的启动锁（如上次 server:start 崩溃遗留），便于后续启动
            $this->releaseStartLock($name);
            return;
        }
        
        $masterPid = $instanceInfo->masterPid;
        $controlPort = $instanceInfo->controlPort;
        
        $this->printer->setup(__('停止 Weline Server'));
        echo "\n";
        
        // 检查 Master 是否存在
        if (!$instanceInfo->isMasterRunning()) {
            $this->printer->warning(__('Master 进程不存在 (PID: %{1})', [$masterPid]));
            $this->showInstanceInfo($instanceInfo);
            // 清理可能残留的进程和文件
            $this->cleanupResidualProcessesByInfo($name, $instanceInfo);
            $manager->deleteInstance($name);
            // 释放启动锁
            $this->releaseStartLock($name);
            $this->printer->success(__('实例文件已清理 ✓'));
            return;
        }
        
        // 显示实例信息
        $this->showInstanceInfo($instanceInfo);
        echo "\n";

        // 通过 IPC 发送 STOP 命令并等待完整停止
        $this->printer->note(__('发送 STOP 命令给 Master (通过 IPC)...'));
        $ipcSuccess = $this->sendStopViaIpcAndWait($name, $controlPort, $masterPid, $force);
        
        if ($ipcSuccess) {
            $this->printer->success(__('所有子进程已完整退出 ✓'));
        } else {
            // IPC 失败，强制杀死 Master
            $this->printer->warning(__('IPC 超时，强制终止 Master...'));
            Processer::killByPid($masterPid, true);
            \usleep(500000);
            
            // 清理可能残留的子进程
            $this->cleanupResidualProcessesByInfo($name, $instanceInfo);
        }
        
        // 删除实例文件
        $manager->deleteInstance($name);
        
        // 清理 PID 文件
        $this->cleanupPidFiles($name, $instanceInfo);
        
        // 释放启动锁
        $this->releaseStartLock($name);
        
        // 最后清理所有 weline-wls 前缀的残留进程
        $this->cleanupAllWlsProcesses($name);
        
        echo "\n";
        $this->printer->success(__('实例 [%{1}] 已停止 ✓', [$name]));
    }
    
    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }
    
    /**
     * 显示实例信息（统一入口，使用 ServerInstanceInfo 对象）
     *
     * 所有信息都来自 ServerInstanceManager，确保一致性。
     */
    protected function showInstanceInfo(ServerInstanceInfo $info): void
    {
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                   停止服务器实例                               ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $info->name));
        $this->printer->note(\sprintf('║  Master PID：%-48s║', $info->masterPid > 0 ? $info->masterPid : '(未运行)'));
        $this->printer->note(\sprintf('║  控制端口：%-50s║', $info->controlPort > 0 ? $info->controlPort : '(未配置)'));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', $info->getListenAddress()));
        $this->printer->note(\sprintf('║  SSL 状态：%-50s║', $info->sslEnabled ? '已启用 (HTTPS)' : '未启用 (HTTP)'));
        
        if ($info->httpRedirectPort > 0) {
            $this->printer->note(\sprintf('║  HTTP 跳转：%-49s║', ":{$info->httpRedirectPort} → :{$info->port}"));
        }
        
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        
        // 显示所有服务实例（已按优先级排序）
        $currentRole = '';
        $roleInstances = [];
        
        // 按角色分组
        foreach ($info->services as $service) {
            $roleInstances[$service->role][] = $service;
        }
        
        foreach ($roleInstances as $role => $services) {
            $count = \count($services);
            $displayName = $services[0]->displayName;
            
            $pids = [];
            $ports = [];
            foreach ($services as $service) {
                if ($service->pid > 0) {
                    $pids[] = $service->pid;
                }
                if ($service->port !== null && $service->port > 0) {
                    $ports[] = $service->port;
                }
            }
            
            $pidStr = !empty($pids) ? \implode(',', $pids) : '(无 PID)';
            $portStr = !empty($ports) ? \implode(',', $ports) : '-';
            
            $line = \sprintf('║  %s (%d): PID=%s, Port=%s', $displayName, $count, $pidStr, $portStr);
            $this->printer->note(\sprintf('%-63s║', $line));
        }
        
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  启动时间：%-50s║', $info->startedAt ?: '(未知)'));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
    }
    
    /**
     * 根据 ServerInstanceInfo 清理残留进程
     * 
     * 优化策略：优先使用已知 PID 直接杀（快速），仅在有残留时才按进程名前缀兜底
     */
    protected function cleanupResidualProcessesByInfo(string $name, ServerInstanceInfo $info): void
    {
        $this->printer->note(__('清理残留进程...'));
        
        $totalKilled = 0;
        $pidsToKill = [];
        
        // 收集所有已知 PID（Master + 所有服务）
        if ($info->masterPid > 0) {
            $pidsToKill[] = $info->masterPid;
        }
        foreach ($info->services as $service) {
            if ($service->pid > 0) {
                $pidsToKill[] = $service->pid;
            }
        }
        
        // 批量杀死所有已知 PID（直接使用 taskkill，速度快）
        if (!empty($pidsToKill)) {
            $pidsToKill = \array_unique($pidsToKill);
            $aliveCount = 0;
            
            foreach ($pidsToKill as $pid) {
                if (Processer::processExists($pid)) {
                    $aliveCount++;
                    Processer::killByPid($pid, true);
                    $totalKilled++;
                }
            }
            
            if ($aliveCount > 0) {
                $this->printer->note(__('  已向 %{1} 个进程发送终止信号', [$aliveCount]));
            }
        }
        
        // 仅在需要时才按进程名前缀兜底（比如有逃逸进程未记录 PID）
        // 跳过此步骤以加速，因为已知 PID 已处理完毕
        // 如果用户发现有残留进程，可以手动运行 server:stop --all 进行彻底清理
        
        if ($totalKilled > 0) {
            $this->printer->success(__('  清理了 %{1} 个进程 ✓', [$totalKilled]));
        } else {
            $this->printer->note(__('  无残留进程'));
        }
    }
    
    /**
     * 格式化 IPC 消息（带颜色）
     * 
     * @param string $message 消息内容
     * @param string $type 消息类型：success, drain, stop, error, info
     */
    protected function ipcMsg(string $message, string $type = 'info'): void
    {
        $color = match ($type) {
            'success' => self::IPC_COLOR_SUCCESS,  // 绿色：上报成功
            'drain' => self::IPC_COLOR_DRAIN,      // 黄色：通知排水/重载
            'stop' => self::IPC_COLOR_STOP,        // 红色：通知停止
            'error' => self::IPC_COLOR_ERROR,      // 红色：错误
            default => self::IPC_COLOR_INFO,       // 蓝色：一般信息
        };
        
        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        $content = $this->printer->colorize($message, $color);
        echo "  {$tag} {$content}\n";
    }
    
    /**
     * 格式化 IPC 进度消息（来自 Orchestrator，自动判断颜色）
     * 
     * 颜色区分：
     * - 绿色：上报成功（✓、已退出、已断开、排水完成）
     * - 黄色：通知排水/重载（广播 DRAIN、RELOAD、等待排水）
     * - 红色：通知停止（广播 SHUTDOWN、强制终止、阶段停止）
     * - 蓝色：一般信息
     */
    protected function ipcProgress(string $message): void
    {
        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        
        // 根据消息内容自动判断颜色
        if (\str_contains($message, '✓') || \str_contains($message, '已退出') || \str_contains($message, '已断开') || \str_contains($message, '排水完成')) {
            // 绿色：上报成功
            $content = $this->printer->colorize($message, self::IPC_COLOR_SUCCESS);
        } elseif (\str_contains($message, '✗') || \str_contains($message, '失败') || \str_contains($message, '错误')) {
            // 红色：错误
            $content = $this->printer->colorize($message, self::IPC_COLOR_ERROR);
        } elseif (\str_contains($message, 'SHUTDOWN') || \str_contains($message, '通知子进程退出') || \str_contains($message, '强制') || \str_contains($message, '校验子进程退出') || \str_contains($message, 'Master 即将退出')) {
            // 红色：通知停止
            $content = $this->printer->colorize($message, self::IPC_COLOR_STOP);
        } elseif (\str_contains($message, 'DRAIN') || \str_contains($message, 'RELOAD') || \str_contains($message, '排水') || \str_contains($message, '等待排水') || \str_contains($message, '重载')) {
            // 黄色：通知排水/重载
            $content = $this->printer->colorize($message, self::IPC_COLOR_DRAIN);
        } elseif (\str_contains($message, '阶段') || \str_contains($message, 'Phase')) {
            // 黄色：阶段信息（作为进度提示）
            $content = $this->printer->colorize($message, self::IPC_COLOR_DRAIN);
        } else {
            // 蓝色：一般信息
            $content = $this->printer->colorize($message, self::IPC_COLOR_INFO);
        }
        
        echo "  {$tag} {$content}\n";
    }
    
    /**
     * 通过 IPC 发送 STOP 命令并等待所有子进程完整退出
     */
    protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
    {
        if ($controlPort <= 0) {
            return false;
        }
        
        // 连接 IPC
        $host = '127.0.0.1';
        $this->ipcMsg("连接 Master (PID:{$masterPid}) 控制端口 {$host}:{$controlPort}...", 'info');
        
        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->ipcMsg("连接失败: {$errstr} (errno:{$errno})", 'error');
            return false;
        }
        
        $this->ipcMsg("连接成功 ✓", 'success');
        $this->ipcMsg("发送 STOP 命令...", 'stop');
        
        // 发送 STOP 命令
        $stopMsg = \Weline\Server\IPC\ControlMessage::command(\Weline\Server\IPC\ControlMessage::ACTION_STOP);
        $written = @\fwrite($conn, $stopMsg);
        
        if ($written === false || $written === 0) {
            $this->ipcMsg("发送命令失败", 'error');
            @\fclose($conn);
            return false;
        }
        
        $this->ipcMsg("等待 Orchestrator 停止所有子进程...", 'stop');
        
        // 设置流为非阻塞，持续读取直到连接断开（表示 Master 已停止 IPC 服务器）
        \stream_set_timeout($conn, 1);
        \stream_set_blocking($conn, false);
        
        // force 模式用于“更快进入停止流程”，不应把 IPC 等待缩短到低于 Orchestrator 的正常停机时长，
        // 否则会频繁误判超时并走强杀 Master，造成状态抖动。
        $timeout = $force ? max(self::IPC_TIMEOUT, 20) : self::IPC_TIMEOUT;
        $deadline = \microtime(true) + $timeout;
        $lastProgress = '';
        $masterAboutToExit = false; // 只在收到 "Master 即将退出" 时置 true
        $exitedPids = []; // 用 PID 去重，防止同一进程的 "已断开" 和 "已退出" 重复计数
        $totalInstances = 0; // 总实例数
        
        while (\microtime(true) < $deadline) {
            // 优先检查 Master 是否已退出（每次循环都检查）
            if (!Processer::processExists($masterPid)) {
                $this->ipcMsg("Master 进程已退出 ✓", 'success');
                @\fclose($conn);
                return true;
            }
            
            $read = [$conn];
            $write = $except = null;
            // 缩短 select 超时到 0.5 秒，更快响应
            $ready = @\stream_select($read, $write, $except, 0, 500000);
            
            if ($ready === false) {
                // stream_select 错误，连接可能已断开
                break;
            }
            
            if ($ready > 0) {
                $data = @\fread($conn, 4096);
                if ($data === false || $data === '') {
                    // 连接断开 - Master 已关闭 IPC
                    $this->ipcMsg("Master 已关闭连接 ✓", 'success');
                    @\fclose($conn);
                    
                    // 快速等待 Master 进程完全退出
                    return $this->waitForMasterExit($masterPid);
                }
                
                // 解析消息
                $lines = \explode("\n", \trim($data));
                foreach ($lines as $line) {
                    if (empty($line)) {
                        continue;
                    }
                    $msg = \Weline\Server\IPC\ControlMessage::decode($line);
                    if ($msg === null) {
                        continue;
                    }
                    
                    $type = $msg['type'] ?? '';
                    
                    // 处理进度消息
                    if ($type === \Weline\Server\IPC\ControlMessage::TYPE_COMMAND_RESULT) {
                        $message = $msg['message'] ?? '';
                        if ($message && $message !== $lastProgress) {
                            $this->ipcProgress($message);
                            $lastProgress = $message;
                            
                            // 解析进度信息：总实例数
                            if (\preg_match('/共\s*(\d+)\s*个实例待停止/', $message, $matches)) {
                                $totalInstances = (int) $matches[1];
                            }
                            // 检测单个子进程退出消息，提取 PID 去重
                            // 格式示例: "✓ HTTP Worker(PID:12345) 已退出" 或 "✓ HTTP Worker(PID:12345) 已断开连接"
                            if (\preg_match('/PID[:\s]*(\d+)\)?\s*(?:已退出|已断开连接)/', $message, $pidMatch)) {
                                $exitedPids[(int) $pidMatch[1]] = true;
                            }
                            // 只在 Orchestrator 明确发送 "Master 即将退出" 时才结束等待
                            if (\str_contains($message, 'Master 即将退出') || \str_contains($message, '所有子进程已完整退出')) {
                                $masterAboutToExit = true;
                            }
                        }
                    }
                }
            }
            
            // 只在 Master 明确发送 "即将退出" 后才进入等待退出流程
            if ($masterAboutToExit) {
                $this->ipcMsg("所有子进程已退出，等待 Master 清理...", 'success');
                @\fclose($conn);
                return $this->waitForMasterExit($masterPid);
            }
        }
        
        @\fclose($conn);
        
        // 超时前最后一次检查 Master 状态
        if (!Processer::processExists($masterPid)) {
            $this->ipcMsg("Master 进程已退出 ✓", 'success');
            return true;
        }
        
        $this->ipcMsg("等待超时（{$timeout}s）", 'error');
        return false;
    }
    
    /**
     * 等待 Master 进程退出（子进程已全部退出后调用）
     * 
     * 优化策略：使用 hasExitedFast() 快速检测
     * 当 Master 从 pid_index.json 删除自己的 PID 后，
     * hasExitedFast() 会立即返回 true，无需调用 tasklist/ps 等外部命令。
     */
    protected function waitForMasterExit(int $masterPid): bool
    {
        $tag = $this->printer->colorize('[IPC]', self::IPC_COLOR_TAG);
        $waitMsg = $this->printer->colorize('等待 Master 进程退出', self::IPC_COLOR_INFO);
        echo "  {$tag} {$waitMsg}";
        
        $deadline = \microtime(true) + self::MASTER_EXIT_TIMEOUT;
        $confirmed = 0;
        
        while (\microtime(true) < $deadline) {
            \usleep(200000); // 200ms
            echo $this->printer->colorize('.', self::IPC_COLOR_INFO);
            // 快速路径 + 真实进程校验双确认，避免“索引先删、进程未退”的假退出。
            if (Processer::hasExitedFast($masterPid) && !Processer::processExists($masterPid)) {
                $confirmed++;
            } else {
                $confirmed = 0;
            }
            if ($confirmed >= 2) {
                echo $this->printer->colorize(' 完成 ✓', self::IPC_COLOR_SUCCESS) . "\n";
                return true;
            }
        }
        
        // 最后一次检查
        if (Processer::hasExitedFast($masterPid) && !Processer::processExists($masterPid)) {
            echo $this->printer->colorize(' 完成 ✓', self::IPC_COLOR_SUCCESS) . "\n";
            return true;
        }
        
        echo $this->printer->colorize(' 超时', self::IPC_COLOR_ERROR) . "\n";
        return false;
    }
    
    /**
     * 清理残留进程
     * 
     * 当 Master IPC 失败时，按进程名前缀批量清理
     */
    protected function cleanupResidualProcesses(string $name, array $instanceData): void
    {
        $this->printer->note(__('清理残留进程...'));
        
        // 新前缀（当前版本使用）
        $prefixes = [
            'weline-wls-master-' . $name,
            'weline-wls-worker-' . $name,
            'weline-wls-dispatcher-' . $name,
            'weline-wls-session-' . $name,
            'weline-wls-redirect-' . $name,
        ];
        
        // 旧前缀兼容（历史版本可能遗留）
        $legacyPrefixes = [
            'weline-master-' . $name . '-worker-',
        ];
        
        $totalKilled = 0;
        foreach ($prefixes as $prefix) {
            $killed = Processer::killByProcessNamePrefix($prefix);
            $totalKilled += $killed;
        }
        
        // 清理旧前缀残留（每个 Worker ID）
        $count = (int)($instanceData['count'] ?? 4);
        foreach ($legacyPrefixes as $legacyPrefix) {
            for ($i = 1; $i <= $count; $i++) {
                $killed = Processer::killByProcessNamePrefix($legacyPrefix . $i);
                $totalKilled += $killed;
            }
        }
        
        if ($totalKilled > 0) {
            $this->printer->success(__('  清理了 %{1} 个进程 ✓', [$totalKilled]));
        } else {
            $this->printer->note(__('  无残留进程'));
        }
    }
    
    /**
     * 清理 PID 文件
     */
    protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
    {
        // Master
        Processer::removePidFile('--name=' . MasterProcess::getMasterProcessName($name));

        // 统一按服务元信息清理，新增服务无需改 stop 命令
        foreach ($info->services as $service) {
            $processName = (string)($service->metadata['process_name'] ?? '');
            if ($processName !== '') {
                Processer::removePidFile('--name=' . $processName);
            }
        }

        // 兼容历史命名前缀（防止老实例残留）
        $count = $info->workerCount;
        for ($i = 1; $i <= $count; $i++) {
            Processer::removePidFile('--name=weline-wls-worker-' . $name . '-' . $i);
            Processer::removePidFile('--name=weline-master-' . $name . '-worker-' . $i);
        }
        Processer::removePidFile('--name=weline-wls-dispatcher-' . $name);
        Processer::removePidFile('--name=weline-wls-session-' . $name);
        Processer::removePidFile('--name=' . MasterProcess::HTTP_REDIRECT_PROCESS_NAME . '-' . $name);
        
        // 清理残留 PID 文件
        Processer::cleanupStalePidFiles();
    }
    
    /**
     * 释放启动锁
     * 
     * 服务器停止后删除启动锁文件，允许重新启动实例
     */
    protected function releaseStartLock(string $instanceName): void
    {
        $lockDir = Env::VAR_DIR . 'server' . DS . 'locks' . DS;
        $lockFile = $lockDir . 'start_' . $instanceName . '.lock';
        
        if (\is_file($lockFile)) {
            @\unlink($lockFile);
            $this->printer->note(__('启动锁已释放 ✓'));
        }
    }
    
    /**
     * 清理所有 weline-wls 前缀的残留进程
     * 
     * 使用快速的 PID 文件查找方式，避免 Windows 上的慢速系统调用
     */
    protected function cleanupAllWlsProcesses(string $instanceName): void
    {
        // 从 name_index 快速读取并杀死残留进程
        $nameIndex = Processer::readNameIndex();
        $currentPid = \getmypid();
        $totalKilled = 0;
        
        // 匹配 weline-wls-*-{instanceName} 的进程
        $targetPrefix = 'weline-wls-';
        $instanceSuffix = '-' . $instanceName;
        
        foreach ($nameIndex as $pname => $entries) {
            // 检查是否是 weline-wls 进程且属于当前实例
            $taskName = $pname;
            if (\str_starts_with($taskName, '--name=')) {
                $taskName = \substr($taskName, 7);
            }
            
            if (!\str_starts_with($taskName, $targetPrefix)) {
                continue;
            }
            
            // 检查是否属于当前实例
            if (\strpos($taskName, $instanceSuffix) === false) {
                continue;
            }
            
            // 遍历该 pname 下的所有 PID
            foreach ($entries as $entry) {
                $pid = (int) ($entry['pid'] ?? 0);
                if ($pid <= 0 || $pid === $currentPid) {
                    continue;
                }
                
                if (Processer::processExists($pid)) {
                    Processer::killByPid($pid, true);
                    $totalKilled++;
                }
            }
        }
        
        if ($totalKilled > 0) {
            $this->printer->note(__('清理了 %{1} 个残留 WLS 进程', [$totalKilled]));
        }
    }
    
    /**
     * 停止所有实例
     */
    protected function stopAllInstances(bool $force = false): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instances = \is_dir($instanceDir) ? \glob($instanceDir . '*.json') : [];
        $cliService = ObjectManager::getInstance(CliServerService::class);
        $cliStatus = $cliService->getCliServerStatus();

        if (empty($instances) && !$cliStatus) {
            $this->printer->warning(__('没有正在运行的实例'));
            return;
        }

        $this->printer->setup(__('停止所有服务器实例'));
        echo "\n";

        if (!empty($instances)) {
            $totalInstances = \count($instances);
            $this->printer->note(__('发现 %{1} 个 Weline Server 实例', [$totalInstances]));
            echo "\n";
            foreach ($instances as $instanceFile) {
                $name = \basename($instanceFile, '.json');
                $this->printer->note(__('正在停止实例 [%{1}]...', [$name]));
                $this->stopInstance($name, $force);
                echo "\n";
            }
            $this->printer->success(__('所有 Weline Server 实例已停止'));
        }

        if ($cliStatus) {
            echo "\n";
            $this->printer->note(__('正在停止 PHP 内置服务器 (cli-server)...'));
            $this->stopCliServer($force);
            $this->printer->success(__('PHP 内置服务器已停止'));
        }
    }

    /**
     * 停止 PHP 内置 CLI 服务器
     */
    protected function stopCliServer(bool $force = false): void
    {
        $args = $force ? ['force' => true, 'f' => true] : [];
        $cliStop = ObjectManager::getInstance(CliStop::class);
        $cliStop->execute($args, []);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止 Weline Server 或 PHP 内置服务器实例');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:stop [name]',
            __('停止正在运行的 Weline Server 或 PHP 内置服务器实例'),
            [
                '[name]' => __('实例名称（默认：default；cli/cli-server 表示 PHP 内置服务器）'),
                '-a, --all' => __('停止所有运行中的实例（含 Weline Server 与 CLI 服务器）'),
                '-f, --force' => __('强制停止（缩短超时时间）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止默认实例') => 'php bin/w server:stop',
                __('停止指定实例') => 'php bin/w server:stop api-server',
                __('停止 PHP 内置服务器') => 'php bin/w server:stop cli-server',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('强制停止') => 'php bin/w server:stop -f',
            ]
        );
    }
}
