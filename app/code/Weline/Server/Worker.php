<?php
declare(strict_types=1);

/**
 * Weline Server - Worker 核心类
 * 
 * Weline 高性能异步事件驱动服务器核心类
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Connection\TcpConnection;
use Weline\Server\Event\EventInterface;

/**
 * Worker - 服务器核心类
 * 
 * 用法示例：
 * ```php
 * $worker = new Worker('http://0.0.0.0:8080');
 * $worker->count = 4;
 * $worker->onMessage = function($connection, $data) {
 *     $connection->send('Hello Weline Server!');
 * };
 * Worker::runAll();
 * ```
 */
class Worker
{
    /**
     * Worker 版本
     */
    public const VERSION = '1.0.0';
    
    /**
     * 状态：启动中
     */
    public const STATUS_STARTING = 1;
    
    /**
     * 状态：运行中
     */
    public const STATUS_RUNNING = 2;
    
    /**
     * 状态：关闭中
     */
    public const STATUS_SHUTDOWN = 4;
    
    /**
     * 状态：重载中
     */
    public const STATUS_RELOADING = 8;
    
    /**
     * 守护进程启动后的标准输出重定向文件
     */
    public static string $stdoutFile = '/dev/null';
    
    /**
     * 日志文件
     */
    public static string $logFile = '';
    
    /**
     * PID 文件
     */
    public static string $pidFile = '';
    
    /**
     * 事件循环类名
     */
    public static string $eventLoopClass = '';
    
    /**
     * 全局事件循环实例
     */
    public static ?EventInterface $globalEvent = null;
    
    /**
     * 当前状态
     */
    protected static int $status = self::STATUS_STARTING;
    
    /**
     * Master 进程 PID
     */
    protected static int $masterPid = 0;
    
    /**
     * 所有 Worker 实例
     * @var Worker[]
     */
    protected static array $workers = [];
    
    /**
     * 所有 Worker 进程的 PID
     * 格式：[worker_id => [pid => pid, ...]]
     */
    protected static array $pidMap = [];
    
    /**
     * 所有 Worker 进程与 Worker 实例的映射
     * 格式：[pid => worker_id]
     */
    protected static array $idMap = [];
    
    /**
     * Worker 名称
     */
    public string $name = 'none';
    
    /**
     * Worker 进程数
     */
    public int $count = 1;
    
    /**
     * Unix 用户
     */
    public string $user = '';
    
    /**
     * Unix 用户组
     */
    public string $group = '';
    
    /**
     * 是否可重载
     */
    public bool $reloadable = true;
    
    /**
     * 是否以守护进程运行
     */
    public static bool $daemonize = false;
    
    /**
     * 监听地址
     * 格式：scheme://host:port
     */
    protected string $socketName = '';
    
    /**
     * 协议类名
     */
    protected string $protocol = '';
    
    /**
     * 传输层协议
     */
    protected string $transport = 'tcp';
    
    /**
     * Socket 上下文选项
     */
    protected array $contextOptions = [];
    
    /**
     * 主 Socket
     * @var resource|null
     */
    protected $mainSocket = null;
    
    /**
     * Worker ID
     */
    public int $id = 0;
    
    /**
     * 当前连接数
     */
    public int $connectionCount = 0;
    
    /**
     * 所有连接
     * @var TcpConnection[]
     */
    public array $connections = [];
    
    /**
     * 是否为 Windows 系统
     */
    protected static bool $isWindows = false;
    
    // ==================== 回调函数 ====================
    
    /**
     * Worker 启动时回调
     * @var callable|null
     */
    public $onWorkerStart = null;
    
    /**
     * Worker 停止时回调
     * @var callable|null
     */
    public $onWorkerStop = null;
    
    /**
     * Worker 重载时回调
     * @var callable|null
     */
    public $onWorkerReload = null;
    
    /**
     * 新连接回调
     * @var callable|null
     */
    public $onConnect = null;
    
