<?php
declare(strict_types=1);

/**
 * Weline Server - 多进程启动命令
 * 
 * 使用框架 Processer 类管理多进程
 * 优先级：proc_open > pcntl_fork > exec（备用）
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\WlsLogService;

/**
 * server:multi-start - 多进程启动
 */
class MultiStart extends CommandAbstract
{
    /**
     * 可用的进程控制函数
     */
    protected array $availableFunctions = [];
    
    /**
     * 使用的启动方式
     */
    protected string $usedMethod = '';
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $host = $args['host'] ?? $args['h'] ?? '127.0.0.1';
        $basePort = (int) ($args['port'] ?? $args['p'] ?? 8080);
        $count = (int) ($args['count'] ?? $args['c'] ?? 4);
        $instanceName = (string)($args['instance'] ?? $args['name'] ?? 'default');
        
        // 检测可用函数
        $this->detectAvailableFunctions();
        
        $this->printer->setup(__('Weline Server 多进程模式'));
        echo "\n";
        $this->printer->note(__('基础端口：%{1}', [$basePort]));
        $this->printer->note(__('进程数量：%{1}', [$count]));
        $this->printer->note(__('平台：%{1}', [IS_WIN ? 'Windows' : 'Linux/Mac']));
        $this->showFunctionStatus();
        echo "\n";
        
        // 检查端口可用性
        $this->printer->note(__('检查端口...'));
        for ($i = 0; $i < $count; $i++) {
            $port = $basePort + $i;
            if (Processer::isPortInUse($port)) {
                $this->printer->warning(__('端口 %{1} 已被占用，尝试释放...', [$port]));
                Processer::killProcessByPort($port);
                SchedulerSystem::sleep(1);
                
                if (Processer::isPortInUse($port)) {
                    $this->printer->error(__('无法释放端口 %{1}', [$port]));
                    return;
                }
            }
        }
        $this->printer->success(__('端口检查通过'));
        echo "\n";
        
        // 创建 Worker 进程脚本
        $workerScript = BP . 'app/code/Weline/Server/bin/worker.php';
        $this->ensureWorkerScript($workerScript);
        
        // 启动多个进程
        $this->printer->note(__('启动 Worker 进程...'));
        $pids = [];
        $phpBinary = PHP_BINARY;
        
        for ($i = 0; $i < $count; $i++) {
            $port = $basePort + $i;
            $workerId = $i + 1;
            
            // 使用最优方式启动进程
            $result = $this->startWorkerProcess($phpBinary, $workerScript, $host, $port, $workerId, $instanceName);
            
            if ($result['success']) {
                $pidInfo = $result['pid'] > 0 ? " (PID: {$result['pid']})" : '';
                $this->printer->success(__('Worker #%{1} 启动成功（端口: %{2}）%{3}', [$workerId, $port, $pidInfo]));
                $pids[] = $result['pid'];
            } else {
                $this->printer->error(__('Worker #%{1} 启动失败：%{2}', [$workerId, $result['error']]));
            }
        }
        
        echo "\n";
        
        // 等待进程启动
        SchedulerSystem::sleep(2);
        
        // 验证进程状态
        $this->printer->note(__('验证进程状态...'));
        $runningCount = 0;
        for ($i = 0; $i < $count; $i++) {
            $port = $basePort + $i;
            $workerId = $i + 1;
            
            $socket = @\fsockopen($host, $port, $errno, $errstr, 2);
            if ($socket) {
                \fclose($socket);
                $this->printer->success(__('  Worker #%{1} (:%{2}) - 运行中', [$workerId, $port]));
                $runningCount++;
            } else {
                $this->printer->error(__('  Worker #%{1} (:%{2}) - 未响应', [$workerId, $port]));
            }
        }
        
        echo "\n";
        $this->printer->setup(__('启动结果'));
        echo "\n";
        $this->printer->note(__('成功启动：%{1}/%{2} 个进程', [$runningCount, $count]));
        $this->printer->note(__('启动方式：%{1}', [$this->usedMethod]));
        
        // 如果使用了备用方案，显示优化建议
        $this->showOptimizationTips();
        
