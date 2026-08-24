<?php
declare(strict_types=1);

/**
 * Weline Server Windows Dispatcher 策略
 * 
 * 使用 Dispatcher TCP 透传模式：
 * - Dispatcher 监听主端口（如 443），纯 TCP 转发
 * - 多个 Worker 各自监听不同内网端口，处理 SSL
 * 
 * 优势：
 * - 兼容 Windows（不支持 SO_REUSEPORT）
 * - SSL 握手分散到各 Worker，多核并行
 * - Dispatcher 极简，只做字节转发，低开销
 * 
 * 适用平台：Windows（及其他不支持 SO_REUSEPORT 的系统）
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Strategy;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Windows Dispatcher 策略（TCP 透传）
 */
class WindowsDispatcherStrategy implements ServerStrategyInterface
{
    /**
     * @var callable|null 日志回调
     */
    private $logCallback = null;
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('Windows Dispatcher TCP 透传模式');
    }
    
    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return 'windows-dispatcher';
    }
    
    /**
     * @inheritDoc
     */
    public function supports(): bool
    {
        // Windows 使用 Dispatcher 透传
        if (\defined('IS_WIN') && IS_WIN) {
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }
        // 统一透传：Linux 也使用 Dispatcher 透传（与 Windows 一致）
        if (\defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux') {
            return true;
        }
        // 不支持 SO_REUSEPORT 时的后备方案（如旧内核）
        if (!\defined('SO_REUSEPORT')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 设置日志回调
     * 
     * @param callable $callback
     * @return void
     */
    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }
    
    /**
     * 记录日志
     * 
     * @param string $message
     * @param string $level
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logCallback) {
            ($this->logCallback)($message, $level);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function start(ServerConfig $config): bool
    {
        $this->log(__('使用 %{1} 启动服务器', [$this->getName()]));
        
        // 确保日志目录存在
        if (!\is_dir($config->logDir)) {
            @\mkdir($config->logDir, 0755, true);
        }
        
        // 1. 先启动 Worker 进程（各自监听不同端口，处理 SSL）
        $workerPids = $this->startWorkers($config);
        
        if (empty($workerPids)) {
            $this->log(__('启动 Worker 失败'), 'ERROR');
            return false;
        }
        
        // 等待 Worker 启动完成
        SchedulerSystem::usleep(500000); // 500ms
        
        // 2. 启动 Dispatcher 进程（TCP 透传）
        $dispatcherPid = $this->startDispatcher($config);
        
        if ($dispatcherPid <= 0 && !$config->frontend) {
            $this->log(__('Dispatcher 启动失败'), 'WARN');
            // Dispatcher 启动失败不是致命错误（前端模式下可能返回 0）
        }
        
        // 3. 启动 HTTP 重定向 Worker（如果启用）
        $httpRedirectPid = 0;
        if ($config->httpRedirectEnabled && $config->sslEnabled) {
            $httpRedirectPid = $this->startHttpRedirectWorker($config);
        }
        
        // 4. 保存实例信息
        $this->saveInstanceInfo($config, $workerPids, $dispatcherPid, $httpRedirectPid);
        
        // 5. 启动 Master 进程（用于监控和热重载）
        if (!$config->frontend) {
            $this->startMasterProcess($config);
        }
        
        return true;
    }
    
    /**
     * 启动 Worker 进程
     * 
     * 每个 Worker 监听不同的内网端口，各自处理 SSL
     * 
     * @param ServerConfig $config
     * @return int[] Worker PID 列表
     */
    private function startWorkers(ServerConfig $config): array
    {
        $pids = [];
        $workerScript = $config->getWorkerScript();
        
        if (!\is_file($workerScript)) {
            $this->log(__('Worker 脚本不存在: %{1}', [$workerScript]), 'ERROR');
            return [];
        }
        
        $workerPorts = $config->getWorkerPorts();
        
        foreach ($workerPorts as $i => $port) {
            $workerId = $i + 1;
            
            $pid = $this->startSingleWorker($config, $workerScript, $port, $workerId);
            
            if ($pid > 0) {
                $pids[] = $pid;
                $protocol = $config->sslEnabled ? 'HTTPS' : 'HTTP';
                $this->log(__('Worker #%{1} (%{2} 端口: %{3}) - 启动成功 (PID: %{4})', [$workerId, $protocol, $port, $pid]));
            } else {
                $this->log(__('Worker #%{1} (端口: %{2}) - 启动失败', [$workerId, $port]), 'ERROR');
            }
        }
        
        return $pids;
    }
    
    /**
     * 启动单个 Worker 进程
     * 
     * @param ServerConfig $config
     * @param string $workerScript
     * @param int $port
     * @param int $workerId
     * @return int PID，失败返回 0
     */
    private function startSingleWorker(ServerConfig $config, string $workerScript, int $port, int $workerId): int
    {
        $processName = \Weline\Server\Service\MasterProcess::buildScopedProcessName('weline-wls-worker', $config->instanceName, $workerId);
        
        // 构建命令
        $command = "\"{$config->phpBinary}\" \"{$workerScript}\" {$config->host} {$port} {$workerId} {$config->instanceName}";
        
        if ($config->sslEnabled) {
            $command .= " \"{$config->sslCert}\" \"{$config->sslKey}\"";
            // TCP 透传模式：Worker 需要延迟 SSL 握手
            // Dispatcher 透传原始 TCP 字节，Worker 在 accept 后手动启用 SSL
            $command .= " --defer-ssl";
        }
        
        $command .= " --memory-limit={$config->workerMemoryLimit}";
        $command .= " --name={$processName}";
        
        if ($config->frontend) {
            $command .= " --frontend";
        }
        
        // 使用进程管理器创建进程
        $pid = Processer::create($command, true, $config->frontend);
        
        // 如果 PID 获取失败，通过端口检测
        if ($pid <= 0) {
            $maxWait = $config->frontend ? 3000 : 2000;
            $waitStep = 100;
            $waited = 0;
            
            while ($waited < $maxWait) {
                SchedulerSystem::usleep((int)($waitStep * 1000));
                $waited += $waitStep;
                
                $detectedPid = Processer::getProcessIdByPort($port);
                if ($detectedPid > 0) {
                    Processer::setPid($processName, $detectedPid);
                    $pid = $detectedPid;
                    break;
                }
            }
        }
        
        return $pid;
    }
    
    /**
     * 启动 Dispatcher 进程（TCP 透传）
     * 
     * @param ServerConfig $config
     * @return int PID
     */
    private function startDispatcher(ServerConfig $config): int
    {
        $dispatcherScript = $config->getDispatcherScript();
        
        if (!\is_file($dispatcherScript)) {
            $this->log(__('Dispatcher 脚本不存在: %{1}', [$dispatcherScript]), 'ERROR');
            return 0;
        }
        
        // 统一进程名
        $processName = \Weline\Server\Service\MasterProcess::buildScopedProcessName('weline-wls-dispatcher', $config->instanceName);
        
        // 构建命令（参数格式: <host> <port> <worker_base_port> <worker_count> <instance_name>）
        $command = "\"{$config->phpBinary}\" \"{$dispatcherScript}\" {$config->host} {$config->port} {$config->workerBasePort} {$config->workerCount} {$config->instanceName}";
        $command .= " --memory-limit={$config->dispatcherMemoryLimit}";
        $command .= " --name={$processName}";
        
        if ($config->frontend) {
            $command .= " --frontend";
        }
        
        $pid = Processer::create($command, true, $config->frontend);
        
        // 如果 PID 获取失败，通过端口检测
        if ($pid <= 0) {
            $maxWait = $config->frontend ? 3000 : 1000;
            $waitStep = 100;
            $waited = 0;
            
            while ($waited < $maxWait) {
                SchedulerSystem::usleep((int)($waitStep * 1000));
                $waited += $waitStep;
                
                $detectedPid = Processer::getProcessIdByPort($config->port);
                if ($detectedPid > 0) {
                    Processer::setPid($processName, $detectedPid);
                    $pid = $detectedPid;
                    break;
                }
            }
        }
        
        if ($pid > 0) {
            $this->log(__('Dispatcher (端口: %{1}) - 启动成功 (PID: %{2})', [$config->port, $pid]));
        }
        
        return $pid;
    }
    
    /**
     * 启动 HTTP 重定向 Worker
     * 
     * @param ServerConfig $config
     * @return int PID
     */
    private function startHttpRedirectWorker(ServerConfig $config): int
    {
        $script = $config->getHttpRedirectScript();
        if (!\is_file($script)) {
            return 0;
        }
        
        $processName = \Weline\Server\Service\MasterProcess::buildScopedProcessName('weline-wls-redirect', $config->instanceName);
        
        $command = "\"{$config->phpBinary}\" \"{$script}\" {$config->host} {$config->httpRedirectPort} {$config->port} {$config->instanceName}";
        $command .= " --name={$processName}";
        
        if ($config->frontend) {
            $command .= " --frontend";
        }
        
        $pid = Processer::create($command, true, $config->frontend);
        
        if ($pid <= 0) {
            $maxWait = 1000;
            $waitStep = 100;
            $waited = 0;
            
            while ($waited < $maxWait) {
                SchedulerSystem::usleep((int)($waitStep * 1000));
                $waited += $waitStep;
                
                $detectedPid = Processer::getProcessIdByPort($config->httpRedirectPort);
                if ($detectedPid > 0) {
                    Processer::setPid($processName, $detectedPid);
                    $pid = $detectedPid;
                    break;
                }
            }
        }
        
        if ($pid > 0) {
            $this->log(__('HTTP 重定向 Worker (端口: %{1}) - 启动成功', [$config->httpRedirectPort]));
        }
        
        return $pid;
    }
    
    /**
     * 启动 Master 进程
     * 
     * @param ServerConfig $config
     * @return int PID
     */
    private function startMasterProcess(ServerConfig $config): int
    {
        $processName = \Weline\Server\Service\MasterProcess::getMasterProcessName($config->instanceName);
        
        // Master 进程通过 Start 命令的 --master-only 参数启动（统一前缀便于按前缀清理逃逸 Master）
        $command = "\"{$config->phpBinary}\" \"" . BP . "bin" . DIRECTORY_SEPARATOR . "w\" server:start --master-only --instance={$config->instanceName}";
        $command .= " --name={$processName}";
        
        $pid = Processer::create($command, true, false);
        
        if ($pid > 0) {
            $this->log(__('Master 进程启动成功 (PID: %{1})', [$pid]));
        }
        
        return $pid;
    }
    
    /**
     * 保存实例信息
     * 
     * @param ServerConfig $config
     * @param int[] $workerPids
     * @param int $dispatcherPid
     * @param int $httpRedirectPid
     */
    private function saveInstanceInfo(ServerConfig $config, array $workerPids, int $dispatcherPid, int $httpRedirectPid): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR;
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0755, true);
        }
        
        $instanceFile = $instanceDir . $config->instanceName . '.json';
        
        $workerPorts = $config->getWorkerPorts();
        $data = [
            'instance_name' => $config->instanceName,
            'strategy' => $this->getIdentifier(),
            'master_mode' => 'windows-dispatcher',  // 运行模式标识，供 Master 进程使用
            'main_port' => $config->port,           // 主端口（Dispatcher 监听）
            'host' => $config->host,
            'port' => $config->port,
            'count' => $config->workerCount,
            'worker_count' => $config->workerCount,
            'worker_pids' => $workerPids,
            'worker_port' => $workerPorts[0] ?? $config->workerBasePort,  // 第一个 Worker 端口
            'worker_ports' => $workerPorts,
            'worker_memory_limit' => $config->workerMemoryLimit,
            'dispatcher_memory_limit' => $config->dispatcherMemoryLimit,
            'dispatcher_enabled' => true,  // Dispatcher 模式
            'dispatcher_pid' => $dispatcherPid,
            'http_redirect_pid' => $httpRedirectPid,
            'ssl_enabled' => $config->sslEnabled,
            'ssl_cert' => $config->sslCert,
            'ssl_key' => $config->sslKey,
            'http_redirect_port' => $config->httpRedirectEnabled ? $config->httpRedirectPort : null,
            'frontend' => $config->frontend,
            'started_at' => \date('Y-m-d H:i:s'),
            'start_time' => \time(),
        ];
        
        ServerInstanceManager::atomicWriteJsonStatic($instanceFile, $data);
    }
    
    /**
     * @inheritDoc
     */
    public function stop(string $instanceName): bool
    {
        $this->log(__('停止实例: %{1}', [$instanceName]));
        
        // 读取实例信息
        $instanceFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        
        if (!\is_file($instanceFile)) {
            $this->log(__('实例不存在: %{1}', [$instanceName]), 'WARN');
            return false;
        }
        
        $data = \json_decode(\file_get_contents($instanceFile), true);
        
        // 停止 Master 进程（使用统一前缀名）
        $masterName = \Weline\Server\Service\MasterProcess::getMasterProcessName($instanceName);
        $masterPid = Processer::getPid($masterName);
        if ($masterPid > 0) {
            Processer::killByPid($masterPid);
            $this->log(__('Master 进程已停止'));
        }
        
        // 停止 Dispatcher 进程
        $dispatcherPid = $data['dispatcher_pid'] ?? 0;
        if ($dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid)) {
            Processer::killByPid($dispatcherPid);
            $this->log(__('Dispatcher 进程已停止'));
        }
        
        // 停止 Worker 进程
        $workerPids = $data['worker_pids'] ?? [];
        foreach ($workerPids as $pid) {
            if ($pid > 0 && Processer::isRunningByPid($pid)) {
                Processer::killByPid($pid);
            }
        }
        $this->log(__('Worker 进程已停止 (%{1} 个)', [\count($workerPids)]));
        
        // 停止 HTTP 重定向 Worker
        $httpRedirectPid = $data['http_redirect_pid'] ?? 0;
        if ($httpRedirectPid > 0 && Processer::isRunningByPid($httpRedirectPid)) {
            Processer::killByPid($httpRedirectPid);
            $this->log(__('HTTP 重定向 Worker 已停止'));
        }
        
        // 删除实例文件
        @\unlink($instanceFile);
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function getStatus(string $instanceName): array
    {
        $instanceFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
        
        if (!\is_file($instanceFile)) {
            return [
                'running' => false,
                'mode' => $this->getIdentifier(),
                'workers' => [],
                'dispatcher' => null,
                'master' => null,
                'uptime' => 0,
            ];
        }
        
        $data = \json_decode(\file_get_contents($instanceFile), true);
        
        // 检查 Worker 状态
        $workers = [];
        $workerPids = $data['worker_pids'] ?? [];
        $workerPorts = $data['worker_ports'] ?? [];
        
        foreach ($workerPids as $i => $pid) {
            $running = $pid > 0 && Processer::isRunningByPid($pid);
            $workers[] = [
                'id' => $i + 1,
                'pid' => $pid,
                'port' => $workerPorts[$i] ?? 0,
                'running' => $running,
            ];
        }
        
        // 检查 Dispatcher 状态
        $dispatcherPid = $data['dispatcher_pid'] ?? 0;
        $dispatcherRunning = $dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid);
        
        // 检查 Master 状态
        $masterName = 'weline-master-' . $instanceName;
        $masterPid = Processer::getPid($masterName);
        $masterRunning = $masterPid > 0 && Processer::isRunningByPid($masterPid);
        
        // 计算运行时间
        $uptime = \time() - ($data['start_time'] ?? \time());
        
        return [
            'running' => $dispatcherRunning || !empty(\array_filter($workers, fn($w) => $w['running'])),
            'mode' => $this->getIdentifier(),
            'workers' => $workers,
            'dispatcher' => [
                'pid' => $dispatcherPid,
                'port' => $data['port'] ?? 0,
                'running' => $dispatcherRunning,
            ],
            'master' => $masterRunning ? ['pid' => $masterPid, 'running' => true] : null,
            'uptime' => $uptime,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function getArchitectureDescription(): string
    {
        return __('Dispatcher 监听主端口做 TCP 透传，Worker 各自处理 SSL，多核并行');
    }
}