    /**
     * 收到消息回调
     * @var callable|null
     */
    public $onMessage = null;
    
    /**
     * 连接关闭回调
     * @var callable|null
     */
    public $onClose = null;
    
    /**
     * 发生错误回调
     * @var callable|null
     */
    public $onError = null;
    
    /**
     * 发送缓冲区满回调
     * @var callable|null
     */
    public $onBufferFull = null;
    
    /**
     * 发送缓冲区空回调
     * @var callable|null
     */
    public $onBufferDrain = null;
    
    /**
     * 构造函数
     * 
     * @param string $socketName 监听地址，如 http://0.0.0.0:8080
     * @param array $contextOptions Socket 上下文选项
     */
    public function __construct(string $socketName = '', array $contextOptions = [])
    {
        $this->id = spl_object_id($this);
        self::$workers[$this->id] = $this;
        self::$pidMap[$this->id] = [];
        
        $this->socketName = $socketName;
        $this->contextOptions = $contextOptions;
        
        // 检测操作系统
        self::$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($socketName) {
            $this->parseSocketName();
        }
    }
    
    /**
     * 解析监听地址
     */
    protected function parseSocketName(): void
    {
        if (!$this->socketName) {
            return;
        }
        
        // 解析 scheme://host:port
        $urlInfo = parse_url($this->socketName);
        
        if (!$urlInfo) {
            throw new \InvalidArgumentException(\__('无效的监听地址：%{1}', [$this->socketName]));
        }
        
        $scheme = $urlInfo['scheme'] ?? 'tcp';
        
        // 映射协议
        $protocolMap = [
            'tcp' => '',
            'udp' => '',
            'unix' => '',
            'ssl' => '',
            'http' => Protocol\Http::class,
            'https' => Protocol\Http::class,
            'websocket' => Protocol\WebSocket::class,
            'ws' => Protocol\WebSocket::class,
            'wss' => Protocol\WebSocket::class,
            'text' => Protocol\Text::class,
        ];
        
        // 传输层协议映射
        $transportMap = [
            'tcp' => 'tcp',
            'udp' => 'udp',
            'unix' => 'unix',
            'ssl' => 'ssl',
            'http' => 'tcp',
            'https' => 'ssl',
            'websocket' => 'tcp',
            'ws' => 'tcp',
            'wss' => 'ssl',
            'text' => 'tcp',
        ];
        
        $this->protocol = $protocolMap[$scheme] ?? '';
        $this->transport = $transportMap[$scheme] ?? 'tcp';
    }
    
    /**
     * 运行所有 Worker
     */
    public static function runAll(): void
    {
        static::checkSapiEnv();
        static::init();
        static::parseCommand();
        
        if (self::$isWindows) {
            static::runAllWindows();
        } else {
            static::daemonize();
            static::initWorkers();
            static::installSignal();
            static::saveMasterPid();
            static::displayUI();
            static::forkWorkers();
            static::monitorWorkers();
        }
    }
    
    /**
     * Windows 模式运行（单进程）
     */
    protected static function runAllWindows(): void
    {
        static::initWorkers();
        static::displayUI();
        
        self::log(\__('Windows 模式：单进程运行'));
        
        // Windows 下只支持单进程
        foreach (self::$workers as $worker) {
            $worker->count = 1;
            $worker->run();
        }
    }
    