        if ($runningCount > 0) {
            echo "\n";
            $this->printer->note(__('测试命令：'));
            $this->printer->note("  curl http://{$host}:{$basePort}/");
            echo "\n";
            $this->printer->note(__('压测命令：'));
            $this->printer->note("  php bin/w server:benchmark -p {$basePort} -c 100 -n 5000");
            echo "\n";
            $this->printer->note(__('停止命令：'));
            $this->printer->note("  php bin/w server:multi-stop -p {$basePort} -c {$count}");
        }
    }
    
    /**
     * 检测可用的进程控制函数
     */
    protected function detectAvailableFunctions(): void
    {
        $this->availableFunctions = [
            'proc_open' => \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open'),
            'proc_close' => \function_exists('proc_close') && !$this->isFunctionDisabled('proc_close'),
            'proc_get_status' => \function_exists('proc_get_status') && !$this->isFunctionDisabled('proc_get_status'),
            'pcntl_fork' => \function_exists('pcntl_fork') && !$this->isFunctionDisabled('pcntl_fork'),
            'posix_setsid' => \function_exists('posix_setsid') && !$this->isFunctionDisabled('posix_setsid'),
            'exec' => \function_exists('exec') && !$this->isFunctionDisabled('exec'),
            'shell_exec' => \function_exists('shell_exec') && !$this->isFunctionDisabled('shell_exec'),
            'popen' => \function_exists('popen') && !$this->isFunctionDisabled('popen'),
        ];
    }
    
    /**
     * 检查函数是否被禁用
     */
    protected function isFunctionDisabled(string $function): bool
    {
        $disabled = \explode(',', \ini_get('disable_functions') ?: '');
        $disabled = \array_map('trim', $disabled);
        return \in_array($function, $disabled, true);
    }
    
    /**
     * 显示函数状态
     */
    protected function showFunctionStatus(): void
    {
        echo "\n";
        $this->printer->note(__('进程控制函数状态：'));
        
        $status = [];
        $importantFuncs = ['proc_open', 'pcntl_fork', 'exec'];
        
        foreach ($importantFuncs as $func) {
            $available = $this->availableFunctions[$func] ?? false;
            $icon = $available ? '✓' : '✗';
            $status[] = "{$func}: {$icon}";
        }
        
        $this->printer->note('  ' . \implode(' | ', $status));
    }
    
    /**
     * 显示优化建议
     */
    protected function showOptimizationTips(): void
    {
        // 收集未启用的推荐函数
        $missingFunctions = [];
        
        if (!$this->availableFunctions['proc_open']) {
            $missingFunctions[] = 'proc_open';
        }
        if (!$this->availableFunctions['proc_close']) {
            $missingFunctions[] = 'proc_close';
        }
        if (!$this->availableFunctions['proc_get_status']) {
            $missingFunctions[] = 'proc_get_status';
        }
        if (!IS_WIN) {
            if (!$this->availableFunctions['pcntl_fork']) {
                $missingFunctions[] = 'pcntl_fork';
            }
            if (!$this->availableFunctions['posix_setsid']) {
                $missingFunctions[] = 'posix_setsid';
            }
        }
        
        // 如果使用了 exec 备用方案，显示优化建议
        if ($this->usedMethod === 'exec' || $this->usedMethod === 'PowerShell + exec') {
            echo "\n";
            $this->printer->warning(__('⚠️ 性能优化建议'));
            echo "\n";
            $this->printer->note(__('当前使用备用方案（%{1}）启动进程，性能可能受限。', [$this->usedMethod]));
            
            if (!empty($missingFunctions)) {
                echo "\n";
                $this->printer->note(__('建议在 php.ini 中启用以下函数以获得最佳性能：'));
                echo "\n";
                
                foreach ($missingFunctions as $func) {
                    $benefit = $this->getFunctionBenefit($func);
                    $this->printer->note("  • {$func} - {$benefit}");
                }
                
                echo "\n";
                $this->printer->note(__('修改方法：'));
                $this->printer->note(__('  1. 找到 php.ini 文件：%{1}', [\php_ini_loaded_file() ?: 'php.ini']));
                $this->printer->note(__('  2. 找到 disable_functions 配置项'));
                $this->printer->note(__('  3. 移除以上函数名，保存并重启服务'));
                echo "\n";
                $this->printer->success(__('启用后，服务器性能将有质的飞跃！🚀'));
            }
        }
    }
    
    /**
     * 获取函数的性能优势说明
     */
    protected function getFunctionBenefit(string $function): string
    {
        $benefits = [
            'proc_open' => __('进程控制核心函数，支持双向通信和精确的 PID 管理'),
            'proc_close' => __('进程资源释放，防止僵尸进程'),
            'proc_get_status' => __('实时获取进程状态，支持进程监控'),
            'pcntl_fork' => __('真正的进程分叉，共享内存，性能最优'),
            'posix_setsid' => __('创建守护进程，脱离终端控制'),
            'popen' => __('轻量级进程通信'),
        ];
        
        return $benefits[$function] ?? __('进程管理辅助函数');
    }
    
    /**
     * 启动 Worker 进程 - 按优先级选择最优方式
     */
    protected function startWorkerProcess(
        string $phpBinary,
        string $script,
        string $host,
        int $port,
        int $workerId,
        string $instanceName = 'default'
    ): array
    {
        $logFile = WlsLogService::getWorkerLogFile($port, $instanceName);
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        // 方案1：proc_open（最可靠，跨平台）
        if ($this->availableFunctions['proc_open'] && $this->availableFunctions['proc_close']) {
            $result = $this->startWithProcOpen($phpBinary, $script, $host, $port, $workerId, $logFile, $instanceName);
            if ($result['success']) {
                $this->usedMethod = 'proc_open';
                return $result;
            }
        }
        
        // 方案2：pcntl_fork（仅限 Linux/Mac，性能最优）
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $result = $this->startWithPcntlFork($phpBinary, $script, $host, $port, $workerId, $logFile, $instanceName);
            if ($result['success']) {
                $this->usedMethod = 'pcntl_fork';
                return $result;
            }
        }
        
        // 方案3（备用）：exec
        if ($this->availableFunctions['exec']) {
            $result = $this->startWithExec($phpBinary, $script, $host, $port, $workerId, $logFile, $instanceName);
            if ($result['success']) {
                $this->usedMethod = IS_WIN ? 'PowerShell + exec' : 'nohup + exec';
                return $result;
            }
        }
        
        return [
            'success' => false,
            'pid' => 0,
            'error' => __('没有可用的进程创建函数'),
        ];
    }
    
    /**
     * 使用 proc_open 启动进程
     */
    protected function startWithProcOpen(
        string $phpBinary,
        string $script,
        string $host,
        int $port,
        int $workerId,
        string $logFile,
        string $instanceName = 'default'
    ): array
    {
        $command = "{$phpBinary} \"{$script}\" {$host} {$port} {$workerId} {$instanceName}";
        
        $descriptorspec = [
            0 => ['pipe', 'r'],       // stdin
            1 => ['file', $logFile, 'a'],  // stdout -> log
            2 => ['file', $logFile, 'a'],  // stderr -> log
        ];
        
        $process = @\proc_open($command, $descriptorspec, $pipes);
        
        if (!\is_resource($process)) {
            return ['success' => false, 'pid' => 0, 'error' => 'proc_open failed'];
        }
        
        // 关闭 stdin
        if (isset($pipes[0])) {
            \fclose($pipes[0]);
        }
        
        // 获取 PID
        $status = @\proc_get_status($process);
        $pid = $status['pid'] ?? 0;
        
        // 不要 proc_close，让进程继续运行
        // 记录 PID 到文件
        $pidFile = BP . "var/process/pid/worker-{$port}.json";
        $pidDir = \dirname($pidFile);
        if (!\is_dir($pidDir)) {
            @\mkdir($pidDir, 0755, true);
        }
        \file_put_contents($pidFile, \json_encode([
            'pid' => $pid,
            'port' => $port,
            'worker_id' => $workerId,
            'started_at' => \date('Y-m-d H:i:s'),
            'method' => 'proc_open',
        ]));
        
        return ['success' => true, 'pid' => $pid, 'error' => ''];
    }
    
    /**
     * 使用 pcntl_fork 启动进程（仅限 Linux/Mac）
     */
    protected function startWithPcntlFork(
        string $phpBinary,
        string $script,
        string $host,
        int $port,
        int $workerId,
        string $logFile,
        string $instanceName = 'default'
    ): array
    {
        $command = "{$phpBinary} \"{$script}\" {$host} {$port} {$workerId} {$instanceName} > \"{$logFile}\" 2>&1";
        
        $pid = \pcntl_fork();
        
        if ($pid === -1) {
            return ['success' => false, 'pid' => 0, 'error' => 'pcntl_fork failed'];
        }
        
        if ($pid === 0) {
            // 子进程
            if ($this->availableFunctions['posix_setsid']) {
                \posix_setsid(); // 创建新会话
            }
            
            // 执行 Worker
            \exec($command);
            exit(0);
        }
        
        // 父进程
        return ['success' => true, 'pid' => $pid, 'error' => ''];
    }
    
    /**
     * 使用 exec 启动进程（备用方案）
     */
    protected function startWithExec(
        string $phpBinary,
        string $script,
        string $host,
        int $port,
        int $workerId,
        string $logFile,
        string $instanceName = 'default'
    ): array
    {
        if (IS_WIN) {
            // Windows: 创建临时批处理文件启动后台进程
            // 这是最可靠的方式，避免 exec 阻塞问题
            $script = \str_replace('/', '\\', $script);
            $logFile = \str_replace('/', '\\', $logFile);
            $phpBinary = \str_replace('/', '\\', $phpBinary);
            
            // 创建临时批处理文件
            $batFile = BP . "var\\tmp\\start_worker_{$port}.bat";
            $batDir = \dirname($batFile);
            if (!\is_dir($batDir)) {
                @\mkdir($batDir, 0755, true);
            }
            
            // 批处理内容：启动进程后立即退出
            $batContent = "@echo off\r\n";
            $batContent .= "start /MIN \"Worker-{$port}\" \"{$phpBinary}\" \"{$script}\" {$host} {$port} {$workerId} {$instanceName}\r\n";
            $batContent .= "exit\r\n";
            
            \file_put_contents($batFile, $batContent);
            
            // 使用 exec 执行批处理文件（不会阻塞，因为 bat 文件会立即退出）
            @\exec("cmd /c \"{$batFile}\"", $output, $returnCode);
            
            // 清理批处理文件
            @\unlink($batFile);
            
            return ['success' => true, 'pid' => 0, 'error' => ''];
        } else {
            // Linux/Mac: 使用 nohup
            $command = "nohup {$phpBinary} \"{$script}\" {$host} {$port} {$workerId} {$instanceName} > \"{$logFile}\" 2>&1 & echo \$!";
            $output = [];
            \exec($command, $output);
            
            $pid = !empty($output[0]) && \is_numeric($output[0]) ? (int)$output[0] : 0;
            
            if ($pid === 0) {
                return ['success' => false, 'pid' => 0, 'error' => 'nohup failed'];
            }
            
            return ['success' => true, 'pid' => $pid, 'error' => ''];
        }
    }
    
    /**
     * 确保 Worker 脚本存在
     */
    protected function ensureWorkerScript(string $path): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        
        if (\is_file($path)) {
            return;
        }
        
        $script = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程
 * 
 * 用法: php worker.php <host> <port> <worker_id> [--name=xxx]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 8080);
