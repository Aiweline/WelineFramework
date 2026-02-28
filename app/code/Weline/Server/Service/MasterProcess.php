<?php
declare(strict_types=1);

/**
 * Weline Server - Master 进程管理服务
 *
 * 职责：
 * - 重载：接收 SIGHUP，由 Master 统一写重载标记文件，Worker 轮询后自行重载（不直接向 Worker 发信号）
 * - 定期巡检：主循环每 healthCheckInterval 秒做健康检查，发现 Worker 端口无人监听则尝试重启
 * - Worker 异常退出时自动重启，并限制重启频率避免抖动
 *
 * 性能：Master 为独立进程，主循环仅 sleep(5)+少量端口检测与写文件，对 Worker 无性能影响。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\LogBuffer;
use Weline\Server\IPC\MasterControlServer;

class MasterProcess
{
    /**
     * 运行模式常量
     */
    public const MODE_LEGACY = 'legacy';           // 传统模式（兼容旧逻辑）
    public const MODE_LINUX_DIRECT = 'linux-direct';   // Linux SO_REUSEPORT 直连模式
    public const MODE_WINDOWS_DISPATCHER = 'windows-dispatcher'; // Windows Dispatcher TCP 透传模式
    
    /**
     * Master 进程名统一前缀（用于识别与按前缀清理逃逸 Master）
     * 完整进程名：weline-wls-master-{instanceName}
     */
    public const MASTER_PROCESS_NAME_PREFIX = 'weline-wls-master-';
    
    /**
     * Worker 生命周期状态（防重复启动）
     */
    public const WORKER_STATE_STOPPED  = 'stopped';
    public const WORKER_STATE_STARTING = 'starting';
    public const WORKER_STATE_RUNNING  = 'running';
    public const WORKER_STATE_DRAINING = 'draining';
    
    /**
     * 实例名称
     */
    protected string $instanceName = '';
    
    /**
     * 运行模式（legacy/linux-direct/windows-dispatcher）
     */
    protected string $mode = self::MODE_LEGACY;
    
    /**
     * 主监听端口（Linux 模式下所有 Worker 共用此端口）
     */
    protected int $mainPort = 0;
    
    /**
     * 服务器配置
     */
    protected array $config = [];
    
    /**
     * Worker 进程信息 [workerId => ['port' => int, 'pid' => int, 'restarts' => int]]
     */
    protected array $workers = [];
    
    /**
     * 是否继续运行
     */
    protected bool $running = true;
    
    /**
     * 健康检查间隔（秒）
     */
    protected int $healthCheckInterval = 5;
    
    /**
     * Worker 最大重启次数（达到后暂停重启一段时间）
     */
    protected int $maxRestarts = 10;
    
    /**
     * 重启计数重置间隔（秒）
     */
    protected int $restartCountResetInterval = 300;
    
    /**
     * 上次重启计数重置时间
     */
    protected int $lastRestartCountReset = 0;
    
    /**
     * 输出打印器
     */
    protected ?Printing $printer = null;
    
    /**
     * Worker 脚本路径
     */
    protected string $workerScript = '';
    
    /**
     * SSL 证书路径
     */
    protected string $sslCert = '';
    
    /**
     * SSL 私钥路径
     */
    protected string $sslKey = '';
    
    /**
     * HTTP 重定向进程信息（HTTPS 启用时由 Master 单独启动，不计入 Worker 数）
     * ['port' => int, 'pid' => int, 'restarts' => int, 'last_restart' => int] 或 null
     */
    protected ?array $httpRedirectWorker = null;
    
    /**
     * HTTP 重定向 Worker 脚本路径
     */
    protected string $httpRedirectScript = '';
    
    /**
     * Dispatcher 进程信息（流量分发模式启用时）
     * ['port' => int, 'pid' => int, 'restarts' => int, 'last_restart' => int, 'worker_ports' => int[]] 或 null
     */
    protected ?array $dispatcher = null;
    
    /**
     * Dispatcher 脚本路径
     */
    protected string $dispatcherScript = '';
    
    /**
     * 是否为前台模式（重启 Worker 时保持可见窗口）
     */
    protected bool $frontend = false;
    
    // ========== IPC 控制通道 ==========
    
    /**
     * IPC 控制服务器
     */
    protected ?MasterControlServer $controlServer = null;
    
    /**
     * 控制端口
     */
    protected int $controlPort = 0;
    
    /**
     * 日志缓冲
     */
    protected ?LogBuffer $logBuffer = null;
    
    /**
     * 是否正在执行滚动重启
     */
    protected bool $rollingRestart = false;
    
    /**
     * 是否正在执行批量强制重启（-f 模式）
     * 此标志为 true 时，禁止 handleWorkerDisconnect 触发复活逻辑
     */
    protected bool $forceRestarting = false;

    /**
     * 滚动重启队列（待重启的 Worker ID 列表）
     */
    protected array $rollingRestartQueue = [];
    
    /**
     * 当前正在排水的 Worker ID（同时只有一个）
     */
    protected int $drainingWorkerId = 0;
    
    /**
     * 滚动重启开始时间（用于超时检测）
     */
    protected int $rollingRestartStartTime = 0;
    
    /**
     * 当前 Worker 排水开始时间（用于单个 Worker 超时检测）
     */
    protected int $drainingStartTime = 0;
    
    /**
     * 维护 Worker 信息 [port => int, pid => int] 或空数组
     */
    protected array $maintenanceWorkers = [];
    
    /**
     * 内部停止标志（由控制通道 command 设置）
     */
    protected bool $shouldStopFlag = false;
    
    /**
     * 是否处于启动阶段（阶段 0～5 期间为 true，进入主循环前置为 false）
     * 启动阶段不执行健康检查、重启策略等，完全启动后才恢复。
     */
    protected bool $startupPhase = false;
    
    /**
     * Worker 重启互斥标志 [workerId => timestamp]
     * 
     * 防止多个路径（健康检查、滚动重启、IPC 断开回调）同时触发同一 Worker 的重启。
     * 启动前设置时间戳，启动完成后清除。超过 30 秒自动过期（防止死锁）。
     */
    protected array $restartingWorkers = [];
    
    /**
     * 是否为复活模式（Master 由 Worker 等子进程拉起）
     * 为 true 时在启动 Worker 前会先等待子进程通过 IPC 重连并 register，再仅对未重连的槽位启动新进程。
     */
    protected bool $resurrectionMode = false;
    
    public function __construct()
    {
    }
    
    /**
     * 设置打印器
     */
    public function setPrinter(Printing $printer): self
    {
        $this->printer = $printer;
        return $this;
    }
    
    /**
     * 设置运行模式
     * 
     * @param string $mode 模式标识（linux-direct/windows-dispatcher/legacy）
     * @return self
     */
    public function setMode(string $mode): self
    {
        $validModes = [self::MODE_LEGACY, self::MODE_LINUX_DIRECT, self::MODE_WINDOWS_DISPATCHER];
        if (\in_array($mode, $validModes, true)) {
            $this->mode = $mode;
        }
        return $this;
    }
    
    /**
     * 获取当前运行模式
     * 
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }
    
    /**
     * 设置主监听端口（Linux 模式下所有 Worker 共用此端口）
     * 
     * @param int $port 主端口
     * @return self
     */
    public function setMainPort(int $port): self
    {
        $this->mainPort = $port;
        return $this;
    }
    
    /**
     * 初始化 Master 进程
     * @param bool $sslEnabled 是否启用 HTTPS（未启用则不启动 HTTP 重定向）
     * @param int $httpRedirectPort HTTP 重定向监听端口，0 表示不启动
     * @param bool $frontend 是否为前台模式（重启 Worker 时保持可见窗口）
     */
    public function init(string $instanceName, array $config, string $workerScript, string $sslCert = '', string $sslKey = '', bool $sslEnabled = false, int $httpRedirectPort = 0, bool $frontend = false): self
    {
        $this->frontend = $frontend;
        $this->instanceName = $instanceName;
        $this->config = $config;
        $this->workerScript = $workerScript;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        $this->lastRestartCountReset = \time();
        
        $scriptDir = \dirname($workerScript);
        $this->httpRedirectScript = $scriptDir . \DIRECTORY_SEPARATOR . 'http_redirect_worker.php';
        // 统一 Dispatcher 脚本
        $this->dispatcherScript = $scriptDir . \DIRECTORY_SEPARATOR . 'dispatcher.php';
        
        // ========== 初始化日志：基础信息 ==========
        $this->log('========================================');
        $this->log(__('Master 初始化开始'));
        $this->log(__('  实例名称: %{1}', [$instanceName]));
        $this->log(__('  运行模式: %{1}', [$this->mode]));
        $this->log(__('  前台模式: %{1}', [$frontend ? 'Yes' : 'No']));
        $this->log(__('  PHP 版本: %{1} (SAPI: %{2})', [\PHP_VERSION, \PHP_SAPI]));
        $this->log(__('  操作系统: %{1}', [\PHP_OS]));
        $this->log(__('  Worker 脚本: %{1}', [$workerScript]));
        
        // 先解析配置
        $port = (int)($config['port'] ?? 80);
        $count = (int)($config['worker_count'] ?? 4);
        
        // ========== 初始化日志：网络配置 ==========
        $host = $config['host'] ?? '0.0.0.0';
        $this->log(__('  监听地址: %{1}:%{2}', [$host, $port]));
        $this->log(__('  Worker 数量: %{1}', [$count]));
        
        // 检测 Dispatcher 模式（优先使用配置，否则多 Worker + HTTPS 自动启用）
        $dispatcherEnabled = (bool)($config['dispatcher_enabled'] ?? ($sslEnabled && $count > 1));
        
        // Worker 端口：优先使用 config 中传递的值，否则使用外部端口
        $workerPort = (int)($config['worker_port'] ?? $port);
        
        // ========== 初始化日志：SSL 配置 ==========
        $this->log(__('  SSL 启用: %{1}', [$sslEnabled ? 'Yes' : 'No']));
        if ($sslEnabled) {
            $this->log(__('  SSL 证书: %{1}', [$sslCert ?: '(未配置)']));
            $this->log(__('  SSL 私钥: %{1}', [$sslKey ?: '(未配置)']));
            $this->log(__('  HTTP 重定向端口: %{1}', [$httpRedirectPort > 0 ? (string)$httpRedirectPort : '(未启用)']));
        }
        
        // 初始化 Worker 信息（含状态机字段，防重复启动）
        // 直连模式（SO_REUSEPORT）：所有 Worker 共用主端口，无需递增
        // Dispatcher/Legacy 模式：每个 Worker 占独立端口，递增分配
        $isDirectMode = ($this->mode === self::MODE_LINUX_DIRECT);
        $workerPorts = [];
        for ($i = 0; $i < $count; $i++) {
            $currentPort = $isDirectMode ? $workerPort : ($workerPort + $i);
            $this->workers[$i + 1] = [
                'port' => $currentPort,
                'pid' => 0,
                'restarts' => 0,
                'last_restart' => 0,
                'state' => self::WORKER_STATE_STOPPED,
                'state_since' => \time(),
            ];
            if (!$isDirectMode) {
                $workerPorts[] = $currentPort;
            }
        }
        
        // ========== 初始化日志：Worker 端口分配 ==========
        if ($isDirectMode) {
            $this->log(__('  Worker 端口: %{1}（%{2} 个 Worker 共用，SO_REUSEPORT）', [$workerPort, $count]));
        } else {
            $this->log(__('  Worker 端口分配: %{1}', [\implode(', ', $workerPorts)]));
        }
        
        // ========== 初始化日志：Dispatcher 配置 ==========
        $this->log(__('  Dispatcher 启用: %{1}', [$dispatcherEnabled ? 'Yes' : 'No']));
        
        // Dispatcher 模式：初始化 Dispatcher 进程信息（只要启用 Dispatcher 就初始化）
        if ($dispatcherEnabled) {
            $this->dispatcher = [
                'port' => $port,
                'pid' => 0,
                'restarts' => 0,
                'last_restart' => 0,
                'worker_ports' => $workerPorts,
            ];
            $this->log(__('  Dispatcher 监听端口: %{1} → Worker 端口: [%{2}]', [$port, \implode(', ', $workerPorts)]));
            // 注意：Dispatcher 模式仍需启动 HTTP Redirect Worker（Dispatcher 不处理 HTTP→HTTPS 重定向）
        }
        
        // HTTPS 启用且配置了 HTTP 重定向端口时，初始化独立 HTTP 重定向进程（不计入 worker_count）
        if ($sslEnabled && $httpRedirectPort > 0) {
            $this->httpRedirectWorker = [
                'port' => $httpRedirectPort,
                'pid' => 0,
                'restarts' => 0,
                'last_restart' => 0,
            ];
            $this->log(__('  HTTP→HTTPS 重定向进程: 端口 %{1}', [$httpRedirectPort]));
        }
        
        // ========== 初始化日志：运行参数 ==========
        $this->log(__('  健康检查间隔: %{1} 秒', [$this->healthCheckInterval]));
        $this->log(__('  最大重启次数: %{1} 次（%{2} 秒内重置）', [$this->maxRestarts, $this->restartCountResetInterval]));
        $this->log(__('Master 初始化完成'));
        $this->log('========================================');
        
        return $this;
    }
    
    /**
     * 设置 Worker PID
     */
    public function setWorkerPids(array $pids): self
    {
        $index = 1;
        foreach ($pids as $pid) {
            if (isset($this->workers[$index])) {
                $this->workers[$index]['pid'] = (int)$pid;
            }
            $index++;
        }
        return $this;
    }
    
    /**
     * 设置复活模式（由 runMasterOnly 调用）
     * 复活时先通过 IPC 等待在役子进程重连，再做通信检查，仅对未重连的槽位启动新进程。
     */
    public function setResurrectionMode(bool $resurrection): self
    {
        $this->resurrectionMode = $resurrection;
        return $this;
    }
    
    /**
     * 设置 Dispatcher PID（复活时从实例文件恢复，用于采纳在役进程）
     */
    public function setDispatcherPid(int $pid): self
    {
        if ($this->dispatcher !== null && $pid > 0) {
            $this->dispatcher['pid'] = $pid;
        }
        return $this;
    }
    
    /**
     * 设置 HTTP 重定向进程 PID（复活时从实例文件恢复，用于采纳在役进程）
     */
    public function setHttpRedirectPid(int $pid): self
    {
        if ($this->httpRedirectWorker !== null && $pid > 0) {
            $this->httpRedirectWorker['pid'] = $pid;
        }
        return $this;
    }
    
    /**
     * 获取实例文件路径
     */
    protected function getInstanceFile(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'instances' . DS . $this->instanceName . '.json';
    }
    
    /**
     * 保存 Master 进程信息到实例文件（原子更新，带文件锁）
     */
    public function saveMasterInfo(): void
    {
        $instanceFile = $this->getInstanceFile();
        if (!\is_file($instanceFile)) {
            return;
        }
        
        $masterPid = \getmypid();
        $masterMode = $this->mode;
        $mainPort = $this->mainPort;
        $controlPort = $this->controlPort;
        $workers = $this->workers;
        $httpRedirectWorker = $this->httpRedirectWorker;
        $dispatcher = $this->dispatcher;
        
        ServerInstanceService::atomicUpdateJson($instanceFile, function (array $data) use (
            $masterPid, $masterMode, $mainPort, $controlPort, $workers, $httpRedirectWorker, $dispatcher
        ): array {
            $data['master_pid'] = $masterPid;
            $data['master_enabled'] = true;
            $data['master_started_at'] = \date('Y-m-d H:i:s');
            $data['master_mode'] = $masterMode;
            $data['main_port'] = $mainPort;
            $data['control_port'] = $controlPort;
            $data['workers'] = $workers;
            if ($httpRedirectWorker !== null) {
                $data['http_redirect_port'] = $httpRedirectWorker['port'];
                $data['http_redirect_pid'] = $httpRedirectWorker['pid'];
            }
            if ($dispatcher !== null) {
                $data['dispatcher_enabled'] = true;
                $data['dispatcher_port'] = $dispatcher['port'];
                $data['dispatcher_pid'] = $dispatcher['pid'];
                $data['dispatcher_worker_ports'] = $dispatcher['worker_ports'] ?? [];
            }
            return $data;
        });
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
        return Processer::processExists((int)$info['master_pid']);
    }
    
    /**
     * 获取 Master 进程名（用于 Processer 注册与按前缀杀进程）
     * 统一前缀便于识别逃逸 Master 并一同按前缀清理。
     */
    public static function getMasterProcessName(string $instanceName): string
    {
        return self::MASTER_PROCESS_NAME_PREFIX . $instanceName;
    }
    
    /**
     * 服务异常标记文件路径（Master 复活三次失败后写入，等待人工干预）
     * 仅在此场景使用文件：此时无 Master 无法走 IPC，子进程需跨进程得知“停止复活”。
     * 停止/重启均由 Master 通过 IPC 广播 shutdown，不依赖文件信号。
     */
    public static function getServiceExceptionFile(string $instanceName): string
    {
        $dir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir . $instanceName . '.service_exception';
    }
    
    /**
     * 设置服务异常（复活失败等），停止策略运行，等待人工干预
     */
    public static function setServiceException(string $instanceName, string $message, int $attempts = 0): void
    {
        $file = self::getServiceExceptionFile($instanceName);
        $data = [
            'message' => $message,
            'attempts' => $attempts,
            'time' => \time(),
            'date' => \date('Y-m-d H:i:s'),
        ];
        @\file_put_contents($file, \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    /**
     * 清除服务异常（Master 正常启动时调用）
     */
    public static function clearServiceException(string $instanceName): void
    {
        @\unlink(self::getServiceExceptionFile($instanceName));
    }
    
    /**
     * 是否处于服务异常状态（停止策略，等待人工干预）
     */
    public static function hasServiceException(string $instanceName): bool
    {
        return \is_file(self::getServiceExceptionFile($instanceName));
    }
    
    /**
     * 运行 Master 进程（主循环）
     */
    public function run(): void
    {
        $this->startupPhase = true;
        self::clearServiceException($this->instanceName);
        $masterPid = \getmypid();
        
        // 检查进程权限（用于调试特权端口问题）
        $euid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : -1;
        $isRoot = $euid === 0;
        
        $this->log('========================================');
        $this->log(__('Master 进程启动'), 'success');
        $this->log(__('  PID: %{1}', [$masterPid]));
        $this->log(__('  euid: %{1}%{2}', [$euid, $isRoot ? ' (root)' : '']));
        $this->log(__('  内存使用: %{1} MB', [\round(\memory_get_usage(true) / 1024 / 1024, 2)]));
        $this->log(__('  启动时间: %{1}', [\date('Y-m-d H:i:s')]));
        $this->log('========================================');
        
        // macOS/Linux：检查是否需要特权端口权限
        if (!IS_WIN && $euid !== 0) {
            $port = (int)($this->config['port'] ?? 0);
            $httpRedirectPort = $this->httpRedirectWorker['port'] ?? 0;
            $needsPrivilege = ($port > 0 && $port < 1024) || ($httpRedirectPort > 0 && $httpRedirectPort < 1024);
            if ($needsPrivilege) {
                $this->log(__('错误：需要绑定特权端口 (<1024) 但当前进程不是 root (euid: %{1})！', [$euid]), 'error');
                $this->log(__('拒绝继续启动，避免后续子进程在非 root 下反复重启。'), 'error');
                throw new \RuntimeException((string)__('特权端口启动失败：Master 进程必须以 root 身份运行。请使用 sudo php bin/w server:start'));
            }
        }
        
        // ========== 阶段 0: 启动 IPC 控制服务器 ==========
        $this->log(__('[阶段 0/6] 启动 IPC 控制服务器...'));
        $ipcStartTime = \microtime(true);
        $this->startControlServer();
        $ipcElapsed = \round((\microtime(true) - $ipcStartTime) * 1000, 1);
        $this->log(__('[阶段 0/6] IPC 控制服务器启动完成 (%{1}ms)', [$ipcElapsed]));
        
        $this->log(__('[保存] 写入 Master 实例信息...'));
        $this->saveMasterInfo();
        
        // 复活模式：等待在役 Worker / Dispatcher / HTTP Redirect 通过 IPC 重连并 register，相互认识后再做启动决策
        if ($this->resurrectionMode && $this->controlServer) {
            $waitSeconds = 6;
            $this->log(__('复活模式：等待子进程重连 (Worker/Dispatcher/Redirect, %{1}s)...', [$waitSeconds]));
            for ($i = 0; $i < $waitSeconds; $i++) {
                $this->controlServer->poll(1, 0);
            }
            $registeredWorkers = $this->controlServer->getClientsByRole(ControlMessage::ROLE_WORKER);
            $registeredDisp = $this->controlServer->getClientsByRole(ControlMessage::ROLE_DISPATCHER);
            $registeredRedirect = $this->controlServer->getClientsByRole(ControlMessage::ROLE_REDIRECT);
            $this->log(__('复活模式：已重连 Worker %{1}/%{2}, Dispatcher %{3}, Redirect %{4}', [
                \count($registeredWorkers), \count($this->workers),
                \count($registeredDisp), \count($registeredRedirect),
            ]));
        }
        
        // ========== 阶段 1: 启动 Worker 进程 ==========
        $workerCount = \count($this->workers);
        $this->log(__('[阶段 1/6] 启动 %{1} 个 Worker 进程...', [$workerCount]));
        $workerStartTime = \microtime(true);
        $this->startAllWorkers();
        $workerElapsed = \round((\microtime(true) - $workerStartTime) * 1000, 1);
        $startedWorkers = 0;
        foreach ($this->workers as $w) {
            if (!empty($w['pid'])) $startedWorkers++;
        }
        $this->log(__('[阶段 1/6] Worker 启动完成: %{1}/%{2} 个成功 (%{3}ms)', [$startedWorkers, $workerCount, $workerElapsed]), $startedWorkers === $workerCount ? 'success' : 'warning');
        
        // ========== 阶段 2: 启动 Dispatcher 进程 ==========
        if ($this->dispatcher !== null) {
            $existingDispPid = (int)($this->dispatcher['pid'] ?? 0);
            if ($existingDispPid > 0 && Processer::isRunningByPid($existingDispPid)) {
                $this->log(__('[阶段 2/6] Dispatcher 已在役 (PID: %{1})，采纳并跳过启动', [$existingDispPid]));
            } else {
                $this->log(__('[阶段 2/6] 启动 Dispatcher 进程 (端口: %{1})...', [$this->dispatcher['port']]));
                $dispStartTime = \microtime(true);
                $pid = $this->startDispatcherProcess();
                $dispElapsed = \round((\microtime(true) - $dispStartTime) * 1000, 1);
                if ($pid > 0) {
                    $this->dispatcher['pid'] = $pid;
                    $this->log(__('[阶段 2/6] Dispatcher 启动成功，PID: %{1} (%{2}ms)', [$pid, $dispElapsed]), 'success');
                } else {
                    $this->log(__('[阶段 2/6] Dispatcher 启动失败 (%{1}ms)', [$dispElapsed]), 'error');
                }
            }
        } else {
            $this->log(__('[阶段 2/6] Dispatcher 未启用，跳过'));
        }
        
        // ========== 阶段 3: 启动 HTTP 重定向进程 ==========
        if ($this->httpRedirectWorker !== null && \is_file($this->httpRedirectScript)) {
            $existingRedirectPid = (int)($this->httpRedirectWorker['pid'] ?? 0);
            if ($existingRedirectPid > 0 && Processer::isRunningByPid($existingRedirectPid)) {
                $this->log(__('[阶段 3/6] HTTP 重定向进程已在役 (PID: %{1})，采纳并跳过启动', [$existingRedirectPid]));
            } else {
                $this->log(__('[阶段 3/6] 启动 HTTP→HTTPS 重定向进程 (端口: %{1})...', [$this->httpRedirectWorker['port']]));
                try {
                    $redirectStartTime = \microtime(true);
                    $pid = $this->startHttpRedirectWorker();
                    $redirectElapsed = \round((\microtime(true) - $redirectStartTime) * 1000, 1);
                    if ($pid > 0) {
                        $this->httpRedirectWorker['pid'] = $pid;
                        $this->log(__('[阶段 3/6] HTTP 重定向进程启动成功，PID: %{1} (%{2}ms)', [$pid, $redirectElapsed]), 'success');
                    } else {
                        $this->log(__('[阶段 3/6] HTTP 重定向进程启动失败 (%{1}ms)（非致命）', [$redirectElapsed]), 'warning');
                    }
                } catch (\RuntimeException $e) {
                    // HTTP 重定向启动失败不应该导致 Master 崩溃
                    $this->log(__('[阶段 3/6] HTTP 重定向启动异常: %{1}（非致命）', [$e->getMessage()]), 'warning');
                }
            }
        } else {
            $this->log(__('[阶段 3/6] HTTP 重定向未启用，跳过'));
        }
        
        $this->log(__('[保存] 更新 Master 实例信息...'));
        $this->saveMasterInfo();
        
        // ========== 阶段 4: 等待 Worker 就绪 ==========
        $this->log(__('[阶段 4/6] 等待所有子进程就绪...'));
        $readyStartTime = \microtime(true);
        $this->waitForWorkersReady();
        $readyElapsed = \round(\microtime(true) - $readyStartTime, 2);
        $this->log(__('[阶段 4/6] 子进程就绪检查完成 (%{1}s)', [$readyElapsed]));
        
        // ========== 阶段 5: 注册信号处理 ==========
        $this->log(__('[阶段 5/6] 注册信号处理器...'));
        // 注册信号处理
        if (\function_exists('pcntl_signal')) {
            // Linux/Mac: 使用 pcntl_signal
            \pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            \pcntl_signal(SIGINT, [$this, 'handleSignal']);
            \pcntl_signal(SIGHUP, [$this, 'handleSignal']);
            \pcntl_signal(SIGUSR1, [$this, 'handleSignal']);
            $this->log(__('  已注册 pcntl 信号: SIGTERM, SIGINT, SIGHUP, SIGUSR1'));
            
            // Mac/Linux 安全网：进程异常退出（fatal error 等）时确保子进程被清理
            \register_shutdown_function(function () {
                if (!$this->cleanedUp) {
                    $this->log(__('Mac/Linux 安全网：检测到进程退出但未完成清理，执行紧急清理...'), 'warning');
                    $this->signalAllChildren(\SIGTERM);
                    // 给子进程短暂的时间优雅退出
                    \usleep(500000); // 500ms
                    $this->cleanup();
                }
            });
            $this->log(__('  Mac/Linux 模式：已注册 register_shutdown_function 安全网'));
        } else {
            // Windows: 使用 sapi_windows_set_ctrl_handler 捕获 Ctrl+C（PHP 7.4+）
            // 注意：register_shutdown_function 在 Windows 上 Ctrl+C 时不会被调用！
            if (\function_exists('sapi_windows_set_ctrl_handler')) {
                // 保存引用到闭包中
                $masterRef = $this;
                \sapi_windows_set_ctrl_handler(function($event) use ($masterRef) {
                    $masterRef->log(__('Windows 模式：收到 Ctrl+C 信号，执行清理...'), 'warning');
                    $masterRef->running = false;
                    $masterRef->cleanup();
                    exit(0);
                }, true); // true = 捕获 Ctrl+C 和 Ctrl+Break
                $this->log(__('  Windows 模式：已注册 sapi_windows_set_ctrl_handler (Ctrl+C/Ctrl+Break)'));
            } else {
                // PHP < 7.4: 回退到 register_shutdown_function（虽然不可靠，但总比没有好）
                $bp = \defined('BP') ? BP : \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
                $instanceName = $this->instanceName;
                
                \register_shutdown_function(function() use ($bp, $instanceName) {
                    // 检查是否是正常退出（通过 $this->running = false）
                    // 如果不是正常退出，强制调用 cleanup
                    if ($this->running ?? true) {
                        $this->log(__('Windows 模式：检测到异常退出，执行清理...'), 'warning');
                        $this->running = false;
                    }
                    $this->cleanup();
                });
                $this->log(__('  Windows 模式：已注册 register_shutdown_function (降级模式，Ctrl+C 可能不触发)'), 'warning');
            }
        }
        $this->log(__('[阶段 5/6] 信号处理器注册完成'));
        
        // ========== 阶段 6: 进入主循环 ==========
        // 汇总启动信息
        $this->log('========================================');
        $this->log(__('Master 启动完成，进入主循环'), 'success');
        $this->log(__('  进程摘要:'));
        foreach ($this->workers as $wid => $winfo) {
            $status = !empty($winfo['pid']) ? 'PID ' . $winfo['pid'] : '未启动';
            $this->log(__('    Worker #%{1}: 端口 %{2}, %{3}', [$wid, $winfo['port'], $status]));
        }
        if ($this->dispatcher !== null) {
            $dStatus = !empty($this->dispatcher['pid']) ? 'PID ' . $this->dispatcher['pid'] : '未启动';
            $this->log(__('    Dispatcher: 端口 %{1}, %{2}', [$this->dispatcher['port'], $dStatus]));
        }
        if ($this->httpRedirectWorker !== null) {
            $rStatus = !empty($this->httpRedirectWorker['pid']) ? 'PID ' . $this->httpRedirectWorker['pid'] : '未启动';
            $this->log(__('    HTTP 重定向: 端口 %{1}, %{2}', [$this->httpRedirectWorker['port'], $rStatus]));
        }
        $this->log(__('  IPC 控制端口: %{1}', [$this->controlPort > 0 ? (string)$this->controlPort : '(未启用)']));
        $this->log(__('  健康检查间隔: %{1} 秒', [$this->healthCheckInterval]));
        $this->log(__('  内存使用: %{1} MB', [\round(\memory_get_usage(true) / 1024 / 1024, 2)]));
        $this->log('========================================');
        
        $this->startupPhase = false;
        // ========== 主循环：stream_select 驱动，融合控制通道 I/O ==========
        $lastHealthCheck = \time();
        $lastSaveInfo    = \time();
        
        while ($this->running) {
            try {
                // 处理信号
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                
                // 检查内部停止标志（由控制通道 command 设置）
                if ($this->shouldStopFlag) {
                    $this->log(__('收到停止命令，准备退出...'));
                    $this->running = false;
                    break;
                }
                
                // 轮询控制通道 I/O（1 秒超时，替代 sleep(1)）
                if ($this->controlServer) {
                    $this->controlServer->poll(1, 0);
                } else {
                    \sleep(1);
                }
                
                // 日志缓冲刷新
                if ($this->logBuffer) {
                    $this->logBuffer->tick();
                }
                
                $now = \time();
                
                // 重置重启计数
                $this->maybeResetRestartCounts();
                
                // 滚动重启超时检查（单个 Worker 排水超过 60 秒则强制跳过）
                $this->checkDrainingTimeout($now);
                
                // 健康检查并修复（每 healthCheckInterval 秒）
                if (($now - $lastHealthCheck) >= $this->healthCheckInterval) {
                    $this->healthCheckAndRepair();
                    $lastHealthCheck = $now;
                }
                
                // 更新 Master 信息（每 10 秒）
                if (($now - $lastSaveInfo) >= 10) {
                    $this->saveMasterInfo();
                    $lastSaveInfo = $now;
                }
                
            } catch (\Throwable $e) {
                // 看门狗不能因单次异常退出，只记录后继续
                $this->log(__('Master 巡检异常（已忽略，继续运行）: %{1}', [$e->getMessage()]), 'warning');
                if (\defined('DEV') && DEV) {
                    $this->log($e->getFile() . ':' . $e->getLine(), 'warning');
                }
            }
        }
        
        $this->log(__('Master 主循环退出 (running=%{1})', [$this->running ? 'true' : 'false']));
        $this->cleanup();
    }
    
    /**
     * 信号处理
     */
    public function handleSignal(int $signo): void
    {
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                $this->log(__('收到终止信号 (%{1})，准备停止...', [$signo]));
                $this->shouldStopFlag = true;
                $this->running = false;
                // Mac/Linux: 立即向所有子进程转发终止信号，确保子进程不会变成孤儿
                $this->signalAllChildren($signo);
                break;
            case SIGHUP:
                $this->log(__('收到重载信号 (SIGHUP)，发起滚动重启...'));
                $this->initiateRollingRestart(ControlMessage::RELOAD_TYPE_CODE);
                break;
            case SIGUSR1:
                $this->log(__('收到用户信号 (SIGUSR1)，输出状态...'));
                $this->logStatus();
                break;
        }
    }
    
    /**
     * 向所有已知子进程发送 POSIX 信号（Mac/Linux 专用）
     *
     * 收集 Worker、Dispatcher、HTTP 重定向的 PID，通过 posix_kill 直接发送。
     * 比 Processer::destroy()（需读 PID 文件）更快、更可靠，
     * 确保 Master 异常退出时子进程也能收到终止信号。
     */
    protected function signalAllChildren(int $signo = \SIGTERM): void
    {
        if (!\function_exists('posix_kill')) {
            return;
        }
        
        $childPids = $this->collectChildPids();
        if (empty($childPids)) {
            return;
        }
        
        $signalName = match ($signo) {
            \SIGTERM => 'SIGTERM',
            \SIGINT  => 'SIGINT',
            \SIGKILL => 'SIGKILL',
            default  => (string) $signo,
        };
        
        foreach ($childPids as $label => $pid) {
            $sent = @\posix_kill($pid, $signo);
            if ($sent) {
                $this->log(__('  已向 %{1} (PID: %{2}) 发送 %{3}', [$label, $pid, $signalName]));
            }
        }
    }
    
    /**
     * 收集所有子进程 PID（Worker + Dispatcher + HTTP 重定向）
     *
     * @return array<string, int> [label => pid]
     */
    protected function collectChildPids(): array
    {
        $pids = [];
        
        foreach ($this->workers as $workerId => $worker) {
            $pid = (int) ($worker['pid'] ?? 0);
            if ($pid > 0) {
                $pids["Worker #{$workerId}"] = $pid;
            }
        }
        
        if ($this->dispatcher !== null) {
            $pid = (int) ($this->dispatcher['pid'] ?? 0);
            if ($pid > 0) {
                $pids['Dispatcher'] = $pid;
            }
        }
        
        if ($this->httpRedirectWorker !== null) {
            $pid = (int) ($this->httpRedirectWorker['pid'] ?? 0);
            if ($pid > 0) {
                $pids['HTTP Redirect'] = $pid;
            }
        }
        
        return $pids;
    }
    
    /**
     * 健康检查并修复
     * 
     * 根据运行模式采用不同的检查策略：
     * - linux-direct: 所有 Worker 共用主端口，通过 PID 或进程名检查
     * - windows-dispatcher: Worker 各占独立端口，通过端口检查
     * - legacy: 传统模式，与 windows-dispatcher 类似
     */
    protected function healthCheckAndRepair(): void
    {
        if (!$this->shouldRunManagementStrategies()) {
            return;
        }
        // 调试日志：记录 workers 数组状态
        $debugLog = Env::VAR_DIR . 'log' . DS . 'master_health_debug.log';
        $workerCount = \count($this->workers);
        if ($workerCount === 0) {
            @\file_put_contents($debugLog, \date('Y-m-d H:i:s') . " WARNING: workers array is empty! workerScript={$this->workerScript}\n", FILE_APPEND);
        }
        
        // Linux 直连模式：所有 Worker 共用主端口
        if ($this->mode === self::MODE_LINUX_DIRECT) {
            $this->healthCheckLinuxDirectMode();
            return;
        }
        
        // Windows Dispatcher 模式或 Legacy 模式：Worker 各占独立端口，按状态分流检查
        foreach ($this->workers as $workerId => &$worker) {
            // 跳过正在滚动重启排水中的 Worker
            if ($this->rollingRestart && $workerId === $this->drainingWorkerId) {
                continue;
            }
            if (($worker['state'] ?? self::WORKER_STATE_STOPPED) === self::WORKER_STATE_DRAINING) {
                continue;
            }
            if ($this->isWorkerRestarting($workerId)) {
                continue;
            }
            
            $port = $worker['port'];
            $state = $worker['state'] ?? self::WORKER_STATE_STOPPED;
            $needRestart = false;
            $reason = '';
            $isPortInUse = false; // 用于重启前是否需先杀进程
            
            if ($state === self::WORKER_STATE_STARTING) {
                // STARTING 期间豁免正常检查，仅做超时（30s）检测
                $stateSince = (int)($worker['state_since'] ?? 0);
                if (\time() - $stateSince >= 30) {
                    $worker['state'] = self::WORKER_STATE_STOPPED;
                    $worker['state_since'] = \time();
                    $needRestart = true;
                    $reason = __('STARTING 超时 30s');
                }
            } elseif ($state === self::WORKER_STATE_RUNNING) {
                // 双信号验证：PID 存活则视为正常；PID 死亡再查端口
                $pid = (int)($worker['pid'] ?? 0);
                if ($pid > 0 && Processer::isRunningByPid($pid)) {
                    // PID 存活，可选做 60s HTTP 健康检查
                    $lastHealthCheck = $worker['last_health_check'] ?? 0;
                    if (\time() - $lastHealthCheck >= 60) {
                        $worker['last_health_check'] = \time();
                        $isHealthy = $this->checkWorkerHealth($port);
                        if (!$isHealthy) {
                            $worker['health_failures'] = ($worker['health_failures'] ?? 0) + 1;
                            $maxHealthFailures = (PHP_OS === 'Darwin') ? 6 : 5;
                            if ($worker['health_failures'] >= $maxHealthFailures) {
                                $needRestart = true;
                                $reason = __('HTTP 健康检查连续失败 %{1} 次', [$worker['health_failures']]);
                                $worker['health_failures'] = 0;
                            }
                        } else {
                            $worker['health_failures'] = 0;
                        }
                    }
                } else {
                    // PID 死亡
                    Processer::clearPortCache($port);
                    if (Processer::isPortInUse($port)) {
                        $newPid = Processer::getProcessIdByPort($port);
                        if ($newPid > 0 && Processer::isRunningByPid($newPid)) {
                            $worker['pid'] = $newPid;
                            $worker['state'] = self::WORKER_STATE_RUNNING;
                            $worker['state_since'] = \time();
                        } else {
                            $needRestart = true;
                            $reason = __('端口在监听但无法获取有效 PID');
                            $isPortInUse = true;
                        }
                    } else {
                        $worker['state'] = self::WORKER_STATE_STOPPED;
                        $worker['state_since'] = \time();
                        $needRestart = true;
                        $reason = __('PID 死亡且端口未监听');
                    }
                }
            } else {
                // STOPPED 或未知状态 → 触发重启
                if ($state !== self::WORKER_STATE_STOPPED) {
                    $worker['state'] = self::WORKER_STATE_STOPPED;
                    $worker['state_since'] = \time();
                }
                $needRestart = true;
                if ($reason === '') {
                    $reason = __('Worker 已停止');
                }
                Processer::clearPortCache($port);
                $isPortInUse = Processer::isPortInUse($port);
            }
            
            if ($needRestart) {
                if ($worker['restarts'] >= $this->maxRestarts) {
                    if (\time() - ($worker['last_restart'] ?? 0) < 60) {
                        continue;
                    }
                    $worker['restarts'] = 0;
                }
                
                $this->log(__('Worker #%{1} (端口: %{2}) 需要重启，原因: %{3}', [$workerId, $port, $reason]), 'warning');
                
                if ($isPortInUse) {
                    $this->log(__('强制终止僵死 Worker #%{1}', [$workerId]), 'warning');
                    $workerProcessName = '--name=' . 'weline-master-' . $this->instanceName . '-worker-' . $workerId;
                    if (!Processer::destroy($workerProcessName)) {
                        // 兜底：仅在进程管理器清理失败时按端口强制处理
                        Processer::killProcessByPort($port);
                    }
                    \usleep(500000);
                }
                
                $newPid = $this->restartWorker($workerId, $port);
                if ($newPid > 0) {
                    $worker['pid'] = $newPid;
                    $worker['restarts']++;
                    $worker['last_restart'] = \time();
                    $this->log(__('Worker #%{1} 重启成功，新 PID: %{2}', [$workerId, $newPid]), 'success');
                } else {
                    $this->log(__('Worker #%{1} 重启失败', [$workerId]), 'error');
                }
            }
        }
        
        // HTTP 重定向进程健康检查与重启（优先 PID，与 Dispatcher 一致，采纳的在役进程可被正确识别）
        if ($this->httpRedirectWorker !== null) {
            $port = $this->httpRedirectWorker['port'];
            $redirectPid = (int)($this->httpRedirectWorker['pid'] ?? 0);
            $isRunning = ($redirectPid > 0 && Processer::isRunningByPid($redirectPid))
                || Processer::isPortInUse($port);
            if (!$isRunning) {
                if ($this->httpRedirectWorker['restarts'] >= $this->maxRestarts) {
                    if (\time() - $this->httpRedirectWorker['last_restart'] < 60) {
                        return;
                    }
                    $this->httpRedirectWorker['restarts'] = 0;
                }
                $this->log(__('HTTP 重定向 (端口: %{1}) 未运行，正在重启...', [$port]), 'warning');
                $newPid = $this->startHttpRedirectWorker();
                if ($newPid > 0) {
                    $this->httpRedirectWorker['pid'] = $newPid;
                    $this->httpRedirectWorker['restarts']++;
                    $this->httpRedirectWorker['last_restart'] = \time();
                    $this->log(__('HTTP 重定向进程重启成功，PID: %{1}', [$newPid]), 'success');
                } else {
                    $this->log(__('HTTP 重定向进程重启失败'), 'error');
                }
            }
        }
        
        // Dispatcher 进程健康检查与重启（最多重试 3 次，之后放弃）
        if ($this->dispatcher !== null) {
            $port = $this->dispatcher['port'];
            $dispatcherPid = $this->dispatcher['pid'] ?? 0;
            
            // 优先使用 PID 检查（不产生 TCP 连接），避免 Dispatcher 日志中出现大量"新连接"
            // 只有当 PID 不存在或进程已退出时，才回退到端口检查
            $isRunning = false;
            if ($dispatcherPid > 0) {
                $isRunning = Processer::isRunningByPid($dispatcherPid);
            }
            
            // PID 检查失败时，回退到端口检查（作为备用方案）
            if (!$isRunning) {
                $isRunning = Processer::isPortInUse($port);
            }
            
            if (!$isRunning) {
                // 失败 3 次后放弃，不再尝试
                $maxDispatcherRetries = 3;
                if ($this->dispatcher['restarts'] >= $maxDispatcherRetries) {
                    // 仅首次记录日志，避免刷屏
                    if ($this->dispatcher['restarts'] === $maxDispatcherRetries) {
                        $this->log(__('Dispatcher 已重试 %{1} 次，放弃重启（请检查 SSL 证书或端口配置）', [$maxDispatcherRetries]), 'error');
                        $this->dispatcher['restarts']++; // 增加以避免重复日志
                    }
                    return;
                }
                $this->log(__('Dispatcher (端口: %{1}) 未运行，正在重启... (%{2}/%{3})', [$port, $this->dispatcher['restarts'] + 1, $maxDispatcherRetries]), 'warning');
                $newPid = $this->restartDispatcher();
                if ($newPid > 0) {
                    $this->dispatcher['pid'] = $newPid;
                    $this->dispatcher['restarts'] = 0; // 成功后重置计数
                    $this->dispatcher['last_restart'] = \time();
                    $this->log(__('Dispatcher 重启成功，PID: %{1}', [$newPid]), 'success');
                } else {
                    $this->dispatcher['restarts']++;
                    $this->dispatcher['last_restart'] = \time();
                    $this->log(__('Dispatcher 重启失败 (%{1}/%{2})', [$this->dispatcher['restarts'], $maxDispatcherRetries]), 'error');
                }
            } else {
                // 运行正常，重置重试计数
                if ($this->dispatcher['restarts'] > 0 && $this->dispatcher['restarts'] <= 3) {
                    $this->dispatcher['restarts'] = 0;
                }
            }
        }
    }
    
    /**
     * Linux 直连模式健康检查
     * 
     * 所有 Worker 共用主端口（SO_REUSEPORT），通过以下方式检测：
     * 1. 检查主端口是否有进程在监听
     * 2. 检查每个 Worker 的 PID 是否存活
     * 3. 如果 Worker 进程死亡，自动重启
     */
    protected function healthCheckLinuxDirectMode(): void
    {
        if (!$this->shouldRunManagementStrategies()) {
            return;
        }
        // 1. 检查主端口是否有进程在监听
        $mainPortListening = $this->mainPort > 0 && Processer::isPortInUse($this->mainPort);
        
        $debugLog = Env::VAR_DIR . 'log' . DS . 'master_health_debug.log';
        @\file_put_contents($debugLog, \date('Y-m-d H:i:s') . " [LinuxDirect] mainPort={$this->mainPort}, listening={$mainPortListening}\n", FILE_APPEND);
        
        // 2. 检查每个 Worker 的 PID 是否存活
        $runningWorkers = 0;
        foreach ($this->workers as $workerId => &$worker) {
            // 跳过正在滚动重启排水中的 Worker
            if ($this->rollingRestart && $workerId === $this->drainingWorkerId) {
                continue;
            }
            // 跳过正在重启中的 Worker
            if ($this->isWorkerRestarting($workerId)) {
                continue;
            }
            
            $pid = $worker['pid'] ?? 0;
            $isRunning = $pid > 0 && Processer::isRunningByPid($pid);
            
            if ($isRunning) {
                $runningWorkers++;
                // 重置健康检查失败计数
                $worker['health_failures'] = 0;
            } else {
                // Worker 进程不存在，需要重启
                if ($worker['restarts'] >= $this->maxRestarts) {
                    // 重启次数过多，暂时跳过
                    if (\time() - ($worker['last_restart'] ?? 0) < 60) {
                        continue;
                    }
                    // 超过 60 秒后重置计数并重试
                    $worker['restarts'] = 0;
                }
                
                $this->log(__('[LinuxDirect] Worker #%{1} (PID: %{2}) 已停止，正在重启...', [$workerId, $pid]), 'warning');
                
                $newPid = $this->restartWorkerLinuxDirect($workerId);
                if ($newPid > 0) {
                    $worker['pid'] = $newPid;
                    $worker['restarts'] = ($worker['restarts'] ?? 0) + 1;
                    $worker['last_restart'] = \time();
                    $this->log(__('[LinuxDirect] Worker #%{1} 重启成功，新 PID: %{2}', [$workerId, $newPid]), 'success');
                } else {
                    $this->log(__('[LinuxDirect] Worker #%{1} 重启失败', [$workerId]), 'error');
                }
            }
        }
        
        // 3. 如果没有任何 Worker 在运行，记录严重错误
        if ($runningWorkers === 0 && \count($this->workers) > 0) {
            $this->log(__('[LinuxDirect] 警告：所有 Worker 都已停止！'), 'error');
        }
        
        @\file_put_contents($debugLog, \date('Y-m-d H:i:s') . " [LinuxDirect] runningWorkers={$runningWorkers}/" . \count($this->workers) . "\n", FILE_APPEND);
        
        // 4. HTTP 重定向进程健康检查（优先 PID，采纳的在役进程可被正确识别）
        if ($this->httpRedirectWorker !== null) {
            $port = $this->httpRedirectWorker['port'];
            $redirectPid = (int)($this->httpRedirectWorker['pid'] ?? 0);
            $isRunning = ($redirectPid > 0 && Processer::isRunningByPid($redirectPid))
                || Processer::isPortInUse($port);
            if (!$isRunning) {
                if ($this->httpRedirectWorker['restarts'] >= $this->maxRestarts) {
                    if (\time() - ($this->httpRedirectWorker['last_restart'] ?? 0) < 60) {
                        return;
                    }
                    $this->httpRedirectWorker['restarts'] = 0;
                }
                $this->log(__('HTTP 重定向 (端口: %{1}) 未运行，正在重启...', [$port]), 'warning');
                $newPid = $this->startHttpRedirectWorker();
                if ($newPid > 0) {
                    $this->httpRedirectWorker['pid'] = $newPid;
                    $this->httpRedirectWorker['restarts']++;
                    $this->httpRedirectWorker['last_restart'] = \time();
                    $this->log(__('HTTP 重定向进程重启成功，PID: %{1}', [$newPid]), 'success');
                } else {
                    $this->log(__('HTTP 重定向进程重启失败'), 'error');
                }
            }
        }
    }
    
    /**
     * Linux 直连模式下重启单个 Worker
     * 
     * 与传统模式不同，Worker 需要使用 --reuseport 参数
     * 且所有 Worker 监听同一主端口
     * 
     * @param int $workerId Worker ID
     * @return int 新进程 PID，失败返回 0
     */
    protected function restartWorkerLinuxDirect(int $workerId): int
    {
        // ====== 互斥守卫：防止同一 Worker 被并发重启 ======
        if (!$this->acquireWorkerRestartLock($workerId)) {
            return 0;
        }
        
        try {
            return $this->doRestartWorkerLinuxDirect($workerId);
        } finally {
            $this->releaseWorkerRestartLock($workerId);
        }
    }
    
    /**
     * Linux 直连模式重启 Worker 的实际逻辑（互斥已保证）
     */
    protected function doRestartWorkerLinuxDirect(int $workerId): int
    {
        $phpBinary = PHP_BINARY;
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->mainPort;  // 使用主端口，而非各自的内部端口
        
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . "worker-{$workerId}.log";
        
        // 进程名包含实例名和 Worker ID
        $processName = 'weline-master-' . $this->instanceName . '-worker-' . $workerId;
        $processNameFlag = '--name=' . $processName;
        
        // 构建参数列表
        $argList = [
            $this->workerScript,
            $host,
            (string) $port,
            (string) $workerId,
            $this->instanceName,
        ];
        
        if ($this->sslCert && $this->sslKey) {
            $argList[] = $this->sslCert;
            $argList[] = $this->sslKey;
            // TCP 透传模式：Worker 需要延迟 SSL 握手
            $argList[] = '--defer-ssl';
        }
        $argList[] = $processNameFlag;
        $argList[] = '--reuseport';  // 关键：启用 SO_REUSEPORT
        $argList[] = '--master-pid=' . \getmypid();
        
        if ($this->frontend) {
            $argList[] = '--frontend';
        }

        // Linux/Mac: 使用 Processer 统一创建（避免手写 nohup 脚本造成 PID 偏差）
        $args = \array_merge([$phpBinary], $argList);
        
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        $pid = Processer::create($command, true, $this->frontend);
        if ($pid > 0) {
            \usleep(300000); // 300ms
            if (Processer::isRunningByPid($pid)) {
                return $pid;
            }
        }

        // 直连模式多个 Worker 共用同一端口，禁止按端口反查 PID；
        // 改为按进程名回查对应 workerId 的真实 PID，避免 PID 错配。
        $maxAttempts = 10;
        $attemptDelay = 300000; // 300ms，总计约 3s
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            \usleep($attemptDelay);

            $indexedPid = (int) Processer::getData($processNameFlag, 'pid');
            if ($indexedPid > 0 && Processer::isRunningByPid($indexedPid)) {
                return $indexedPid;
            }

            $foundPid = Processer::getPid($processNameFlag);
            if ($foundPid > 0 && Processer::isRunningByPid($foundPid)) {
                return $foundPid;
            }
        }

        return 0;
    }
    
    /**
     * 启动所有 Worker 进程（Master 统一管理）
     */
    protected function startAllWorkers(): void
    {
        $workerCount = \count($this->workers);
        if ($workerCount === 0) {
            $this->log(__('没有 Worker 需要启动'), 'warning');
            return;
        }
        
        $strategy = IS_WIN ? 'Windows PowerShell 批量启动' : ($this->mode === self::MODE_LINUX_DIRECT ? 'Linux SO_REUSEPORT' : 'Linux 独立端口');
        $this->log(__('启动 %{1} 个 Worker 进程 (策略: %{2})...', [$workerCount, $strategy]));
        
        $isDirectMode = ($this->mode === self::MODE_LINUX_DIRECT);
        
        foreach ($this->workers as $workerId => &$worker) {
            $port = $worker['port'];
            // 采纳在役 Worker：复活场景下实例文件中的 PID 仍存活则视为已运行，不重复启动（避免僵尸进程）
            $existingPid = (int)($worker['pid'] ?? 0);
            if ($existingPid > 0 && Processer::isRunningByPid($existingPid)) {
                $worker['state'] = self::WORKER_STATE_RUNNING;
                $worker['state_since'] = \time();
                $this->log(__('Worker #%{1} (端口: %{2}) 已在役 (PID: %{3})，采纳并跳过启动', [$workerId, $port, $existingPid]));
                continue;
            }
            // 直连模式下多个 Worker 通过 SO_REUSEPORT 共用同一端口，端口被占用是预期行为
            if (!$isDirectMode && Processer::isPortInUse($port)) {
                $this->log(__('Worker #%{1} 端口 %{2} 已被占用，跳过', [$workerId, $port]), 'warning');
                continue;
            }
            $pid = $isDirectMode ? $this->restartWorkerLinuxDirect($workerId) : $this->restartWorker($workerId, $port);
            if ($pid <= 0) {
                $worker['state'] = self::WORKER_STATE_STOPPED;
                $worker['state_since'] = \time();
                $this->log(__('Worker #%{1} (端口: %{2}) 启动失败，已停止后续 Worker 启动', [$workerId, $port]), 'error');
                break;
            }
            
            $worker['pid'] = $pid;
            $worker['started_at'] = \time();
            $worker['restarts'] = 0;
            $worker['state'] = self::WORKER_STATE_STARTING;
            $worker['state_since'] = \time();
            $this->log(__('Worker #%{1} (端口: %{2}) 启动成功，PID: %{3}', [$workerId, $port, $pid]), 'success');
            
            if (!$this->isProcessAliveWithRetry($pid)) {
                $worker['state'] = self::WORKER_STATE_STOPPED;
                $worker['state_since'] = \time();
                $this->log(__('Worker #%{1} (端口: %{2}) 启动后快速退出，已停止后续 Worker 启动', [$workerId, $port]), 'error');
                break;
            }
            
            if ($this->shouldStopStartingWorkersByMemory()) {
                $this->log(__('Worker #%{1} 启动后触发内存保护阈值，已停止后续 Worker 启动', [$workerId]), 'warning');
                break;
            }
        }
        
        // 更新实例文件中的 Worker PID 列表
        $this->updateInstanceWorkerPids();
    }
    
    /**
     * 进程启动后短时间内存活检测，避免启动风暴
     */
    protected function isProcessAliveWithRetry(int $pid, int $retries = 3, int $intervalMs = 120): bool
    {
        if ($pid <= 0) {
            return false;
        }
        for ($i = 0; $i < $retries; $i++) {
            if (Processer::isRunningByPid($pid)) {
                return true;
            }
            \usleep($intervalMs * 1000);
        }
        return false;
    }
    
    /**
     * 启动保护：达到内存阈值后停止继续拉起 Worker
     */
    protected function shouldStopStartingWorkersByMemory(): bool
    {
        $memoryLimit = (string)\ini_get('memory_limit');
        $limitBytes = $this->parseMemoryToBytes($memoryLimit);
        if ($limitBytes <= 0) {
            return false;
        }
        // 使用 90% 作为保护阈值，避免在临界点继续扩容导致抖动
        $thresholdBytes = (int)\floor($limitBytes * 0.9);
        return \memory_get_usage(true) >= $thresholdBytes;
    }
    
    /**
     * 解析 PHP memory_limit（如 512M/1G/-1）为字节
     */
    protected function parseMemoryToBytes(string $value): int
    {
        $raw = \trim($value);
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        if (\is_numeric($raw)) {
            return (int)$raw;
        }
        $unit = \strtolower($raw[\strlen($raw) - 1]);
        $num = (float)\substr($raw, 0, -1);
        return match ($unit) {
            'g' => (int)($num * 1024 * 1024 * 1024),
            'm' => (int)($num * 1024 * 1024),
            'k' => (int)($num * 1024),
            default => (int)$raw,
        };
    }
    
    /**
     * 更新实例文件中的 Worker PID 列表（原子更新，带文件锁）
     */
    protected function updateInstanceWorkerPids(): void
    {
        $instanceFile = $this->getInstanceFile();
        if (!\is_file($instanceFile)) {
            return;
        }
        
        $workerPids = [];
        foreach ($this->workers as $workerId => $worker) {
            if (!empty($worker['pid'])) {
                $workerPids[$workerId] = $worker['pid'];
            }
        }
        
        ServerInstanceService::atomicUpdateJson($instanceFile, function (array $data) use ($workerPids): array {
            $data['worker_pids'] = $workerPids;
            return $data;
        });
    }
    
    /**
     * 启动 Dispatcher 进程（Master 统一管理）
     * 
     * @return int PID，失败返回 0
     */
    protected function startDispatcherProcess(): int
    {
        if ($this->dispatcher === null || !\is_file($this->dispatcherScript)) {
            $this->log(__('  Dispatcher 脚本不存在或未配置: %{1}', [$this->dispatcherScript ?? '(null)']), 'warning');
            return 0;
        }
        
        $port = $this->dispatcher['port'];
        $workerPorts = $this->dispatcher['worker_ports'] ?? [];
        $this->log(__('  Dispatcher 配置:'));
        $this->log(__('    脚本: %{1}', [$this->dispatcherScript]));
        $this->log(__('    监听端口: %{1}', [$port]));
        $this->log(__('    后端 Worker 端口: [%{1}]', [\implode(', ', $workerPorts)]));
        $this->log(__('    Worker 数量: %{1}', [\count($workerPorts)]));
        
        // 检查端口是否已被占用
        Processer::clearPortCache($port);
        if (Processer::isPortInUse($port)) {
            $this->log(__('  Dispatcher 端口 %{1} 已被占用，尝试停止旧进程...', [$port]), 'warning');
            // 如果端口被占用，尝试通过端口找到 PID 并杀死
            $oldPid = Processer::getProcessIdByPort($port);
            if ($oldPid > 0) {
                $this->log(__('发现旧进程 PID: %{1}，正在强制停止...', [$oldPid]), 'warning');
                Processer::killByPid($oldPid);
                \usleep(500000);
                Processer::clearPortCache($port);
                if (Processer::isPortInUse($port)) {
                    $this->log(__('无法释放 Dispatcher 端口 %{1}，启动失败', [$port]), 'error');
                    // 端口无法释放，直接退出，避免重复尝试
                    exit(1);
                }
            } else {
                $this->log(__('Dispatcher 端口 %{1} 被非框架进程占用', [$port]), 'error');
                // 端口被占用且无法自动处理，直接退出
                exit(1);
            }
        }
        
        // 使用现有的重启方法启动
        $pid = $this->restartDispatcher();
        
        // 启动后清除端口缓存，避免健康检查误判
        // （首次检查在绑定前执行，缓存了 inUse=false，导致健康检查误判并重启）
        if ($pid > 0) {
            Processer::clearPortCache($port);
        }
        
        return $pid;
    }
    
    /**
     * 重启 Dispatcher 进程
     */
    protected function restartDispatcher(): int
    {
        if ($this->dispatcher === null || !\is_file($this->dispatcherScript)) {
            return 0;
        }
        
        $phpBinary = PHP_BINARY;
        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->dispatcher['port'];
        $workerPorts = $this->dispatcher['worker_ports'] ?? [];
        $workerCount = \count($workerPorts);
        $workerBasePort = !empty($workerPorts) ? \min($workerPorts) : ($port + 10000);
        
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        // 统一进程名
        $processName = 'weline-dispatcher-' . $this->instanceName;
        
        // 确保路径使用反斜杠（Windows）
        $dispatcherScript = \str_replace('/', '\\', $this->dispatcherScript);
        $phpBinaryEsc = \str_replace('/', '\\', $phpBinary);
        
        // 构建参数数组（TCP 透传模式：不需要 SSL 证书）
        // 参数格式: <host> <port> <worker_base_port> <worker_count> <instance_name>
        $masterPid = \getmypid();
        $argList = [
            $dispatcherScript,
            $host,
            (string) $port,
            (string) $workerBasePort,
            (string) $workerCount,
            $this->instanceName,
            '--name=' . $processName,
        ];
        if ($this->controlPort > 0) {
            $argList[] = '--control-port=' . $this->controlPort;
        }
        $argList[] = '--master-pid=' . $masterPid;
        // 前台模式标记
        if ($this->frontend) {
            $argList[] = '--frontend';
        }
        
        $args = [
            $phpBinary,
            $this->dispatcherScript,
            $host,
            (string) $port,
            (string) $workerBasePort,
            (string) $workerCount,
            $this->instanceName,
            '--name=' . $processName,
        ];
        if ($this->controlPort > 0) {
            $args[] = '--control-port=' . $this->controlPort;
        }
        $args[] = '--master-pid=' . $masterPid;
        if ($this->frontend) {
            $args[] = '--frontend';
        }
        
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        $pid = Processer::create($command, false, $this->frontend);
        if ($pid > 0) {
            return $pid;
        }
        \usleep(500000);
        return Processer::getProcessIdByPort($port) ?: 0;
    }
    
    /**
     * 重启单个 Worker
     */
    protected function restartWorker(int $workerId, int $port): int
    {
        // ====== 互斥守卫：防止同一 Worker 被并发重启 ======
        if (!$this->acquireWorkerRestartLock($workerId)) {
            return 0;
        }
        
        try {
            return $this->doRestartWorker($workerId, $port);
        } finally {
            $this->releaseWorkerRestartLock($workerId);
        }
    }
    
    /**
     * 重启单个 Worker 的实际逻辑（由 restartWorker 调用，互斥已保证）
     */
    protected function doRestartWorker(int $workerId, int $port): int
    {
        // 启动前双重保护：端口已在监听且存在活跃 PID 则不再启动，避免重复 Worker
        Processer::clearPortCache($port);
        if (Processer::isPortInUse($port)) {
            $existingPid = Processer::getProcessIdByPort($port);
            if ($existingPid > 0 && Processer::isRunningByPid($existingPid)) {
                $this->log(__('Worker #%{1} 端口 %{2} 已有活跃进程 (PID: %{3})，跳过启动', [$workerId, $port, $existingPid]));
                if (isset($this->workers[$workerId])) {
                    $this->workers[$workerId]['pid'] = $existingPid;
                    $this->workers[$workerId]['state'] = self::WORKER_STATE_RUNNING;
                    $this->workers[$workerId]['state_since'] = \time();
                }
                return $existingPid;
            }
        }
        
        $phpBinary = PHP_BINARY;
        $host = $this->config['host'] ?? '127.0.0.1';
        
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . "worker-{$port}.log";
        
        // 进程名包含实例名和 Worker ID，便于多 Master 架构下识别和管理
        // 格式：weline-master-{instance_name}-worker-{worker_id}
        $processName = 'weline-master-' . $this->instanceName . '-worker-' . $workerId;
        
        // 构建参数列表（不包含 PHP 二进制路径，它作为 -FilePath）
        $argList = [
            $this->workerScript,
            $host,
            (string) $port,
            (string) $workerId,
            $this->instanceName,
        ];
        
        if ($this->sslCert && $this->sslKey) {
            $argList[] = $this->sslCert;
            $argList[] = $this->sslKey;
            // TCP 透传模式：Worker 需要延迟 SSL 握手
            $argList[] = '--defer-ssl';
        }
        $argList[] = '--name=' . $processName;
        if ($this->controlPort > 0) {
            $argList[] = '--control-port=' . $this->controlPort;
        }
        $argList[] = '--master-pid=' . \getmypid();
        // 前台模式标记（Worker 可据此决定输出方式）
        if ($this->frontend) {
            $argList[] = '--frontend';
        }
        
        // 状态机：进入 STARTING，健康检查在此期间豁免
        if (isset($this->workers[$workerId])) {
            $this->workers[$workerId]['state'] = self::WORKER_STATE_STARTING;
            $this->workers[$workerId]['state_since'] = \time();
        }
        
        // 启动进程：统一通过 Processer::create（由驱动封装 OS 差分）
        $args = \array_merge([$phpBinary], $argList);
        
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        $pid = Processer::create($command, true, $this->frontend);
        if ($pid > 0) {
            \usleep(200000);
            if (Processer::isRunningByPid($pid)) {
                return $pid;
            }
        }
        
        // Workerman 模式：多次尝试检测进程启动（给进程足够的启动时间）
        // Worker 启动需要加载框架，可能需要 1-3 秒
        $maxAttempts = 10;  // 最多尝试 10 次
        $attemptDelay = 500000; // 每次等待 500ms，共 5 秒
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            \usleep($attemptDelay);
            
            // 方法1：通过端口检测
            if (Processer::isPortInUse($port)) {
                $pid = Processer::getProcessIdByPort($port);
                if ($pid > 0) {
                    // 记录调试日志
                    $debugLog = Env::VAR_DIR . 'log' . DS . 'master_restart_debug.log';
                    @\file_put_contents($debugLog, \date('Y-m-d H:i:s') . " Worker #{$workerId} started successfully on attempt {$attempt}, PID: {$pid}\n", FILE_APPEND);
                    return $pid;
                }
            }
        }
        
        // 启动失败，记录调试日志
        $debugLog = Env::VAR_DIR . 'log' . DS . 'master_restart_debug.log';
        @\file_put_contents($debugLog, \date('Y-m-d H:i:s') . " Worker #{$workerId} failed to start after {$maxAttempts} attempts\n", FILE_APPEND);
        
        return 0;
    }
    
    /**
     * HTTP 重定向进程名标识（用于识别框架进程，避免乱杀；多 Master 时可共用此进程）
     */
    public const HTTP_REDIRECT_PROCESS_NAME = 'weline-http-redirect';
    
    /**
     * 启动 HTTP 重定向进程（监听 HTTP 端口并 301 重定向到 HTTPS）
     * 
     * 多 Master 共用：如果 80 端口已被框架的 HTTP 重定向进程占用，则跳过不再启动（共用已有进程）。
     * 该进程不加载框架，仅做简单的 301 重定向，负担极低。
     */
    protected function startHttpRedirectWorker(): int
    {
        if ($this->httpRedirectWorker === null || !\is_file($this->httpRedirectScript)) {
            return 0;
        }
        
        $httpPort = $this->httpRedirectWorker['port'];
        
        // 多 Master 共用：如果 80 端口已被框架的 HTTP 重定向进程占用，则跳过
        if (Processer::isPortInUse($httpPort)) {
            if (Processer::isPortUsedByWeline($httpPort)) {
                $existingPid = Processer::getProcessIdByPort($httpPort);
                $this->log(__('HTTP 重定向端口 %{1} 已被框架进程占用 (PID: %{2})，共用该进程', [$httpPort, $existingPid]));
                return $existingPid;
            } else {
                $pid = Processer::getProcessIdByPort($httpPort);
                $processInfo = $pid > 0 ? Processer::getProcessInfo($pid) : [];
                $processName = !empty($processInfo['name']) ? $processInfo['name'] : ($pid > 0 ? __('PID: %{1}', [$pid]) : __('未知'));
                $errorMessage = __('HTTP 重定向端口 %{1} 已被其他进程占用 (%{2})。请停止占用该端口的进程，或使用 --http-redirect-port 参数指定其他端口。', 
                    [$httpPort, $processName]);
                throw new \RuntimeException($errorMessage);
            }
        }
        
        $phpBinary = PHP_BINARY;
        $host = $this->config['host'] ?? '127.0.0.1';
        $httpsPort = (int)($this->config['port'] ?? 443);
        
        $processName = self::HTTP_REDIRECT_PROCESS_NAME;
        $argList = [
            $this->httpRedirectScript,
            $host,
            (string) $httpPort,
            (string) $httpsPort,
            $this->instanceName,
            '--name=' . $processName,
        ];
        if ($this->controlPort > 0) {
            $argList[] = '--control-port=' . $this->controlPort;
        }
        $argList[] = '--master-pid=' . \getmypid();
        if ($this->frontend) {
            $argList[] = '--frontend';
        }
        
        $args = \array_merge([$phpBinary], $argList);
        
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        
        $pid = Processer::create($command, false, $this->frontend);
        
        // 如果 Processer::create 返回 0（非阻塞模式可能返回 0），尝试通过端口获取 PID
        if ($pid === 0) {
            \usleep(500000); // 等待进程启动
            $pid = Processer::getProcessIdByPort($httpPort) ?: 0;
            
            // 如果通过端口获取到 PID，确保注册到进程管理器
            if ($pid > 0) {
                $pname = '--name=' . $processName;
                Processer::setPid($pname, $pid);
                // 注册监听端口（启用快速端口→PID 查找）
                Processer::setProcessPorts($pname, [$httpPort]);
            }
        }
        
        return $pid;
    }
    
    /**
     * 等待所有 Worker 启动完成
     * 
     * 在首次健康检查前，等待所有 Worker 进程完成初始化并绑定端口。
     * 使用智能等待策略：定期检查端口状态，最多等待指定时间。
     */
    protected function waitForWorkersReady(): void
    {
        $maxWaitSeconds = IS_WIN ? 15 : 10; // Windows 需要更长启动时间
        $checkIntervalMs = 500; // 每 500ms 检查一次
        $maxChecks = ($maxWaitSeconds * 1000) / $checkIntervalMs;
        $totalWorkers = \count($this->workers);
        
        $this->log(__('  等待模式: %{1}', [$this->mode === self::MODE_LINUX_DIRECT ? '主端口检测' : '逐端口检测']));
        $this->log(__('  最大等待: %{1} 秒，检查间隔: %{2}ms，共 %{3} 次检查', [$maxWaitSeconds, $checkIntervalMs, $maxChecks]));
        
        $startTime = \microtime(true);
        $allReady = false;
        
        for ($i = 0; $i < $maxChecks; $i++) {
            $readyCount = 0;
            
            // Linux 直连模式：检查主端口
            if ($this->mode === self::MODE_LINUX_DIRECT && $this->mainPort > 0) {
                if (Processer::isPortInUse($this->mainPort)) {
                    $allReady = true;
                    break;
                }
            } else {
                // Windows/Legacy 模式：检查每个 Worker 的端口
                $readyPorts = [];
                $pendingPorts = [];
                foreach ($this->workers as $wid => $worker) {
                    $port = $worker['port'] ?? 0;
                    if ($port > 0 && Processer::isPortInUse($port)) {
                        $readyCount++;
                        $readyPorts[] = $port;
                    } else {
                        $pendingPorts[] = $port;
                    }
                }
                
                if ($readyCount === $totalWorkers) {
                    $allReady = true;
                    break;
                }
                
                // 每 2 秒输出一次等待进度
                if ($i > 0 && $i % 4 === 0) {
                    $elapsed = \round(\microtime(true) - $startTime, 1);
                    $this->log(__('  [%{1}s] 就绪: %{2}/%{3}，等待端口: [%{4}]', [$elapsed, $readyCount, $totalWorkers, \implode(', ', $pendingPorts)]));
                }
            }
            
            // 未全部就绪，继续等待
            \usleep($checkIntervalMs * 1000);
        }
        
        $elapsedTime = \round(\microtime(true) - $startTime, 2);
        
        if ($allReady) {
            $this->log(__('  所有 Worker 已就绪 (%{1}/%{2})，耗时: %{3} 秒', [$totalWorkers, $totalWorkers, $elapsedTime]), 'success');
        } else {
            // 输出每个 Worker 的最终状态（直连模式用 PID 判断，避免共用端口误判）
            foreach ($this->workers as $wid => $worker) {
                $port = $worker['port'] ?? 0;
                $pid = (int)($worker['pid'] ?? 0);
                $isUp = ($pid > 0 && Processer::isRunningByPid($pid)) || ($port > 0 && Processer::isPortInUse($port));
                $this->log(__('    Worker #%{1} (端口 %{2}, PID %{3}): %{4}', [$wid, $port, $pid, $isUp ? '就绪' : '未就绪']), $isUp ? 'success' : 'warning');
            }
            $this->log(__('  等待超时 (%{1} 秒)，%{2}/%{3} 个 Worker 就绪', [$elapsedTime, $readyCount ?? 0, $totalWorkers]), 'warning');
            // 即使超时也继续运行，健康检查会处理未启动的 Worker
        }
        
        // 额外等待 2 秒，确保进程完全稳定
        $this->log(__('  额外稳定化等待 2 秒...'));
        \sleep(2);
        
        // 状态机：将仍为 STARTING 且已就绪的 Worker 标记为 RUNNING
        // 直连模式用 PID 判断（共用端口无法区分单个 Worker），其他模式用端口判断
        $isDirectMode = ($this->mode === self::MODE_LINUX_DIRECT);
        foreach ($this->workers as $wid => &$w) {
            if (($w['state'] ?? self::WORKER_STATE_STOPPED) === self::WORKER_STATE_STARTING) {
                $pid = (int)($w['pid'] ?? 0);
                $port = $w['port'] ?? 0;
                $isReady = $isDirectMode
                    ? ($pid > 0 && Processer::isRunningByPid($pid))
                    : ($port > 0 && Processer::isPortInUse($port));
                if ($isReady) {
                    $w['state'] = self::WORKER_STATE_RUNNING;
                    $w['state_since'] = \time();
                }
            }
        }
        unset($w);
    }
    
    /**
     * 检查 Worker HTTP 健康状态
     * 
     * 通过请求 /_wls/health 接口检测 Worker 是否能正常响应
     * 参照 Workerman：健康检查应该宽松，避免误判重启
     * 
     * @param int $port Worker 端口
     * @return bool true=健康，false=无响应或超时
     */
    protected function checkWorkerHealth(int $port): bool
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        // 健康检查应使用可连接地址，监听地址为 0.0.0.0/:: 时使用回环
        $healthHost = ($host === '0.0.0.0' || $host === '::' || $host === '') ? '127.0.0.1' : $host;
        
        // SSL 模式下使用 HTTPS，否则使用 HTTP
        $sslEnabled = !empty($this->sslCert) && !empty($this->sslKey);
        $scheme = $sslEnabled ? 'https' : 'http';
        $url = "{$scheme}://{$healthHost}:{$port}/_wls/health";
        
        // 使用 stream_context 设置超时（Workerman 模式：宽松超时，避免误判）
        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'timeout' => (PHP_OS === 'Darwin') ? 8 : 5, // macOS 放宽超时，减少误判重启
                'ignore_errors' => true,
            ],
        ];
        
        // SSL 模式下忽略证书验证（自签名证书）
        if ($sslEnabled) {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];
        }
        
        $context = \stream_context_create($contextOptions);
        
        $response = @\file_get_contents($url, false, $context);
        
        if ($response === false) {
            return false;
        }
        
        // 健康检查响应：接受 "OK"（极简模式）或 JSON 格式
        $trimmed = \trim($response);
        
        // 极简模式：返回 "OK"
        if ($trimmed === 'OK') {
            return true;
        }
        
        // JSON 模式：检查 status 字段
        $data = @\json_decode($response, true);
        if (\is_array($data) && isset($data['status']) && $data['status'] === 'healthy') {
            return true;
        }
        
        // 只要有响应就认为健康（极端宽松模式，避免误判）
        // Worker 能响应说明进程还在运行
        return !empty($trimmed);
    }
    
    /**
     * 通知所有 Worker 重载（通过 IPC 控制通道）
     * 
     * @param string $type 重载类型：code（代码重载/重启）或 cache（仅清缓存）
     */
    protected function notifyWorkersReload(string $type = 'code'): void
    {
        $this->initiateRollingRestart($type);
    }
    
    /**
     * 发起重载：缓存重载原地清理，代码重载滚动重启，强制重载批量杀死后重启
     */
    protected function initiateRollingRestart(string $type = 'code'): void
    {
        if (!$this->controlServer) {
            $this->log(__('控制服务器未启动，无法发送重载命令'), 'warning');
            return;
        }
        
        // 缓存重载：广播 cache_clear，原地清理，不重启
        if ($type === ControlMessage::RELOAD_TYPE_CACHE) {
            $this->controlServer->broadcast(ControlMessage::cacheClear(), ControlMessage::ROLE_WORKER);
            $this->controlServer->broadcast(ControlMessage::cacheClear(), ControlMessage::ROLE_MAINTENANCE);
            $this->log(__('已广播缓存清理命令给所有 Worker'));
            return;
        }
        
        // 强制重载：批量杀死所有 Worker 后重新启动（不排水）
        if ($type === ControlMessage::RELOAD_TYPE_FORCE) {
            $this->log(__('发起强制重载：批量杀死所有 Worker 后重新启动...'));
            $this->batchForceRestartWorkers();
            return;
        }
        
        // 代码重载：滚动重启
        if ($this->rollingRestart) {
            // 检查滚动重启是否超时卡住（每个 Worker 最多等待 60 秒）
            $maxTimeout = 60 * \max(1, \count($this->workers));
            if ($this->rollingRestartStartTime > 0 && (\time() - $this->rollingRestartStartTime) > $maxTimeout) {
                $this->log(__('滚动重启超时 (%{1}s)，强制重置状态', [$maxTimeout]), 'warning');
                $this->resetRollingRestartState();
            } else {
                $this->log(__('滚动重启已在进行中，忽略重复请求'), 'warning');
                return;
            }
        }
        
        $this->rollingRestart = true;
        $this->rollingRestartStartTime = \time();
        $this->rollingRestartQueue = \array_keys($this->workers);
        $this->log(__('发起滚动重启，Worker 队列: [%{1}]', [\implode(', ', $this->rollingRestartQueue)]));
        
        // 检查是否需要维护 Worker
        $totalWorkers = \count($this->workers);
        if ($totalWorkers <= 1) {
            // 只有 1 个 Worker，先启动维护 Worker
            $this->startMaintenanceWorkers();
        }
        
        // 开始排水第一个 Worker
        $this->drainNextWorker();
    }
    
    /**
     * 批量强制重启所有 Worker（不排水，直接杀死后重新启动）
     * 
     * 适用于 -f 强制模式：不等待请求处理完成，直接杀死所有 Worker，然后批量启动新的
     */
    protected function batchForceRestartWorkers(): void
    {
        $startTime = \time();
        $workerIds = \array_keys($this->workers);
        $workerCount = \count($workerIds);
        
        if ($workerCount === 0) {
            $this->log(__('没有 Worker 需要重启'));
            return;
        }
        
        // ⭐ 设置强制重启标志，禁止 handleWorkerDisconnect 触发复活逻辑
        $this->forceRestarting = true;
        
        $this->log(__('批量强制重启 %{1} 个 Worker...', [$workerCount]));
        
        // 通知 Dispatcher 暂停路由（如果有）
        if ($this->controlServer && $this->mode !== self::MODE_LINUX_DIRECT) {
            $allPorts = [];
            foreach ($this->workers as $worker) {
                if (!empty($worker['port'])) {
                    $allPorts[] = $worker['port'];
                }
            }
            if (!empty($allPorts)) {
                $this->controlServer->sendToRole(ControlMessage::ROLE_DISPATCHER, ControlMessage::drain($allPorts));
                $this->log(__('已通知 Dispatcher 暂停路由到所有 Worker 端口'));
            }
        }
        
        // ========== 阶段 1：批量发送 shutdown 信号给所有 Worker ==========
        $pidsToKill = [];
        $isWin = \defined('IS_WIN') && IS_WIN;
        
        foreach ($this->workers as $workerId => $worker) {
            $pid = (int) ($worker['pid'] ?? 0);
            $port = $worker['port'] ?? 0;
            
            // 通过 IPC 发送 shutdown
            if ($this->controlServer) {
                $this->controlServer->sendToWorker($workerId, ControlMessage::shutdown('force_reload'));
            }
            
            if ($pid > 0) {
                $pidsToKill[$pid] = ['workerId' => $workerId, 'port' => $port];
                
                // 同时发送系统信号
                if (!$isWin && \function_exists('posix_kill')) {
                    @\posix_kill($pid, \SIGTERM);
                }
            }
        }
        
        $this->log(__('已向 %{1} 个 Worker 发送 shutdown 信号', [\count($pidsToKill)]));
        
        // ========== 阶段 2：等待进程退出（短超时） ==========
        $waitTimeout = 3; // 强制模式下只等 3 秒
        $waitStart = \time();
        
        while (!empty($pidsToKill) && (\time() - $waitStart) < $waitTimeout) {
            \usleep(200000); // 200ms
            
            foreach ($pidsToKill as $pid => $info) {
                if (!Processer::isRunningByPid($pid)) {
                    $this->log(__('  Worker #%{1} 已退出 (PID: %{2})', [$info['workerId'], $pid]));
                    unset($pidsToKill[$pid]);
                }
            }
        }
        
        // ========== 阶段 3：强制杀死未退出的进程 ==========
        if (!empty($pidsToKill)) {
            $this->log(__('部分 Worker 未响应，批量强制杀死...'), 'warning');
            
            foreach ($pidsToKill as $pid => $info) {
                if (!$isWin && \function_exists('posix_kill')) {
                    @\posix_kill($pid, \SIGKILL);
                }
                Processer::killProcessTreeByPid($pid, true);
                $this->log(__('  已强制杀死 Worker #%{1} (PID: %{2})', [$info['workerId'], $pid]));
            }
            
            // 等待强制杀死生效
            \usleep(500000); // 500ms
        }
        
        // ========== 阶段 4：批量启动新 Worker ==========
        $this->log(__('批量启动 %{1} 个新 Worker...', [$workerCount]));
        
        foreach ($workerIds as $workerId) {
            $oldWorker = $this->workers[$workerId] ?? [];
            $port = $oldWorker['port'] ?? 0;
            
            // 重置 Worker 状态
            $this->workers[$workerId] = [
                'pid' => 0,
                'port' => $port,
                'state' => self::WORKER_STATE_STOPPED,
                'restarts' => ($oldWorker['restarts'] ?? 0) + 1,
                'last_restart' => \time(),
            ];
            
            // 启动新 Worker
            $this->startWorker($workerId);
        }
        
        // ========== 阶段 5：通知 Dispatcher 恢复路由 ==========
        if ($this->controlServer && $this->mode !== self::MODE_LINUX_DIRECT) {
            $allPorts = [];
            foreach ($this->workers as $worker) {
                if (!empty($worker['port'])) {
                    $allPorts[] = $worker['port'];
                }
            }
            if (!empty($allPorts)) {
                // 延迟一点让 Worker 启动
                \usleep(500000);
                $this->controlServer->sendToRole(ControlMessage::ROLE_DISPATCHER, ControlMessage::undrain($allPorts));
                $this->log(__('已通知 Dispatcher 恢复路由到所有 Worker 端口'));
            }
        }
        
        // ⭐ 重置强制重启标志，恢复正常的管理策略
        $this->forceRestarting = false;
        
        $elapsed = \time() - $startTime;
        $this->log(__('批量强制重启完成，%{1} 个 Worker 已更新 (耗时: %{2}s)', [$workerCount, $elapsed]), 'success');
    }
    
    /**
     * 排水下一个待重启的 Worker
     */
    protected function drainNextWorker(): void
    {
        if (empty($this->rollingRestartQueue)) {
            // 所有 Worker 重启完成
            $elapsed = $this->rollingRestartStartTime > 0 ? (\time() - $this->rollingRestartStartTime) : 0;
            $this->resetRollingRestartState();
            $this->log(__('滚动重启完成，所有 Worker 已更新 (耗时: %{1}s)', [$elapsed]));
            
            // 关闭维护 Worker
            $this->stopMaintenanceWorkers();
            return;
        }
        
        $workerId = \array_shift($this->rollingRestartQueue);
        $this->drainingWorkerId = $workerId;
        $this->drainingStartTime = \time();
        
        $worker = $this->workers[$workerId] ?? null;
        if (!$worker) {
            // 跳过无效 Worker，继续下一个
            $this->drainNextWorker();
            return;
        }
        
        $port = $worker['port'];
        
        // 仅 Dispatcher/Legacy 模式需要通知 Dispatcher 停止路由；
        // 直连模式无 Dispatcher，跳过该步骤。
        if ($this->controlServer && $this->mode !== self::MODE_LINUX_DIRECT) {
            $this->controlServer->sendToRole(ControlMessage::ROLE_DISPATCHER, ControlMessage::drain([$port]));
        }
        
        // 通知 Worker 重载（Worker 收到后关闭监听 socket，处理完剩余请求后发送 draining_complete）
        $sendSuccess = false;
        if ($this->controlServer) {
            $sendSuccess = $this->controlServer->sendToWorker($workerId, ControlMessage::reload(ControlMessage::RELOAD_TYPE_CODE));
        }
        
        if (!$sendSuccess) {
            $this->log(__('Worker #%{1} (端口: %{2}) 无法发送 reload 消息（可能未注册），尝试直接杀死', [$workerId, $port]), 'warning');
            $pid = $worker['pid'] ?? 0;
            if ($pid > 0) {
                Processer::killProcessTreeByPid($pid, true);
            }
        }
        
        $this->log(__('Worker #%{1} (端口: %{2}) 开始排水', [$workerId, $port]));
    }
    
    /**
     * 重置滚动重启状态
     */
    protected function resetRollingRestartState(): void
    {
        $this->rollingRestart = false;
        $this->rollingRestartStartTime = 0;
        $this->rollingRestartQueue = [];
        $this->drainingWorkerId = 0;
        $this->drainingStartTime = 0;
    }
    
    /**
     * 检查单个 Worker 排水超时（在主循环中定期调用）
     */
    protected function checkDrainingTimeout(int $now): void
    {
        if (!$this->rollingRestart || $this->drainingWorkerId === 0 || $this->drainingStartTime === 0) {
            return;
        }
        
        $elapsed = $now - $this->drainingStartTime;
        $drainingTimeout = 60;
        
        if ($elapsed > $drainingTimeout) {
            $workerId = $this->drainingWorkerId;
            $this->log(__('Worker #%{1} 排水超时 (%{2}s > %{3}s)，强制杀死并继续下一个', [$workerId, $elapsed, $drainingTimeout]), 'warning');
            
            $worker = $this->workers[$workerId] ?? null;
            if ($worker && !empty($worker['pid'])) {
                Processer::killProcessTreeByPid($worker['pid'], true);
            }
            
            $this->drainingWorkerId = 0;
            $this->drainingStartTime = 0;
            
            $this->drainNextWorker();
        }
    }
    
    /**
     * 处理 Worker draining_complete：Worker 处理完所有请求，准备退出
     */
    protected function handleDrainingComplete(int $workerId, int $port): void
    {
        $this->log(__('Worker #%{1} (端口: %{2}) 排水完成，等待新 Worker 启动', [$workerId, $port]));
        // Worker 会自行退出，Master 在 onDisconnect 中检测到后启动新 Worker
    }
    
    /**
     * 处理 Worker 断开（TCP 连接断开 = Worker 退出/死亡）
     */
    protected function handleWorkerDisconnect(int $workerId, int $port): void
    {
        if (!$this->shouldRunManagementStrategies()) {
            return;
        }
        if ($this->rollingRestart && $workerId === $this->drainingWorkerId) {
            // 滚动重启中：Worker 排水后退出，启动新 Worker
            $this->log(__('Worker #%{1} 已退出，正在启动新 Worker...', [$workerId]));
            $this->restartWorkerAndContinue($workerId);
        } else {
            // 非滚动重启：Worker 意外退出，立即尝试重启
            // 通过 restartWorker() 的互斥守卫保证不会与健康检查并发重启
            $this->log(__('Worker #%{1} (端口: %{2}) 意外断开，尝试重启...', [$workerId, $port]), 'warning');
            $newPid = ($this->mode === self::MODE_LINUX_DIRECT)
                ? $this->restartWorkerLinuxDirect($workerId)
                : $this->restartWorker($workerId, $port);
            if ($newPid > 0) {
                $worker = &$this->workers[$workerId];
                $worker['pid'] = $newPid;
                $worker['restarts'] = ($worker['restarts'] ?? 0) + 1;
                $worker['last_restart'] = \time();
                $this->log(__('Worker #%{1} 重启成功，新 PID: %{2}', [$workerId, $newPid]), 'success');
            } else {
                $this->log(__('Worker #%{1} 重启失败（将由健康检查重试）', [$workerId]), 'warning');
            }
        }
    }
    
    /**
     * 滚动重启中：启动新 Worker，等待 ready，然后继续下一个
     */
    protected function restartWorkerAndContinue(int $workerId): void
    {
        if (!$this->shouldRunManagementStrategies()) {
            return;
        }
        $worker = $this->workers[$workerId] ?? null;
        if (!$worker) {
            return;
        }
        
        $port = $worker['port'];
        $oldPid = (int)($worker['pid'] ?? 0);
        
        // 检查端口是否已被其他进程占用（可能是健康检查并发重启的 Worker）
        Processer::clearPortCache($port);
        if (Processer::isPortInUse($port)) {
            $existingPid = Processer::getProcessIdByPort($port);
            if ($existingPid > 0 && Processer::isRunningByPid($existingPid) && $existingPid !== $oldPid) {
                // 端口被另一个存活进程占用（可能是健康检查重启的新 Worker）
                // 这个新 Worker 应该已经注册过了，直接采纳并继续滚动重启
                $this->log(__('Worker #%{1} 端口已被 PID %{2} 占用（可能是并发重启），采纳并继续', [$workerId, $existingPid]));
                $this->workers[$workerId]['pid'] = $existingPid;
                $this->workers[$workerId]['state'] = self::WORKER_STATE_RUNNING;
                $this->workers[$workerId]['state_since'] = \time();
                
                // 直接继续下一个 Worker，不等待 ready（因为该 Worker 可能早就 ready 过了）
                $this->drainingWorkerId = 0;
                $this->drainNextWorker();
                return;
            }
        }
        
        // 启动新 Worker
        $newPid = ($this->mode === self::MODE_LINUX_DIRECT)
            ? $this->restartWorkerLinuxDirect($workerId)
            : $this->restartWorker($workerId, $port);
        
        if ($newPid > 0) {
            // 检查返回的 PID 是否真的是新启动的（不是采纳已有的）
            $wasAdopted = ($newPid === $oldPid) || (Processer::isPortInUse($port) && Processer::getProcessIdByPort($port) === $newPid);
            
            if ($wasAdopted && $this->workers[$workerId]['state'] === self::WORKER_STATE_RUNNING) {
                // 采纳了已有进程，它可能已经 ready 过了，直接继续
                $this->log(__('Worker #%{1} 采纳已有进程 (PID: %{2})，继续滚动重启', [$workerId, $newPid]));
                $this->drainingWorkerId = 0;
                $this->drainNextWorker();
            } else {
                $this->log(__('新 Worker #%{1} 已启动 (PID: %{2})，等待 ready...', [$workerId, $newPid]));
            }
        } else {
            $this->log(__('新 Worker #%{1} 启动失败，将由健康检查重试', [$workerId]), 'error');
            // 启动失败也要继续滚动重启，否则会卡死
            $this->drainingWorkerId = 0;
            $this->drainNextWorker();
        }
    }
    
    /**
     * 处理 Worker ready：Worker 初始化完成可接收流量
     */
    protected function handleWorkerReady(int $workerId, int $port): void
    {
        $this->log(__('Worker #%{1} (端口: %{2}) 就绪', [$workerId, $port]));
        
        // 状态机：READY 表示 Worker 可接收流量，转为 RUNNING
        if (isset($this->workers[$workerId])) {
            $this->workers[$workerId]['state'] = self::WORKER_STATE_RUNNING;
            $this->workers[$workerId]['state_since'] = \time();
        }
        
        // 仅在滚动重启中发送 undrain（初始启动时 Worker 从未被 drain，无需 undrain）
        if ($this->rollingRestart && $this->controlServer) {
            $this->controlServer->sendToRole(ControlMessage::ROLE_DISPATCHER, ControlMessage::undrain([$port]));
        }
        
        // 如果在滚动重启中，继续下一个
        if ($this->rollingRestart && $workerId === $this->drainingWorkerId) {
            $this->drainingWorkerId = 0;
            $this->drainNextWorker();
        }
    }
    
    // ========== 维护 Worker 管理 ==========
    
    /**
     * 启动维护 Worker（单 Worker 场景兜底）
     */
    protected function startMaintenanceWorkers(): void
    {
        $totalWorkers = \count($this->workers);
        $maintenanceCount = \max(1, (int)\ceil($totalWorkers / 10));
        
        $this->log(__('启动 %{1} 个维护 Worker', [$maintenanceCount]));
        
        $basePort = $this->getMaintenanceWorkerBasePort();
        
        for ($i = 0; $i < $maintenanceCount; $i++) {
            $port = $basePort + $i;
            $pid = $this->startSingleMaintenanceWorker($port);
            if ($pid > 0) {
                $this->maintenanceWorkers[] = ['port' => $port, 'pid' => $pid];
                
                // 通知 Dispatcher 添加维护 Worker 端口
                if ($this->controlServer) {
                    $this->controlServer->sendToRole(
                        ControlMessage::ROLE_DISPATCHER,
                        ControlMessage::addWorker([$port])
                    );
                }
                
                $this->log(__('维护 Worker 已启动，端口: %{1}, PID: %{2}', [$port, $pid]));
            }
        }
    }
    
    /**
     * 停止所有维护 Worker
     */
    protected function stopMaintenanceWorkers(): void
    {
        if (empty($this->maintenanceWorkers)) {
            return;
        }
        
        $ports = [];
        foreach ($this->maintenanceWorkers as $mw) {
            $port = $mw['port'];
            $ports[] = $port;
            
            // 通过控制通道发送 shutdown
            if ($this->controlServer) {
                $this->controlServer->sendToWorkerByPort($port, ControlMessage::shutdown('maintenance_done'));
            }
            
            $this->log(__('维护 Worker (端口: %{1}) 已通知关闭', [$port]));
        }
        
        // 通知 Dispatcher 移除维护 Worker 端口
        if (!empty($ports) && $this->controlServer) {
            $this->controlServer->sendToRole(
                ControlMessage::ROLE_DISPATCHER,
                ControlMessage::removeWorker($ports)
            );
        }
        
        $this->maintenanceWorkers = [];
    }
    
    /**
     * 获取维护 Worker 的基础端口
     */
    protected function getMaintenanceWorkerBasePort(): int
    {
        // 直连模式：所有 Worker 共用主端口，基于主端口 + 100
        if ($this->mode === self::MODE_LINUX_DIRECT && $this->mainPort > 0) {
            return $this->mainPort + 100;
        }
        // Dispatcher/Legacy 模式：最大 Worker 端口 + 100
        $maxPort = 0;
        foreach ($this->workers as $worker) {
            if ($worker['port'] > $maxPort) {
                $maxPort = $worker['port'];
            }
        }
        return $maxPort + 100;
    }
    
    /**
     * 启动单个维护 Worker
     */
    protected function startSingleMaintenanceWorker(int $port): int
    {
        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $instanceFile = $this->getInstanceFile();
        
        $workerScript = $this->workerScript;
        $args = [
            '--port=' . $port,
            '--instance=' . $this->instanceName,
            '--maintenance',
            '--control-port=' . $this->controlPort,
            '--master-pid=' . \getmypid(),
        ];
        
        if ($this->sslCert) {
            $args[] = '--ssl-cert=' . $this->sslCert;
        }
        if ($this->sslKey) {
            $args[] = '--ssl-key=' . $this->sslKey;
        }
        
        $cmd = $phpBinary . ' ' . \escapeshellarg($workerScript) . ' ' . \implode(' ', \array_map('escapeshellarg', $args));
        
        $pid = Processer::create($cmd, false);
        if ($pid > 0) {
            return $pid;
        }
        \usleep(500000);
        return Processer::getProcessIdByPort($port) ?: 0;
    }
    
    // ========== IPC 控制服务器 ==========
    
    /**
     * 启动 IPC 控制服务器
     */
    protected function startControlServer(): void
    {
        // 优先使用用户显式配置的端口（config > env），否则让 OS 自动分配
        $this->controlPort = (int)($this->config['control_port'] ?? 0);
        $portSource = 'config';
        
        if ($this->controlPort <= 0) {
            try {
                $envControlPort = Env::get('server.control_port', 0);
                $this->controlPort = (int)$envControlPort;
                $portSource = 'env';
            } catch (\Throwable $e) {
                // ignore
            }
        }
        
        if ($this->controlPort <= 0) {
            $portSource = 'OS 自动分配';
        }
        $this->log(__('  IPC 端口来源: %{1} (请求端口: %{2})', [$portSource, $this->controlPort ?: 'auto']));
        
        // controlPort=0 → 让 OS 自动分配空闲端口（绝不冲突）
        $this->controlServer = new MasterControlServer();
        $host = '127.0.0.1';
        $this->log(__('  IPC 绑定地址: %{1}', [$host]));
        
        if (!$this->controlServer->start($host, $this->controlPort)) {
            $this->log(__('  IPC 控制服务器启动失败 (端口: %{1})，将使用降级模式', [$this->controlPort ?: 'auto']), 'warning');
            $this->controlServer = null;
            return;
        }
        
        // 获取 OS 实际分配的端口（port=0 时由 OS 分配）
        $this->controlPort = $this->controlServer->getPort();
        
        $this->log(__('  IPC 控制服务器已启动，实际端口: %{1}', [$this->controlPort]), 'success');
        
        // 注册 IPC 日志回调（亮洋红色突出 IPC 通信）
        $this->controlServer->setLogger(function (string $line) {
            $this->log("\033[95m{$line}\033[0m");
        });
        
        // DEV 模式下输出详细 IPC SEND/RECV 明细，否则只输出关键事件（CONNECT/DISCONNECT）
        $isDevMode = (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE);
        $this->controlServer->setVerboseLog($isDevMode);
        
        // 注册消息处理器
        $this->controlServer->onMessage(function (array $msg, int $clientId, MasterControlServer $server) {
            $this->handleControlMessage($msg, $clientId);
        });
        
        // 注册断开处理器
        $this->controlServer->onDisconnect(function (int $clientId, array $clientInfo, MasterControlServer $server) {
            $this->handleControlDisconnect($clientId, $clientInfo);
        });
    }
    
    /**
     * 处理控制通道消息
     */
    protected function handleControlMessage(array $msg, int $clientId): void
    {
        $type = $msg['type'] ?? '';
        
        switch ($type) {
            case ControlMessage::TYPE_REGISTER:
                // register 已在 MasterControlServer 内部处理（设置 role/pid/port + 发送 ack）
                $role = $msg['role'] ?? '';
                $pid  = $msg['pid'] ?? 0;
                $port = $msg['port'] ?? 0;
                $wid  = $msg['worker_id'] ?? 0;
                $this->log(__('进程注册: role=%{1}, pid=%{2}, port=%{3}, worker_id=%{4}', [$role, $pid, $port, $wid]));
                
                // 更新内部 workers / dispatcher / httpRedirect 的 PID；IPC 注册为最可靠的就绪信号，复活后重连即相互认识
                if ($role === ControlMessage::ROLE_WORKER && $wid > 0 && isset($this->workers[$wid])) {
                    $this->workers[$wid]['pid'] = (int)$pid;
                    $this->workers[$wid]['state'] = self::WORKER_STATE_RUNNING;
                    $this->workers[$wid]['state_since'] = \time();
                } elseif ($role === ControlMessage::ROLE_DISPATCHER && $this->dispatcher !== null) {
                    $this->dispatcher['pid'] = (int)$pid;
                } elseif ($role === ControlMessage::ROLE_REDIRECT && $this->httpRedirectWorker !== null) {
                    $this->httpRedirectWorker['pid'] = (int)$pid;
                }
                break;
                
            case ControlMessage::TYPE_READY:
                $role = $msg['role'] ?? '';
                $wid  = (int)($msg['worker_id'] ?? 0);
                $port = (int)($msg['port'] ?? 0);
                
                if ($role === ControlMessage::ROLE_WORKER || $role === ControlMessage::ROLE_MAINTENANCE) {
                    $this->handleWorkerReady($wid, $port);
                }
                break;
                
            case ControlMessage::TYPE_DRAINING_COMPLETE:
                $wid  = (int)($msg['worker_id'] ?? 0);
                $port = (int)($msg['port'] ?? 0);
                $this->handleDrainingComplete($wid, $port);
                break;
                
            case ControlMessage::TYPE_STATUS_REPORT:
                // 可选：记录 Worker 运行状态
                break;
                
            case ControlMessage::TYPE_COMMAND:
                $this->handleCliCommand($msg, $clientId);
                break;
        }
    }
    
    /**
     * 处理 CLI 命令（server:stop / server:reload / cache:clear / server:status）
     */
    protected function handleCliCommand(array $msg, int $clientId): void
    {
        $action = $msg['action'] ?? '';
        
        switch ($action) {
            case ControlMessage::ACTION_STOP:
                $this->log(__('收到 CLI 停止命令'));
                // 通过 IPC 控制通道广播 shutdown，子进程收后不复活（不依赖文件信号）
                if ($this->controlServer) {
                    $this->controlServer->broadcast(ControlMessage::shutdown('server:stop'));
                    // 给子进程时间收到消息
                    \usleep(100000); // 100ms
                    $this->controlServer->sendTo($clientId, ControlMessage::commandResult(true, [], 'stopping'));
                }
                $this->shouldStopFlag = true;
                break;
                
            case ControlMessage::ACTION_RELOAD:
                $reloadType = $msg['reload_type'] ?? ControlMessage::RELOAD_TYPE_CODE;
                $this->log(__('收到 CLI 重载命令 (类型: %{1})', [$reloadType]));
                $this->initiateRollingRestart($reloadType);
                if ($this->controlServer) {
                    $this->controlServer->sendTo($clientId, ControlMessage::commandResult(true, [], 'reload initiated'));
                }
                break;
                
            case ControlMessage::ACTION_CACHE_CLEAR:
                $this->log(__('收到 CLI 缓存清理命令'));
                $this->initiateRollingRestart(ControlMessage::RELOAD_TYPE_CACHE);
                if ($this->controlServer) {
                    $this->controlServer->sendTo($clientId, ControlMessage::commandResult(true, [], 'cache cleared'));
                }
                break;
                
            case ControlMessage::ACTION_STATUS:
                $status = $this->getWorkersStatus();
                if ($this->controlServer) {
                    $this->controlServer->sendTo($clientId, ControlMessage::commandResult(true, [
                        'workers'    => $status,
                        'mode'       => $this->mode,
                        'uptime'     => $this->getUptime(),
                        'master_pid' => \getmypid(),
                    ]));
                }
                break;
        }
    }
    
    /**
     * 处理控制通道客户端断开
     */
    protected function handleControlDisconnect(int $clientId, array $clientInfo): void
    {
        $role     = $clientInfo['role'] ?? '';
        $pid      = $clientInfo['pid'] ?? 0;
        $port     = $clientInfo['port'] ?? 0;
        $workerId = $clientInfo['worker_id'] ?? 0;
        
        if ($role === ControlMessage::ROLE_WORKER && $workerId > 0) {
            $this->handleWorkerDisconnect($workerId, $port);
        } elseif ($role === ControlMessage::ROLE_DISPATCHER) {
            $this->log(__('Dispatcher (PID: %{1}) 断开', [$pid]), 'warning');
        } elseif ($role === ControlMessage::ROLE_REDIRECT) {
            $this->log(__('HTTP Redirect Worker (PID: %{1}) 断开', [$pid]), 'warning');
        } elseif ($role === ControlMessage::ROLE_MAINTENANCE) {
            $this->log(__('维护 Worker (端口: %{1}) 断开', [$port]));
        }
        // CLI 命令连接断开不需要处理
    }
    
    /**
     * 重置重启计数
     */
    protected function maybeResetRestartCounts(): void
    {
        if (\time() - $this->lastRestartCountReset >= $this->restartCountResetInterval) {
            foreach ($this->workers as &$worker) {
                $worker['restarts'] = 0;
            }
            $this->lastRestartCountReset = \time();
            $this->log(__('重启计数已重置'));
        }
    }
    
    /**
     * 检查是否应该停止（由内部标志控制，不再使用文件）
     */
    protected function shouldStop(): bool
    {
        return $this->shouldStopFlag;
    }
    
    /**
     * 是否允许执行管理策略（健康检查、重启 Worker 等）
     *
     * 统一判断入口：关闭/启动期间不执行，收到关闭信号立即停止，完全启动后才恢复。
     * - 已收到停止信号 → 不允许（直接停止）
     * - 处于启动阶段（阶段 0～5）→ 不允许（等完全重启后恢复）
     * - 复活阶段（resurrectionMode 下阶段 0～5）→ 不允许，等待复活结果
     * - 服务异常状态（复活三次失败等）→ 不允许，等待人工干预
     * - 否则 → 允许
     */
    protected function shouldRunManagementStrategies(): bool
    {
        if ($this->shouldStopFlag) {
            return false;
        }
        // 批量强制重启期间禁止复活逻辑（由 batchForceRestartWorkers 统一管理）
        if ($this->forceRestarting) {
            return false;
        }
        if ($this->startupPhase) {
            return false;
        }
        if (self::hasServiceException($this->instanceName)) {
            return false;
        }
        return true;
    }
    
    /**
     * 通过 IPC 控制通道发送停止命令给 Master
     *
     * @param string $instanceName 实例名称
     * @return bool 是否发送成功
     */
    public static function sendStopCommand(string $instanceName = 'default'): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            return false;
        }
        
        $controlPort = (int)$info['control_port'];
        $host = '127.0.0.1';
        
        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 3);
        if (!$conn) {
            return false;
        }
        
        @\fwrite($conn, ControlMessage::command(ControlMessage::ACTION_STOP));
        
        // 等待 command_result
        \stream_set_timeout($conn, 5);
        $response = '';
        $deadline = \microtime(true) + 5;
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            if ($data === false || $data === '') {
                break;
            }
            $response .= $data;
            if (\strpos($response, "\n") !== false) {
                break;
            }
        }
        @\fclose($conn);
        
        $msg = ControlMessage::decode($response);
        return $msg !== null && ($msg['success'] ?? false);
    }
    
    /**
     * 通过 IPC 发送重载命令给 Master
     */
    public static function sendReloadCommand(string $instanceName = 'default', string $reloadType = 'code'): bool
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            return false;
        }
        
        $controlPort = (int)$info['control_port'];
        $host = '127.0.0.1';
        
        $errno = 0;
        $errstr = '';
        $conn = @\stream_socket_client("tcp://{$host}:{$controlPort}", $errno, $errstr, 3);
        if (!$conn) {
            return false;
        }
        
        @\fwrite($conn, ControlMessage::command(ControlMessage::ACTION_RELOAD, $reloadType));
        
        // 等待 command_result
        \stream_set_timeout($conn, 5);
        $response = '';
        $deadline = \microtime(true) + 5;
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            if ($data === false || $data === '') {
                break;
            }
            $response .= $data;
            if (\strpos($response, "\n") !== false) {
                break;
            }
        }
        @\fclose($conn);
        
        $msg = ControlMessage::decode($response);
        return $msg !== null && ($msg['success'] ?? false);
    }
    
    /**
     * 通过 IPC 发送缓存清理命令给 Master
     */
    public static function sendCacheClearCommand(string $instanceName = 'default'): bool
    {
        return self::sendReloadCommand($instanceName, ControlMessage::RELOAD_TYPE_CACHE);
    }
    
    /**
     * 向 Master 控制端口发送命令并获取响应（通用方法）
     *
     * @param string $instanceName 实例名称
     * @param string $action 命令动作
     * @return array|null 响应消息，失败返回 null
     */
    public static function sendCommand(string $instanceName, string $action): ?array
    {
        $info = self::getMasterInfo($instanceName);
        if (!$info || empty($info['control_port'])) {
            return null;
        }
        
        $controlPort = (int)$info['control_port'];
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, 3);
        if (!$conn) {
            return null;
        }
        
        @\fwrite($conn, ControlMessage::command($action));
        
        \stream_set_timeout($conn, 5);
        $response = '';
        $deadline = \microtime(true) + 5;
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            if ($data === false || $data === '') {
                break;
            }
            $response .= $data;
            if (\strpos($response, "\n") !== false) {
                break;
            }
        }
        @\fclose($conn);
        
        return ControlMessage::decode($response);
    }
    
    // ========== Worker 重启互斥锁 ==========
    
    /**
     * 尝试获取 Worker 重启锁
     * 
     * 同一时刻只允许一个路径（健康检查 / 滚动重启 / IPC 断开回调）重启某个 Worker。
     * 锁带有 30 秒过期保护：如果进程崩溃导致锁未释放，超时后自动过期。
     *
     * @param int $workerId Worker ID
     * @return bool true=获取成功，可以重启；false=已被锁定，跳过
     */
    protected function acquireWorkerRestartLock(int $workerId): bool
    {
        $now = \time();
        
        // 检查锁是否已存在
        if (isset($this->restartingWorkers[$workerId])) {
            $lockTime = $this->restartingWorkers[$workerId];
            // 30 秒超时保护
            if (($now - $lockTime) < 30) {
                $this->log(__('Worker #%{1} 正在重启中（已持续 %{2} 秒），跳过重复启动', [$workerId, $now - $lockTime]), 'warning');
                return false;
            }
            // 超时，强制释放
            $this->log(__('Worker #%{1} 重启锁超时（%{2} 秒），强制释放', [$workerId, $now - $lockTime]), 'warning');
        }
        
        $this->restartingWorkers[$workerId] = $now;
        return true;
    }
    
    /**
     * 释放 Worker 重启锁
     */
    protected function releaseWorkerRestartLock(int $workerId): void
    {
        unset($this->restartingWorkers[$workerId]);
    }
    
    /**
     * 检查 Worker 是否正在重启中
     */
    protected function isWorkerRestarting(int $workerId): bool
    {
        if (!isset($this->restartingWorkers[$workerId])) {
            return false;
        }
        
        // 超过 30 秒视为已过期
        if ((\time() - $this->restartingWorkers[$workerId]) >= 30) {
            unset($this->restartingWorkers[$workerId]);
            return false;
        }
        
        return true;
    }
    
    /**
     * 是否已执行过 cleanup（防止重复调用）
     */
    protected bool $cleanedUp = false;
    
    /**
     * 清理资源：通过控制通道通知所有子进程优雅退出，关闭控制服务器，更新实例文件
     */
    public function cleanup(): void
    {
        // 防止重复调用（shutdown function 和正常退出都会触发）
        if ($this->cleanedUp) {
            return;
        }
        $this->cleanedUp = true;
        
        // ⭐ 立即设置停止标志，禁止所有复活/重启/健康检查逻辑
        // 这确保 handleWorkerDisconnect 中的 shouldRunManagementStrategies() 返回 false
        $this->shouldStopFlag = true;
        $this->running = false;
        
        $masterPid = \getmypid();
        $this->log('========================================');
        $this->log(__('Master 开始清理'), 'warning');
        $this->log(__('  Master PID: %{1}', [$masterPid]));
        $this->log(__('  Worker 数量: %{1}', [\count($this->workers)]));
        $this->log(__('  Dispatcher: %{1}', [$this->dispatcher !== null ? '端口 ' . ($this->dispatcher['port'] ?? '?') : '无']));
        $this->log(__('  HTTP 重定向: %{1}', [$this->httpRedirectWorker !== null ? '端口 ' . ($this->httpRedirectWorker['port'] ?? '?') : '无']));
        $this->log(__('  内存使用: %{1} MB', [\round(\memory_get_usage(true) / 1024 / 1024, 2)]));
        $this->log('========================================');
        
        // ========== 统一使用 IPC 通道广播 shutdown 并等待子进程退出 ==========
        $gracefulShutdownTimeout = 10; // 等待子进程优雅退出的最长时间（秒）
        $waitedChildren = false;
        
        if ($this->controlServer) {
            // 获取当前连接的子进程数量
            $connectedClients = $this->controlServer->getConnectedClients();
            $childCount = \count($connectedClients);
            
            if ($childCount > 0) {
                // 广播 shutdown 命令
                $this->controlServer->broadcast(ControlMessage::shutdown('master_cleanup'));
                $this->log(__('已通过 IPC 通道广播 shutdown 命令 (子进程数: %{1})', [$childCount]));
                
                // 等待所有子进程断开连接（收到 exited 或 TCP FIN）
                $startTime = \time();
                $lastLogTime = $startTime;
                
                while (true) {
                    // 检查超时
                    $elapsed = \time() - $startTime;
                    if ($elapsed >= $gracefulShutdownTimeout) {
                        $remaining = $this->controlServer->getConnectedClients();
                        $remainingCount = \count($remaining);
                        if ($remainingCount > 0) {
                            $this->log(__('等待超时 (%{1}s)，仍有 %{2} 个子进程未退出', [$gracefulShutdownTimeout, $remainingCount]), 'warning');
                        }
                        break;
                    }
                    
                    // 轮询 IPC 通道，处理子进程消息和断开事件
                    $this->controlServer->poll(100); // 100ms
                    
                    // 检查是否所有子进程都已断开
                    $remaining = $this->controlServer->getConnectedClients();
                    $remainingCount = \count($remaining);
                    
                    if ($remainingCount === 0) {
                        $this->log(__('所有子进程已优雅退出 (耗时: %{1}s)', [$elapsed]));
                        $waitedChildren = true;
                        break;
                    }
                    
                    // 每秒输出一次等待日志
                    $now = \time();
                    if ($now > $lastLogTime) {
                        $lastLogTime = $now;
                        $roles = [];
                        foreach ($remaining as $client) {
                            $role = $client['role'] ?? 'unknown';
                            $wid = $client['worker_id'] ?? 0;
                            if ($role === ControlMessage::ROLE_WORKER && $wid > 0) {
                                $roles[] = "Worker#{$wid}";
                            } else {
                                $roles[] = \ucfirst($role);
                            }
                        }
                        $this->log(__('等待子进程退出... (%{1}s/%{2}s) 剩余: %{3}', [$elapsed, $gracefulShutdownTimeout, \implode(', ', $roles)]));
                    }
                }
            }
        }
        
        // ========== 兜底：批量强制清理未退出的进程 ==========
        $this->batchKillRemainingProcesses();
        
        // 5. 关闭 IPC 控制服务器
        if ($this->controlServer) {
            $this->controlServer->close();
            $this->controlServer = null;
            $this->log(__('IPC 控制服务器已关闭'));
        }
        
        // 6. 刷新日志缓冲
        if ($this->logBuffer) {
            $this->logBuffer->flush(true);
        }
        
        // 7. 更新实例文件（原子更新，带文件锁）
        $instanceFile = $this->getInstanceFile();
        if (\is_file($instanceFile)) {
            ServerInstanceService::atomicUpdateJson($instanceFile, function (array $data): array {
                $data['master_enabled'] = false;
                $data['master_pid'] = 0;
                $data['control_port'] = 0;
                $data['dispatcher_pid'] = 0;
                unset($data['http_redirect_pid']);
                return $data;
            });
        }
        
        $this->log('========================================');
        $this->log(__('Master 清理完成，进程即将退出'), 'success');
        $this->log(__('  内存峰值: %{1} MB', [\round(\memory_get_peak_usage(true) / 1024 / 1024, 2)]));
        $this->log('========================================');
    }
    
    /**
     * 批量强制清理未退出的进程
     * 
     * 核心思路：
     * 1. 收集所有仍在运行的进程 PID
     * 2. 批量发送 kill 信号（而不是逐个等待）
     * 3. 统一等待一次
     * 4. 验证并清理残留
     */
    protected function batchKillRemainingProcesses(): void
    {
        $isDirectMode = ($this->mode === self::MODE_LINUX_DIRECT);
        
        // ========== 阶段 1：收集所有需要清理的进程 PID ==========
        $processesToKill = []; // ['label' => string, 'pid' => int, 'port' => int, 'processName' => string]
        
        // 收集 Worker 进程
        foreach ($this->workers as $workerId => $worker) {
            $port = $worker['port'] ?? 0;
            $pid = (int) ($worker['pid'] ?? 0);
            $processName = '--name=' . 'weline-master-' . $this->instanceName . '-worker-' . $workerId;
            
            // 检查进程是否仍在运行
            $stillRunning = ($pid > 0 && Processer::isRunningByPid($pid)) 
                || ($port > 0 && !$isDirectMode && Processer::isPortInUse($port));
            
            if ($stillRunning) {
                // 如果没有 PID，尝试从端口获取
                if ($pid <= 0 && $port > 0) {
                    $pid = Processer::getProcessIdByPort($port);
                }
                if ($pid > 0) {
                    $processesToKill[] = [
                        'label' => "Worker #{$workerId}",
                        'pid' => $pid,
                        'port' => $port,
                        'processName' => $processName,
                    ];
                }
            } else {
                $this->log(__('  Worker #%{1} 已停止 (端口: %{2})', [$workerId, $port]));
            }
        }
        
        // 收集维护 Worker
        foreach ($this->maintenanceWorkers as $mw) {
            $port = $mw['port'] ?? 0;
            $pid = (int) ($mw['pid'] ?? 0);
            if ($port > 0 && Processer::isPortInUse($port)) {
                if ($pid <= 0) {
                    $pid = Processer::getProcessIdByPort($port);
                }
                if ($pid > 0) {
                    $processesToKill[] = [
                        'label' => "Maintenance Worker",
                        'pid' => $pid,
                        'port' => $port,
                        'processName' => '',
                    ];
                }
            }
        }
        $this->maintenanceWorkers = [];
        
        // 收集 Dispatcher 进程
        $dispatcherPort = $this->dispatcher['port'] ?? 0;
        if ($dispatcherPort <= 0) {
            $instanceFile = $this->getInstanceFile();
            if (\is_file($instanceFile)) {
                $data = @\json_decode(\file_get_contents($instanceFile), true);
                $dispatcherPort = (int) ($data['dispatcher_port'] ?? 0);
            }
        }
        if ($dispatcherPort > 0 && Processer::isPortInUse($dispatcherPort)) {
            $dispatcherPid = (int) ($this->dispatcher['pid'] ?? 0);
            if ($dispatcherPid <= 0) {
                $dispatcherPid = Processer::getProcessIdByPort($dispatcherPort);
            }
            if ($dispatcherPid > 0) {
                $processesToKill[] = [
                    'label' => 'Dispatcher',
                    'pid' => $dispatcherPid,
                    'port' => $dispatcherPort,
                    'processName' => '--name=weline-dispatcher-' . $this->instanceName,
                ];
            }
        }
        
        // 收集 HTTP 重定向进程
        $httpRedirectPort = $this->httpRedirectWorker['port'] ?? 0;
        if ($httpRedirectPort <= 0) {
            $instanceFile = $this->getInstanceFile();
            if (\is_file($instanceFile)) {
                $data = @\json_decode(\file_get_contents($instanceFile), true);
                $httpRedirectPort = (int) ($data['http_redirect_port'] ?? 0);
            }
        }
        if ($httpRedirectPort > 0 && Processer::isPortInUse($httpRedirectPort)) {
            // 检查是否是框架进程
            if (Processer::isPortUsedByWeline($httpRedirectPort)) {
                $httpRedirectPid = (int) ($this->httpRedirectWorker['pid'] ?? 0);
                if ($httpRedirectPid <= 0) {
                    $httpRedirectPid = Processer::getProcessIdByPort($httpRedirectPort);
                }
                if ($httpRedirectPid > 0) {
                    $processesToKill[] = [
                        'label' => 'HTTP Redirect',
                        'pid' => $httpRedirectPid,
                        'port' => $httpRedirectPort,
                        'processName' => '--name=' . self::HTTP_REDIRECT_PROCESS_NAME,
                    ];
                }
            } else {
                $this->log(__('HTTP 重定向端口 %{1} 被非框架进程占用，跳过清理', [$httpRedirectPort]));
            }
        }
        
        // 如果没有需要清理的进程
        if (empty($processesToKill)) {
            $this->log(__('所有子进程已停止'));
            return;
        }
        
        // ========== 阶段 2：批量发送 kill 信号 ==========
        $labels = [];
        $pidsToWait = [];
        
        foreach ($processesToKill as $proc) {
            $pid = $proc['pid'];
            $label = $proc['label'];
            $port = $proc['port'];
            $labels[] = "{$label}(PID:{$pid})";
            $pidsToWait[$pid] = ['label' => $label, 'port' => $port];
            
            // Mac/Linux: 使用 posix_kill 发送 SIGTERM
            if (!\defined('IS_WIN') || !IS_WIN) {
                if (\function_exists('posix_kill')) {
                    @\posix_kill($pid, \SIGTERM);
                }
            } else {
                // Windows: 使用 taskkill（不等待）
                @\exec("taskkill /PID {$pid} /F 2>NUL", $output, $code);
            }
        }
        
        $this->log(__('批量发送终止信号: %{1}', [\implode(', ', $labels)]));
        
        // ========== 阶段 3：统一等待进程退出 ==========
        $waitTimeout = 3; // 批量杀死后的等待时间（秒）
        $startTime = \time();
        
        while (!empty($pidsToWait) && (\time() - $startTime) < $waitTimeout) {
            \usleep(200000); // 200ms
            
            foreach ($pidsToWait as $pid => $info) {
                if (!Processer::isRunningByPid($pid)) {
                    $this->log(__('  %{1} 已停止 (端口: %{2})', [$info['label'], $info['port']]));
                    unset($pidsToWait[$pid]);
                }
            }
        }
        
        // ========== 阶段 4：强制清理残留进程 ==========
        if (!empty($pidsToWait)) {
            $this->log(__('部分进程未响应，强制清理...'), 'warning');
            
            foreach ($pidsToWait as $pid => $info) {
                // Mac/Linux: 发送 SIGKILL
                if (!\defined('IS_WIN') || !IS_WIN) {
                    if (\function_exists('posix_kill')) {
                        @\posix_kill($pid, \SIGKILL);
                    }
                }
                // 使用 Processer 的进程树杀死（确保子进程也被终止）
                Processer::killProcessTreeByPid($pid, true);
                $this->log(__('  已强制停止 %{1} (PID: %{2}, 端口: %{3})', [$info['label'], $pid, $info['port']]));
            }
            
            // 等待强制杀死生效
            \usleep(500000); // 500ms
        }
        
        // ========== 阶段 5：最终验证（按端口检查） ==========
        $stillRunning = [];
        foreach ($processesToKill as $proc) {
            $port = $proc['port'];
            if ($port > 0 && Processer::isPortInUse($port)) {
                $stillRunning[] = "{$proc['label']} (端口: {$port})";
                // 最后尝试按端口杀死
                Processer::killProcessByPort($port);
            }
        }
        
        if (!empty($stillRunning)) {
            $this->log(__('警告：以下进程仍在运行: %{1}', [\implode(', ', $stillRunning)]), 'warning');
        }
        
        // 清理 PID 文件
        foreach ($processesToKill as $proc) {
            if (!empty($proc['processName'])) {
                Processer::removePidFile($proc['processName']);
            }
        }
        
        $this->log(__('已处理 %{1} 个进程', [\count($processesToKill)]));
    }
    
    /**
     * 输出状态日志
     */
    protected function logStatus(): void
    {
        $this->log(__('=== Master 进程状态 ==='));
        $this->log(__('实例: %{1}', [$this->instanceName]));
        $this->log(__('运行时间: %{1}', [$this->getUptime()]));
        
        foreach ($this->workers as $workerId => $worker) {
            $pid = (int)($worker['pid'] ?? 0);
            $status = ($pid > 0 && Processer::isRunningByPid($pid)) ? '运行中' : '已停止';
            $this->log(__('  Worker #%{1}: 端口=%{2}, PID=%{3}, 状态=%{4}, 重启=%{5}次', [
                $workerId,
                $worker['port'],
                $pid,
                $status,
                $worker['restarts'],
            ]));
        }
        if ($this->httpRedirectWorker !== null) {
            $status = Processer::isPortInUse($this->httpRedirectWorker['port']) ? '运行中' : '已停止';
            $this->log(__('  HTTP 重定向: 端口=%{1}, PID=%{2}, 状态=%{3}', [
                $this->httpRedirectWorker['port'],
                $this->httpRedirectWorker['pid'],
                $status,
            ]));
        }
    }
    
    /**
     * 获取运行时间
     */
    protected function getUptime(): string
    {
        $info = self::getMasterInfo();
        if (!$info || empty($info['started_at'])) {
            return '-';
        }
        
        $start = \strtotime($info['started_at']);
        $seconds = \time() - $start;
        
        $days = \floor($seconds / 86400);
        $hours = \floor(($seconds % 86400) / 3600);
        $minutes = \floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($days > 0) {
            return "{$days}天 {$hours}时 {$minutes}分";
        } elseif ($hours > 0) {
            return "{$hours}时 {$minutes}分 {$secs}秒";
        } elseif ($minutes > 0) {
            return "{$minutes}分 {$secs}秒";
        }
        return "{$secs}秒";
    }
    
    /**
     * 日志输出
     */
    protected function log(string $message, string $level = 'note'): void
    {
        $time = \date('Y-m-d H:i:s');
        $logMessage = "[{$time}] [Master] {$message}";
        
        // 写入日志文件（剥离 ANSI 颜色码，避免日志文件乱码）
        $logFile = Env::VAR_DIR . 'log' . DS . 'master.log';
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $plainMessage = \preg_replace('/\033\[[0-9;]*m/', '', $logMessage);
        @\file_put_contents($logFile, $plainMessage . PHP_EOL, FILE_APPEND);
        
        // 如果有打印器且非守护进程模式，输出到控制台
        if ($this->printer && !($this->config['daemon'] ?? false)) {
            $this->printer->$level($logMessage);
        }
    }
    
    /**
     * 获取所有 Worker 状态
     */
    public function getWorkersStatus(): array
    {
        $status = [];
        foreach ($this->workers as $workerId => $worker) {
            $pid = (int)($worker['pid'] ?? 0);
            $isRunning = ($pid > 0 && Processer::isRunningByPid($pid)) || Processer::isPortInUse($worker['port']);
            $status[$workerId] = [
                'port' => $worker['port'],
                'pid' => $pid,
                'running' => $isRunning,
                'restarts' => $worker['restarts'],
            ];
        }
        return $status;
    }
}
