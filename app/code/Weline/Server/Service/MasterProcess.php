<?php
declare(strict_types=1);

/**
 * Weline Server - 精简版 Master 进程管理服务
 *
 * 重构后职责：
 * - 解析启动参数，构建 ServiceContext
 * - 创建 ServiceOrchestrator 并委托服务管理
 * - 信号处理
 * - 向后兼容的公共接口
 *
 * 核心服务管理逻辑已移至 ServiceOrchestrator。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;

class MasterProcess
{
    /**
     * 运行模式常量
     */
    public const MODE_LEGACY = 'legacy';
    public const MODE_LINUX_DIRECT = 'linux-direct';
    public const MODE_WINDOWS_DISPATCHER = 'windows-dispatcher';

    /**
     * Master 进程名统一前缀
     */
    public const MASTER_PROCESS_NAME_PREFIX = 'weline-wls-master-';

    /**
     * HTTP 重定向进程名
     */
    public const HTTP_REDIRECT_PROCESS_NAME = 'weline-wls-redirect';

    /**
     * Session Server 进程名
     */
    public const SESSION_SERVER_PROCESS_NAME = 'weline-wls-session';
    
    /** IPC 消息颜色 ANSI 转义码 */
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BLUE = "\033[34m";    // 一般信息
    private const ANSI_GREEN = "\033[32m";   // 上报成功
    private const ANSI_YELLOW = "\033[33m";  // 通知排水/重载
    private const ANSI_RED = "\033[31m";     // 通知停止/错误

    /**
     * Worker 状态常量
     */
    public const WORKER_STATE_STARTING = 'starting';
    public const WORKER_STATE_RUNNING = 'running';
    public const WORKER_STATE_DRAINING = 'draining';
    public const WORKER_STATE_STOPPED = 'stopped';

    protected string $instanceName = '';
    protected string $mode = self::MODE_LEGACY;
    protected int $mainPort = 0;
    protected array $config = [];
    protected bool $running = true;
    protected bool $frontend = false;
    protected int $controlPort = 0;
    protected string $sslCert = '';
    protected string $sslKey = '';
    protected bool $sslEnabled = false;
    protected int $httpRedirectPort = 0;
    
    // 运行态配置（由 Start.php 传递）
    protected ?bool $dispatcherEnabled = null;
    protected int|string|null $workerCount = null;
    protected ?int $workerBasePort = null;
    protected ?int $workerPort = null;

    protected ?Printing $printer = null;
    protected ?WlsLogger $logger = null;
    protected ?ServiceOrchestrator $orchestrator = null;
    protected ?ServiceContext $context = null;

    /**
     * 启动完成后的回调（用于释放启动锁等）
     * @var ?\Closure
     */
    protected ?\Closure $onStartedCallback = null;

    public function __construct()
    {
        $this->logger = WlsLogger::getInstance();
    }

    public function setPrinter(Printing $printer): self
    {
        $this->printer = $printer;
        return $this;
    }

    /**
     * 设置启动完成后的回调
     * 
     * 在所有子进程启动完成后调用，用于释放启动锁等资源。
     */
    public function setOnStartedCallback(\Closure $callback): self
    {
        $this->onStartedCallback = $callback;
        return $this;
    }

    public function setMode(string $mode): self
    {
        $validModes = [self::MODE_LEGACY, self::MODE_LINUX_DIRECT, self::MODE_WINDOWS_DISPATCHER];
        if (\in_array($mode, $validModes, true)) {
            $this->mode = $mode;
        }
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMainPort(int $port): self
    {
        $this->mainPort = $port;
        return $this;
    }
    
    /**
     * 设置 Dispatcher 是否启用（运行态配置）
     */
    public function setDispatcherEnabled(?bool $enabled): self
    {
        $this->dispatcherEnabled = $enabled;
        return $this;
    }
    
    /**
     * 设置 Worker 数量（运行态配置）
     */
    public function setWorkerCount(int|string|null $count): self
    {
        $this->workerCount = $count;
        return $this;
    }
    
    /**
     * 设置 Worker 基础端口（运行态配置）
     */
    public function setWorkerBasePort(?int $port): self
    {
        $this->workerBasePort = $port;
        return $this;
    }
    
    /**
     * 设置首个 Worker 端口（运行态配置）
     */
    public function setWorkerPort(?int $port): self
    {
        $this->workerPort = $port;
        return $this;
    }

    /**
     * 初始化 Master 进程
     */
    public function init(
        string $instanceName,
        array $config,
        string $workerScript,
        string $sslCert = '',
        string $sslKey = '',
        bool $sslEnabled = false,
        int $httpRedirectPort = 0,
        bool $frontend = false
    ): self {
        $this->instanceName = $instanceName;
        $this->config = $config;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        $this->sslEnabled = $sslEnabled;
        $this->frontend = $frontend;

        // 前台模式：启用 Logger 控制台输出
        if ($frontend) {
            $this->logger->setStdoutEnabled(true);
            $this->logger->setProcessTag('Master');
        }

        $this->log('========================================');
        $this->log(__('Master 初始化开始 (Orchestrator 模式)'));
        $this->log(__('  实例名称: %{1}', [$instanceName]));
        $this->log(__('  运行模式: %{1}', [$this->mode]));
        $this->log(__('  前台模式: %{1}', [$frontend ? 'Yes' : 'No']));

        $port = (int) ($config['port'] ?? 80);
        $this->mainPort = $port;
        $this->httpRedirectPort = $httpRedirectPort;

        $this->log(__('Master 初始化完成'));
        $this->log('========================================');

        return $this;
    }

    /**
     * 运行 Master 主循环
     */
    public function run(): void
    {
        $this->log(__('启动 Master 进程...'));
        try {
            // 注册 Master PID 到索引（用于快速检测 Master 是否退出）
            $this->registerMasterPid();

            // 初始化控制端口
            $this->controlPort = Processer::findAvailablePort(19980);
            $this->log(__('  控制端口: %{1}', [$this->controlPort]));

            // 构建 ServiceContext
            $this->context = $this->buildContext();

            // 创建 Orchestrator
            $this->orchestrator = new ServiceOrchestrator();
            $this->orchestrator->loadProviders();

            // 注册信号处理
            $this->registerSignalHandlers();

            // 启动所有服务
            $this->log(__('正在启动子进程...'));
            $this->orchestrator->startAll($this->context);
            $this->log(__('子进程启动完成'));

            // 释放启动锁（允许其他进程检测服务器状态或重新启动）
            $this->releaseStartupLock();

            // 保存 Master 信息
            $this->saveMasterInfo();

            // 进入主循环
            $this->log(__('Master 进入主循环，监控子进程...'));
            $this->orchestrator->runLoop();

            $this->log(__('Master 主循环结束'));
        } finally {
            $this->unregisterMasterPid();
        }
    }
    
    /**
     * 注册 Master PID 到索引
     * 
     * 这样 CLI 可以通过检查索引快速判断 Master 是否已退出，
     * 而不需要调用 tasklist/ps 等外部命令。
     */
    private function registerMasterPid(): void
    {
        $masterPid = \getmypid();
        $masterName = '--name=' . self::getMasterProcessName($this->instanceName);
        
        Processer::setPid($masterName, $masterPid);
        $this->log(__('Master PID %{1} 已注册到索引', [$masterPid]));
    }

    /**
     * 从进程索引移除 Master PID。
     */
    private function unregisterMasterPid(): void
    {
        $masterName = '--name=' . self::getMasterProcessName($this->instanceName);
        Processer::removePidFile($masterName);
        $this->log(__('Master PID 索引已移除'));
    }

    /**
     * 构建 ServiceContext
     */
    private function buildContext(): ServiceContext
    {
        $envConfig = Env::getInstance()->getConfig() ?: [];

        return new ServiceContext(
            instanceName: $this->instanceName,
            epoch: 1,
            controlPort: $this->controlPort,
            masterPid: \getmypid(),
            host: $this->config['host'] ?? '0.0.0.0',
            mainPort: $this->mainPort,
            sslEnabled: $this->sslEnabled,
            sslCert: $this->sslCert,
            sslKey: $this->sslKey,
            mode: $this->mode,
            daemon: !$this->frontend,
            debug: (bool) ($this->config['debug'] ?? false),
            frontend: $this->frontend,
            envConfig: $envConfig,
            httpRedirectPort: $this->httpRedirectPort,
            // 运行态配置：由 Start.php 传入，优先级高于 envConfig
            dispatcherEnabled: $this->dispatcherEnabled,
            workerCount: $this->workerCount,
            workerBasePort: $this->workerBasePort,
            workerPort: $this->workerPort,
        );
    }

    /**
     * 注册信号处理器
     */
    private function registerSignalHandlers(): void
    {
        // Linux/macOS: 使用 pcntl_signal
        if (\function_exists('pcntl_signal')) {
            \pcntl_async_signals(true);

            // SIGTERM / SIGINT: 优雅停止（带控制台输出）
            \pcntl_signal(SIGTERM, function () {
                $this->log(__('收到 SIGTERM 信号，开始优雅停止...'));
                $this->stopWithProgress('SIGTERM');
            });

            \pcntl_signal(SIGINT, function () {
                $this->log(__('收到 SIGINT 信号，开始优雅停止...'));
                $this->stopWithProgress('SIGINT (Ctrl+C)');
            });

            // SIGHUP: 重载
            \pcntl_signal(SIGHUP, function () {
                $this->log(__('收到 SIGHUP 信号，开始重载...'));
                $this->reload();
            });

            // SIGUSR1: 状态报告
            \pcntl_signal(SIGUSR1, function () {
                $status = $this->getStatus();
                $this->log(__('状态报告: %{1}', [\json_encode($status, JSON_PRETTY_PRINT)]));
            });

            $this->log(__('已注册 pcntl 信号: SIGTERM, SIGINT, SIGHUP, SIGUSR1'));
            return;
        }

        // Windows: 使用 sapi_windows_set_ctrl_handler
        if (\function_exists('sapi_windows_set_ctrl_handler')) {
            \sapi_windows_set_ctrl_handler(function (int $event): bool {
                if ($event === \PHP_WINDOWS_EVENT_CTRL_C || $event === \PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    $this->log(__('Windows 模式：收到 Ctrl+C 信号，执行清理...'));
                    $this->stopWithProgress('Ctrl+C (Windows)');
                    return true;
                }
                return false;
            });
            $this->log(__('Windows 模式：已注册 Ctrl+C 处理函数（sapi_windows_set_ctrl_handler）'));
            return;
        }

        // 后备：register_shutdown_function（无法捕获 Ctrl+C，但可处理正常退出）
        \register_shutdown_function(function () {
            if ($this->running) {
                $this->log(__('检测到异常退出，执行清理...'));
                $this->stop();
            }
        });
        $this->log(__('已注册 shutdown 清理函数（Ctrl+C 可能不会触发优雅停止）'), 'warning');
    }

    /**
     * 停止 Master（带控制台进度输出，用于信号处理）
     */
    public function stopWithProgress(string $signal): void
    {
        $this->running = false;
        
        // 前台模式时输出停止进度
        if ($this->frontend) {
            echo "\n";
            echo "  ╔══════════════════════════════════════════════════════════════╗\n";
            echo "  ║  收到 {$signal} 信号，开始优雅停止...                          ║\n";
            echo "  ╚══════════════════════════════════════════════════════════════╝\n";
            
            // 设置 Orchestrator 输出到控制台
            $this->orchestrator?->setConsoleProgressEnabled(true);
        }
        
        $this->orchestrator?->stopAll($signal);
        $this->orchestrator?->stop();
        
        if ($this->frontend) {
            echo "  ✓ Master 退出流程已完成（进程即将退出）\n";
        }
    }

    /**
     * 停止 Master（静默模式，用于 IPC 命令）
     */
    public function stop(): void
    {
        $this->running = false;
        $this->orchestrator?->stopAll('manual');
        $this->orchestrator?->stop();
    }

    /**
     * 重载所有服务
     */
    public function reload(string $type = 'code'): void
    {
        $this->orchestrator?->reloadAll($type);
    }

    /**
     * 获取状态
     */
    public function getStatus(): array
    {
        return $this->orchestrator?->getStatus() ?? [
            'running' => false,
            'message' => 'Orchestrator not initialized',
        ];
    }

    /**
     * 保存 Master 信息到实例文件
     * 
     * 注意：合并现有数据而非覆盖，保留 Start.php 保存的 worker_port、count 等字段
     */
    public function saveMasterInfo(): void
    {
        $instanceFile = $this->getInstanceFile();
        $dir = \dirname($instanceFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        // 读取现有数据并合并，保留 Start.php 保存的配置
        $existingData = [];
        if (\is_file($instanceFile)) {
            $content = @\file_get_contents($instanceFile);
            if ($content !== false) {
                $existingData = \json_decode($content, true) ?: [];
            }
        }

        // Master 进程的状态信息
        $masterData = [
            'master_pid' => \getmypid(),
            'master_enabled' => true,
            'master_started_at' => \date('Y-m-d H:i:s'),
            'master_mode' => $this->mode,
            'main_port' => $this->mainPort,
            'control_port' => $this->controlPort,
            'instance_name' => $this->instanceName,
            'orchestrator_mode' => true,
        ];

        // 合并：保留现有配置（如 worker_port、count、ssl_enabled 等）并更新 Master 状态
        $data = \array_merge($existingData, $masterData);

        @\file_put_contents($instanceFile, \json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * 获取实例文件路径
     */
    protected function getInstanceFile(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
    }

    /**
     * 获取 Master 进程名
     */
    public static function getMasterProcessName(string $instanceName): string
    {
        return self::MASTER_PROCESS_NAME_PREFIX . $instanceName;
    }

    /**
     * 获取实例的 Master 信息
     */
    public static function getMasterInfo(string $instanceName = 'default'): ?array
    {
        $file = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!\is_file($file)) {
            return null;
        }
        $content = @\file_get_contents($file);
        if (!$content) {
            return null;
        }
        $data = \json_decode($content, true);
        if (!\is_array($data) || empty($data['master_enabled'])) {
            return null;
        }
        return $data;
    }

    /**
     * 检查指定实例的 Master 是否运行中
     */
    public static function isMasterRunning(string $instanceName = 'default'): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['master_pid'])) {
            return false;
        }
        return Processer::processExists((int) $info['master_pid']);
    }

    /**
     * 格式化 IPC 消息（带颜色）
     * 
     * @param string $message 消息内容
     * @param string $type 消息类型：success, drain, stop, error, info
     */
    protected static function ipcMsg(string $message, string $type = 'info'): void
    {
        $color = match ($type) {
            'success' => self::ANSI_GREEN,   // 绿色：上报成功
            'drain' => self::ANSI_YELLOW,    // 黄色：通知排水/重载
            'stop' => self::ANSI_RED,        // 红色：通知停止
            'error' => self::ANSI_RED,       // 红色：错误
            default => self::ANSI_BLUE,      // 蓝色：一般信息
        };
        
        $tag = self::ANSI_BLUE . '[IPC]' . self::ANSI_RESET;
        $content = $color . $message . self::ANSI_RESET;
        echo "  {$tag} {$content}\n";
    }

    /**
     * 通过 IPC 控制通道发送停止命令给 Master
     * 
     * @param string $instanceName 实例名称
     * @param bool $verbose 是否输出详细日志到控制台
     * @return bool 是否发送成功
     */
    public static function sendStopCommand(string $instanceName = 'default', bool $verbose = true): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            if ($verbose) {
                self::ipcMsg("无法获取实例 [{$instanceName}] 的控制端口信息", 'error');
            }
            return false;
        }

        $controlPort = (int) $info['control_port'];
        $masterPid = (int) ($info['master_pid'] ?? 0);
        $host = '127.0.0.1';

        if ($verbose) {
            self::ipcMsg("连接 Master (PID:{$masterPid}) 控制端口 {$host}:{$controlPort}...", 'info');
        }

        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            if ($verbose) {
                self::ipcMsg("连接失败: {$errstr} (errno:{$errno})", 'error');
            }
            return false;
        }

        if ($verbose) {
            self::ipcMsg("连接成功 ✓", 'success');
            self::ipcMsg("发送 STOP 命令...", 'stop');
        }

        $stopMsg = ControlMessage::command(ControlMessage::ACTION_STOP);
        $written = @\fwrite($conn, $stopMsg);
        
        if ($written === false || $written === 0) {
            if ($verbose) {
                self::ipcMsg("发送命令失败", 'error');
            }
            @\fclose($conn);
            return false;
        }

        if ($verbose) {
            self::ipcMsg("等待 Master 响应...", 'stop');
        }

        // 设置阻塞读取超时
        \stream_set_timeout($conn, 5);
        \stream_set_blocking($conn, true);
        
        $response = '';
        $deadline = \microtime(true) + 5;
        
        while (\microtime(true) < $deadline) {
            $read = [$conn];
            $write = $except = null;
            $ready = @\stream_select($read, $write, $except, 1);
            
            if ($ready === false) {
                break;
            }
            
            if ($ready > 0) {
                $data = @\fread($conn, 4096);
                if ($data === false || $data === '') {
                    // 连接关闭
                    break;
                }
                $response .= $data;
                if (\strpos($response, "\n") !== false) {
                    break;
                }
            }
        }
        @\fclose($conn);

        $msg = ControlMessage::decode($response);
        $success = $msg !== null && ($msg['success'] ?? false);
        
        if ($verbose) {
            if ($success) {
                $message = $msg['message'] ?? 'OK';
                self::ipcMsg("Master 响应: {$message}", 'success');
                self::ipcMsg("Master 开始广播 SHUTDOWN 给所有子进程...", 'stop');
            } else {
                // 如果响应为空但命令发送成功，可能 Master 收到命令后就开始停止了
                // 这种情况下也认为成功
                if ($response === '' && $written > 0) {
                    self::ipcMsg("命令已发送，Master 正在处理...", 'drain');
                    return true;
                }
                self::ipcMsg("Master 响应失败或超时 (response: " . \strlen($response) . " bytes)", 'error');
            }
        }

        return $success;
    }

    /**
     * 通过 IPC 控制通道发送重载命令给 Master
     * 
     * @param string $instanceName 实例名称
     * @param string $type 重载类型 (code, force, cache)
     * @return bool 是否发送成功
     */
    public static function sendReloadCommand(string $instanceName = 'default', string $type = 'code'): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            return false;
        }

        $controlPort = (int) $info['control_port'];
        $host = '127.0.0.1';

        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 3);
        if (!$conn) {
            return false;
        }

        $reloadMsg = ControlMessage::command(ControlMessage::ACTION_RELOAD, $type);
        @\fwrite($conn, $reloadMsg);

        \stream_set_timeout($conn, 3);
        $response = @\fread($conn, 4096);
        @\fclose($conn);

        if ($response) {
            $msg = ControlMessage::decode($response);
            return $msg !== null && ($msg['success'] ?? false);
        }

        // 没有收到响应，视为失败（可能 Master 进程已退出或无响应）
        return false;
    }

    /**
     * 通过 IPC 控制通道查询 Master 状态
     * 
     * @param string $instanceName 实例名称
     * @param float $timeout 超时时间（秒）
     * @return array|null 状态数据或 null（超时/失败）
     */
    public static function sendStatusQuery(string $instanceName = 'default', float $timeout = 3): ?array
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            return null;
        }

        $controlPort = (int) $info['control_port'];
        $host = '127.0.0.1';

        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, $timeout);
        if (!$conn) {
            return null;
        }

        $statusMsg = ControlMessage::command(ControlMessage::ACTION_STATUS);
        @\fwrite($conn, $statusMsg);

        \stream_set_timeout($conn, (int) \ceil($timeout));
        $response = @\fread($conn, 65536);
        @\fclose($conn);

        if ($response) {
            $msg = ControlMessage::decode($response);
            if ($msg !== null && ($msg['success'] ?? false)) {
                return $msg['data'] ?? [];
            }
        }

        return null;
    }

    /**
     * 获取 Orchestrator 实例
     */
    public function getOrchestrator(): ?ServiceOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * 获取控制端口
     */
    public function getControlPort(): int
    {
        return $this->controlPort;
    }

    /**
     * 日志输出
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $formatted = "[Master] {$message}";

        switch ($level) {
            case 'error':
                WlsLogger::error_($formatted);
                break;
            case 'warning':
                WlsLogger::warning_($formatted);
                break;
            case 'debug':
                WlsLogger::debug_($formatted);
                break;
            default:
                WlsLogger::info_($formatted);
        }

        // 前台模式下，WlsLogger 已输出到控制台，无需再通过 printer 重复输出
        // 后台模式下，通过 printer 输出（此时 Logger 只写文件）
        if (!$this->frontend && $this->printer !== null) {
            $this->printer->note($formatted);
        }
    }

    // ========== 向后兼容的方法（新架构中由 Orchestrator 管理，这些方法仅保留接口兼容）==========

    /**
     * 设置 Worker PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setWorkerPids(array $pids): self
    {
        return $this;
    }

    /**
     * 设置复活模式
     * @deprecated 由 Orchestrator 管理
     */
    public function setResurrectionMode(bool $resurrection): self
    {
        return $this;
    }

    /**
     * 设置 Dispatcher PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setDispatcherPid(int $pid): self
    {
        return $this;
    }

    /**
     * 设置 HTTP Redirect PID
     * @deprecated 由 Orchestrator 管理
     */
    public function setHttpRedirectPid(int $pid): self
    {
        return $this;
    }

    /**
     * 清除 Master 信息（用于 stop 命令）
     */
    public static function clearMasterInfo(string $instanceName = 'default'): void
    {
        $file = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    /**
     * 服务异常标记文件路径
     */
    public static function getServiceExceptionFile(string $instanceName): string
    {
        $dir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir . $instanceName . '.exception';
    }

    /**
     * 检查服务是否存在异常标记
     */
    public static function hasServiceException(string $instanceName): bool
    {
        return \is_file(self::getServiceExceptionFile($instanceName));
    }

    /**
     * 设置服务异常标记
     */
    public static function setServiceException(string $instanceName, string $reason = ''): void
    {
        $file = self::getServiceExceptionFile($instanceName);
        @\file_put_contents($file, $reason ?: 'unknown');
    }

    /**
     * 清除服务异常标记
     */
    public static function clearServiceException(string $instanceName): void
    {
        $file = self::getServiceExceptionFile($instanceName);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    /**
     * 释放启动锁
     * 
     * 在所有子进程启动完成后调用，允许其他进程检测服务器状态或重新启动。
     * 优先使用回调（可正确关闭文件句柄），否则直接删除锁文件。
     */
    private function releaseStartupLock(): void
    {
        // 优先使用回调（Start.php 传入的 releaseStartLock 方法可正确关闭句柄）
        if ($this->onStartedCallback !== null) {
            ($this->onStartedCallback)();
            $this->log(__('启动锁已释放（回调）'));
            return;
        }

        // 后备方案：直接删除锁文件
        $lockFile = Env::VAR_DIR . 'server' . DS . 'locks' . DS . 'start_' . $this->instanceName . '.lock';
        
        if (\is_file($lockFile)) {
            @\unlink($lockFile);
            $this->log(__('启动锁已释放'));
        }
    }
}