$workerId = (int) ($argv[3] ?? 1);

echo "=== Weline Server Worker #{$workerId} ===\n";
echo "Host: {$host}\n";
echo "Port: {$port}\n";
echo "PID: " . getmypid() . "\n";
echo "Starting...\n";

// 创建 socket
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ]
]);

$socket = @stream_socket_server(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    echo "ERROR: Failed to create socket: [{$errno}] {$errstr}\n";
    exit(1);
}

stream_set_blocking($socket, false);

echo "Listening on {$host}:{$port}\n";
echo "Ready.\n\n";

$connections = [];
$requestCount = 0;

// 事件循环
while (true) {
    $read = array_merge([$socket], $connections);
    $write = [];
    $except = [];
    
    $changed = @stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 新连接
    if (in_array($socket, $read)) {
        $conn = @stream_socket_accept($socket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $connections[(int)$conn] = $conn;
        }
        $key = array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $data = @fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @fclose($conn);
            unset($connections[(int)$conn]);
            continue;
        }
        
        $requestCount++;
        
        // 简单响应
        $body = "Hello Weline Server! Worker #{$workerId}, Port: {$port}, Request #{$requestCount}";
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/plain; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: keep-alive\r\n";
        $response .= "\r\n";
        $response .= $body;
        
        @fwrite($conn, $response);
    }
}
PHP;
        
        \file_put_contents($path, $script);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('多进程模式启动 Weline Server');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:multi-start',
            __('启动多个独立进程以提高并发性能'),
            [
                '-h, --host <ip>' => __('监听地址（默认：127.0.0.1）'),
                '-p, --port <port>' => __('基础端口（默认：8080）'),
                '-c, --count <n>' => __('进程数量（默认：4）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('启动 4 个进程') => 'php bin/w server:multi-start -p 8080 -c 4',
                __('启动 8 个进程') => 'php bin/w server:multi-start -p 8080 -c 8',
            ]
        );
    }
}