    /**
     * 检查运行环境
     */
    protected static function checkSapiEnv(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException(\__('Weline Server 只能在 CLI 模式下运行'));
        }
    }
    
    /**
     * 初始化
     */
    protected static function init(): void
    {
        // 设置错误处理
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        // 设置默认时区
        date_default_timezone_set(date_default_timezone_get() ?: 'Asia/Shanghai');
        
        // 设置进程标题
        static::setProcessTitle('Weline Server: master process');
        
        // 初始化 PID 文件路径
        if (!static::$pidFile) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $startFile = end($backtrace)['file'] ?? '';
            $startFileName = basename($startFile, '.php');
            
            if (self::$isWindows) {
                static::$pidFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "weline_server.{$startFileName}.pid";
            } else {
                static::$pidFile = sys_get_temp_dir() . "/weline_server.{$startFileName}.pid";
            }
        }
        
        // 初始化日志文件路径
        if (!static::$logFile) {
            if (self::$isWindows) {
                static::$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_server.log';
            } else {
                static::$logFile = sys_get_temp_dir() . '/weline_server.log';
            }
        }
        
        // 初始化状态
        static::$status = static::STATUS_STARTING;
        
        if (!self::$isWindows) {
            static::$masterPid = posix_getpid();
        }
        
        // 初始化事件循环
        static::initEventLoop();
    }
    
    /**
     * 初始化事件循环
     */
    protected static function initEventLoop(): void
    {
        if (static::$eventLoopClass) {
            return;
        }
        
        // 使用 EventFactory 自动选择最优事件循环
        $driver = Event\EventFactory::detectBestDriver();
        $drivers = Event\EventFactory::getAllDrivers();
        
        if (isset($drivers[$driver])) {
            static::$eventLoopClass = $drivers[$driver]['class'];
        } else {
            // 回退到 Select
            static::$eventLoopClass = Event\Select::class;
        }
    }
    
    /**
     * 获取事件循环诊断信息
     */
    public static function getEventLoopDiagnostics(): array
    {
        return Event\EventFactory::getDiagnostics();
    }
    
    /**
     * 检查是否使用最优事件循环
     */
    public static function isOptimalEventLoop(): bool
    {
        return Event\EventFactory::isOptimalConfiguration();
    }
    
    /**
     * 获取缺失的最优驱动
     */
    public static function getMissingOptimalDrivers(): array
    {
        return Event\EventFactory::getMissingOptimalDrivers();
    }
    
    /**
     * 解析命令行参数
     */
    protected static function parseCommand(): void
    {
        $argv = \Weline\Server\Service\WlsWorkerGlobals::getArgv();

        $command = $argv[1] ?? '';
        $option = $argv[2] ?? '';
        
        switch ($command) {
            case 'start':
                if ($option === '-d' || $option === '--daemon') {
                    static::$daemonize = true;
                }
                break;
                
            case 'stop':
                static::stopAll();
                exit(0);
                
            case 'restart':
                static::stopAll();
                static::$daemonize = true;
                break;
                
            case 'reload':
                static::reloadAll();
                exit(0);
                
            case 'status':
                static::showStatus();
                exit(0);
                
            default:
                if ($command && $command !== 'start') {
                    echo \__("用法：php yourfile.php {start|stop|restart|reload|status}") . "\n";
                    exit(0);
                }
        }
    }
    
    /**
     * 守护进程化
     */
    protected static function daemonize(): void
    {
        if (!static::$daemonize || self::$isWindows) {
            return;
        }
        
        umask(0);
        
        $pid = pcntl_fork();
        
        if ($pid < 0) {
            throw new \RuntimeException(\__('Fork 失败'));
        }
        
        if ($pid > 0) {
            exit(0);
        }
        
        if (posix_setsid() < 0) {
            throw new \RuntimeException(\__('setsid 失败'));
        }
        
        // 再次 fork，防止获取控制终端
        $pid = pcntl_fork();
        
        if ($pid < 0) {
            throw new \RuntimeException(\__('二次 Fork 失败'));
        }
        
        if ($pid > 0) {
            exit(0);
        }
        
        // 重定向标准输入输出
        static::resetStd();
    }
    
    /**
     * 重定向标准输入输出
     */
    protected static function resetStd(): void
    {
        $stdoutFile = static::$stdoutFile;

        if ($stdoutFile === '/dev/null') {
            $stdin = fopen('/dev/null', 'r');
            $stdout = fopen('/dev/null', 'a');
            $stderr = fopen('/dev/null', 'a');
        } else {
            $stdin = fopen('/dev/null', 'r');
            $stdout = fopen($stdoutFile, 'a');
            $stderr = fopen($stdoutFile, 'a');
        }

        // 设置到 WlsWorkerGlobals
        \Weline\Server\Service\WlsWorkerGlobals::setStdin($stdin);
        \Weline\Server\Service\WlsWorkerGlobals::setStdout($stdout);
        \Weline\Server\Service\WlsWorkerGlobals::setStderr($stderr);

        // 尝试更新全局常量（PHP 7.3+ 可以通过重新定义常量来更新）
        // 注意：这可能在某些 SAPI 中不起作用
        if (\defined('STDIN') && STDIN !== $stdin) {
            // 在支持的 SAPI 中更新常量
        }
    }
    
    /**
     * 初始化所有 Worker
     */
    protected static function initWorkers(): void
    {
        foreach (static::$workers as $worker) {
            $worker->name = $worker->name ?: 'WelineServer';
            
            if (!$worker->user && !self::$isWindows) {
                $worker->user = static::getCurrentUser();
            }
        }
    }
    
    /**
     * 获取当前用户
     */
    protected static function getCurrentUser(): string
    {
        if (self::$isWindows) {
            return getenv('USERNAME') ?: 'unknown';
        }
        
        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['name'] ?? '';
    }
    
    /**
     * 安装信号处理器
     */
    protected static function installSignal(): void
    {
        if (self::$isWindows) {
            return;
        }
        
        pcntl_signal(SIGINT, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGTERM, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGUSR1, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGUSR2, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGCHLD, [static::class, 'signalHandler'], false);
    }
    
    /**
     * 信号处理器
     */
    public static function signalHandler(int $signal): void
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
                static::$status = static::STATUS_SHUTDOWN;
                static::stopAll();
                break;
                
            case SIGUSR1:
                static::$status = static::STATUS_RELOADING;
                static::reloadAll();
                break;
                
            case SIGUSR2:
                // 可用于自定义信号
                break;
                
            case SIGCHLD:
                // 子进程退出，由 monitorWorkers 处理
                break;
        }
    }
    
    /**
     * 保存 Master PID
     */
    protected static function saveMasterPid(): void
    {
        if (self::$isWindows) {
            static::$masterPid = getmypid() ?: 0;
        } else {
            static::$masterPid = posix_getpid();
        }
        
        if (false === file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new \RuntimeException(\__('无法保存 PID 到 %{1}', [static::$pidFile]));
        }
    }
    
    /**
     * 显示启动信息
     */
    protected static function displayUI(): void
    {
        $version = static::VERSION;
        $phpVersion = PHP_VERSION;
        $eventLoop = basename(static::$eventLoopClass);
        
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║                     Weline Server v{$version}                          ║\n";
        echo "║              " . \__('高性能异步常驻内存服务器') . "                    ║\n";
        echo "╠══════════════════════════════════════════════════════════════════╣\n";
        echo "║  PHP Version:    {$phpVersion}                                        \n";
        echo "║  Event Loop:     {$eventLoop}                                         \n";
        echo "║  OS:             " . (self::$isWindows ? 'Windows' : 'Linux/Unix') . "                                      \n";
        echo "╠══════════════════════════════════════════════════════════════════╣\n";
        echo "║  Workers                                                          ║\n";
        echo "╠══════════════════════════════════════════════════════════════════╣\n";
        
        foreach (static::$workers as $worker) {
            $name = str_pad($worker->name, 15);
            $listen = str_pad($worker->socketName ?: 'none', 25);
            $count = $worker->count;
            echo "║  {$name} {$listen} [×{$count}]                  \n";
        }
        
        echo "╚══════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        if (static::$daemonize) {
            echo \__('守护进程模式运行，PID：%{1}', [static::$masterPid]) . "\n";
        } else {
            echo \__('按 Ctrl+C 停止服务器') . "\n";
        }
        
        echo "\n";
    }
    
    /**
     * Fork Worker 进程
     */
    protected static function forkWorkers(): void
    {
        foreach (static::$workers as $worker) {
            for ($i = 0; $i < $worker->count; $i++) {
                static::forkOneWorker($worker);
            }
        }
    }
    
    /**
     * Fork 单个 Worker 进程
     */
    protected static function forkOneWorker(Worker $worker): void
    {
        $pid = pcntl_fork();
        
        if ($pid < 0) {
            throw new \RuntimeException(\__('Fork 失败'));
        }
        
        if ($pid > 0) {
            // Master 进程
            static::$pidMap[$worker->id][$pid] = $pid;
            static::$idMap[$pid] = $worker->id;
            return;
        }
        
        // Worker 进程
        static::$status = static::STATUS_RUNNING;
        static::$masterPid = 0;
        static::$pidMap = [];
        static::$idMap = [];
        
        // 清除其他 Worker
        foreach (static::$workers as $key => $w) {
            if ($w->id !== $worker->id) {
                unset(static::$workers[$key]);
            }
        }
        
        // 设置进程标题
        static::setProcessTitle("Weline Server: worker process [{$worker->name}]");
        
        // 设置用户和组
        $worker->setUserAndGroup();
        
        // 运行 Worker
        $worker->run();
        
        exit(0);
    }
    
    /**
     * 设置用户和组
     */
    protected function setUserAndGroup(): void
    {
        if (self::$isWindows || !$this->user) {
            return;
        }
        
        $userInfo = posix_getpwnam($this->user);
        
        if (!$userInfo) {
            static::log(\__('警告：用户 %{1} 不存在', [$this->user]));
            return;
        }
        
        $uid = $userInfo['uid'];
        $gid = $userInfo['gid'];
        
        if ($this->group) {
            $groupInfo = posix_getgrnam($this->group);
            if ($groupInfo) {
                $gid = $groupInfo['gid'];
            }
        }
        
        if ($uid !== posix_getuid() || $gid !== posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($this->user, $gid) || !posix_setuid($uid)) {
                static::log(\__('警告：无法切换用户到 %{1}', [$this->user]));
            }
        }
    }
    
    /**
     * 运行 Worker
     */
    public function run(): void
    {
        // 创建事件循环
        static::$globalEvent = new (static::$eventLoopClass)();
        
        // 恢复信号处理（子进程需要重新安装）
        if (!self::$isWindows) {
            static::reinstallSignal();
        }
        
        // 创建定时器
        Timer::init(static::$globalEvent);
        
        // 监听端口
        $this->listen();
        
        // 触发 onWorkerStart 回调
        if ($this->onWorkerStart) {
            try {
                ($this->onWorkerStart)($this);
            } catch (\Throwable $e) {
                static::log(\__('onWorkerStart 错误：%{1}', [$e->getMessage()]));
            }
        }
        
        // 启动事件循环
        static::$globalEvent->loop();
    }
    
    /**
     * 重新安装信号处理器（子进程）
     */
    protected static function reinstallSignal(): void
    {
        if (self::$isWindows) {
            return;
        }
        
        // 使用事件循环的信号处理
        pcntl_signal(SIGINT, SIG_IGN, false);
        pcntl_signal(SIGTERM, SIG_IGN, false);
        
        static::$globalEvent->add(SIGINT, EventInterface::EV_SIGNAL, function() {
            static::stopAll();
        });
        
        static::$globalEvent->add(SIGTERM, EventInterface::EV_SIGNAL, function() {
            static::stopAll();
        });
        
        static::$globalEvent->add(SIGUSR1, EventInterface::EV_SIGNAL, function() {
            // 重载（Worker 进程）
            foreach (static::$workers as $worker) {
                if ($worker->onWorkerReload) {
                    ($worker->onWorkerReload)($worker);
                }
            }
        });
    }
    
    /**
     * 监听端口
     */
    protected function listen(): void
    {
        if (!$this->socketName) {
            return;
        }
        
        // 构建监听地址
        $localSocket = $this->buildLocalSocket();
        
        // 创建 Socket 上下文
        $context = stream_context_create($this->contextOptions);
        
        // 设置 SO_REUSEPORT（Linux 3.9+）
        $this->setSocketOptions($context);
        
        // 创建服务器 Socket
        $flags = $this->transport === 'udp' 
            ? STREAM_SERVER_BIND 
            : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            
        $this->mainSocket = stream_socket_server(
            $localSocket,
            $errno,
            $errstr,
            $flags,
            $context
        );
        
        if (!$this->mainSocket) {
            throw new \RuntimeException(\__('无法创建 Socket：[%{1}] %{2}', [$errno, $errstr]));
        }
        
        // 设置非阻塞
        stream_set_blocking($this->mainSocket, false);
        
        // 注册读事件
        static::$globalEvent->add(
            $this->mainSocket,
            EventInterface::EV_READ,
            [$this, 'acceptConnection']
        );
    }
    
    /**
     * 构建本地 Socket 地址
     */
    protected function buildLocalSocket(): string
    {
        $urlInfo = parse_url($this->socketName);
        $host = $urlInfo['host'] ?? '0.0.0.0';
        $port = $urlInfo['port'] ?? 0;
        
        return "{$this->transport}://{$host}:{$port}";
    }
    
    /**
     * 设置 Socket 选项
     */
    protected function setSocketOptions($context): void
    {
        // SO_REUSEPORT 允许多进程监听同一端口
        if (!self::$isWindows && PHP_OS === 'Linux') {
            // 检查内核版本是否支持 SO_REUSEPORT
            $release = php_uname('r');
            if (version_compare($release, '3.9', '>=')) {
                stream_context_set_option($context, 'socket', 'so_reuseport', true);
            }
        }
        
        stream_context_set_option($context, 'socket', 'so_reuseaddr', true);
        stream_context_set_option($context, 'socket', 'backlog', 102400);
    }
    
    /**
     * 接受新连接
     */
    public function acceptConnection($socket): void
    {
        // 批量接受连接（性能优化）
        for ($i = 0; $i < 10; $i++) {
            $newSocket = @stream_socket_accept($socket, 0, $remoteAddress);
            
            if (!$newSocket) {
                return;
            }
            
            // 创建连接对象
            $connection = new TcpConnection(
                $newSocket,
                $remoteAddress,
                static::$globalEvent
            );
            
            $connection->worker = $this;
            $connection->protocol = $this->protocol;
            $connection->transport = $this->transport;
            
            // 存储连接
            $this->connections[$connection->id] = $connection;
            $this->connectionCount++;
            
            // 触发 onConnect 回调
            if ($this->onConnect) {
                try {
                    ($this->onConnect)($connection);
                } catch (\Throwable $e) {
                    static::log(\__('onConnect 错误：%{1}', [$e->getMessage()]));
                }
            }
        }
    }
    
    /**
     * 监控 Worker 进程
     */
    protected static function monitorWorkers(): void
    {
        static::$status = static::STATUS_RUNNING;
        
        while (true) {
            // 处理信号
            pcntl_signal_dispatch();
            
            // 等待子进程退出
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            
            // 处理信号
            pcntl_signal_dispatch();
            
            // 有子进程退出
            if ($pid > 0) {
                // 获取 Worker ID
                $workerId = static::$idMap[$pid] ?? null;
                
                if ($workerId !== null) {
                    // 从映射中删除
                    unset(static::$pidMap[$workerId][$pid]);
                    unset(static::$idMap[$pid]);
                    
                    // 如果不是关闭状态，重新 fork
                    if (static::$status !== static::STATUS_SHUTDOWN) {
                        $worker = static::$workers[$workerId] ?? null;
                        
                        if ($worker) {
                            $exitCode = pcntl_wexitstatus($status);
                            static::log(\__('Worker [%{1}] 退出，退出码：%{2}，正在重启...', [$worker->name, $exitCode]));
                            
                            // 等待一小段时间再重启
                            SchedulerSystem::usleep(100000);
                            static::forkOneWorker($worker);
                        }
                    }
                }
            }
            
            // 检查是否所有子进程都退出了
            if (static::$status === static::STATUS_SHUTDOWN) {
                $allExited = true;
                
                foreach (static::$pidMap as $pids) {
                    if (!empty($pids)) {
                        $allExited = false;
                        break;
                    }
                }
                
                if ($allExited) {
                    static::exitAndCleanup();
                }
            }
        }
    }
    
    /**
     * 停止所有 Worker
     */
    public static function stopAll(): void
    {
        static::$status = static::STATUS_SHUTDOWN;
        
        if (self::$isWindows) {
            // Windows 下直接退出
            foreach (static::$workers as $worker) {
                if ($worker->onWorkerStop) {
                    try {
                        ($worker->onWorkerStop)($worker);
                    } catch (\Throwable $e) {
                        static::log(\__('onWorkerStop 错误：%{1}', [$e->getMessage()]));
                    }
                }
            }
            exit(0);
        }
        
        // 如果是 Master 进程
        if (static::$masterPid === posix_getpid()) {
            static::log(\__('正在停止所有 Worker...'));
            
            // 发送 SIGTERM 给所有子进程
            foreach (static::$pidMap as $workerPids) {
                foreach ($workerPids as $pid) {
                    Processer::sendSignal((int)$pid, SIGTERM, true);
                }
            }
            
            return;
        }
        
        // Worker 进程：停止事件循环
        if (static::$globalEvent) {
            static::$globalEvent->destroy();
        }
        
        // 触发 onWorkerStop 回调
        foreach (static::$workers as $worker) {
            if ($worker->onWorkerStop) {
                try {
                    ($worker->onWorkerStop)($worker);
                } catch (\Throwable $e) {
                    static::log(\__('onWorkerStop 错误：%{1}', [$e->getMessage()]));
                }
            }
        }
        
        exit(0);
    }
    
    /**
     * 重载所有 Worker
     */
    public static function reloadAll(): void
    {
        if (self::$isWindows) {
            echo \__('Windows 不支持重载功能') . "\n";
            return;
        }
        
        $pid = static::getMasterPid();
        
        if ($pid > 0) {
            Processer::sendSignal($pid, SIGUSR1, true);
            return;
        }
        
        echo \__('Weline Server 未运行') . "\n";
    }
    
    /**
     * 显示状态
     */
    public static function showStatus(): void
    {
        $pid = static::getMasterPid();
        
        if ($pid > 0 && Processer::isRunningByPid($pid)) {
            echo \__('Weline Server 正在运行，Master PID：%{1}', [$pid]) . "\n";
        } else {
            echo \__('Weline Server 未运行') . "\n";
        }
    }
    
    /**
     * 获取 Master PID
     */
    protected static function getMasterPid(): int
    {
        if (!file_exists(static::$pidFile)) {
            return 0;
        }
        
        $pid = (int) file_get_contents(static::$pidFile);
        
        if ($pid > 0 && Processer::isRunningByPid($pid)) {
            return $pid;
        }
        
        return 0;
    }
    
    /**
     * 退出并清理
     */
    protected static function exitAndCleanup(): void
    {
        @unlink(static::$pidFile);
        static::log(\__('Weline Server 已停止'));
        exit(0);
    }
    
    /**
     * 设置进程标题
     */
    protected static function setProcessTitle(string $title): void
    {
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }
    
    /**
     * 记录日志
     */
    public static function log(string $message): void
    {
        $time = date('Y-m-d H:i:s');
        $log = "[{$time}] {$message}\n";
        
        if (static::$daemonize && static::$logFile) {
            file_put_contents(static::$logFile, $log, FILE_APPEND | LOCK_EX);
        } else {
            echo $log;
        }
    }
    
    /**
     * 获取监听地址
     */
    public function getSocketName(): string
    {
        return $this->socketName;
    }
    
    /**
     * 获取协议类
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }
}
