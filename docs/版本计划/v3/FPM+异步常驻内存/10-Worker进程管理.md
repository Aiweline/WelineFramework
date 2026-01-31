# 10 - WeAsync 异步引擎

> **优先级**: ⭐⭐⭐⭐⭐  
> **依赖**: 02-运行时抽象层  
> **预计工作量**: 7-10 天  
> **参考实现**: 完全照搬 Workerman 开源代码（MIT 协议）

---

## 0. 核心决策

**WeAsync 完全照搬 Workerman 开源代码**，改名后作为框架自研的异步引擎。

### 照搬策略

```
Workerman 源码                    →  WeAsync 模块
├── Workerman/Worker.php          →  Weline/WeAsync/Worker.php
├── Workerman/Connection/         →  Weline/WeAsync/Connection/
├── Workerman/Events/             →  Weline/WeAsync/Event/
├── Workerman/Protocols/          →  Weline/WeAsync/Protocol/
└── Workerman/Lib/Timer.php       →  Weline/WeAsync/Timer.php
```

### 优化增强（超越 Workerman，全部实现）

| 优化项 | 说明 | 实现文件 |
|--------|------|----------|
| **Windows 兼容** | 支持 Windows 多进程 | `WindowsCompat.php` |
| **HTTP/2 支持** | 原生支持 HTTP/2 协议 | `Protocol/Http2.php` |
| **限流中间件** | 可选内置限流 | `Middleware/RateLimiter.php` |
| **热重载** | 开发模式自动重载 | `HotReload.php` |

详细实现见 [14-WeAsync优化清单](14-WeAsync优化清单.md)

---

## 1. 概述

**WeAsync** 是 Weline Framework 自研的高性能异步引擎，完全照搬 Workerman 核心代码实现，具备同等性能水平。

### 1.1 设计目标

| 目标 | 描述 |
|------|------|
| **高性能** | 达到 Workerman 同等性能水平（50,000+ QPS） |
| **自主可控** | 完全照搬后可自行维护和优化 |
| **多协议** | HTTP/HTTP2/WebSocket/TCP/UDP |
| **多进程** | 利用多核 CPU（包括 Windows） |
| **事件驱动** | 支持 event/ev/stream_select |

### 1.2 性能目标

| 后端 | 依赖 | Hello World QPS |
|------|------|-----------------|
| `EventDriver` | event 扩展 | 50,000+ |
| `EvDriver` | ev 扩展 | 55,000+ |
| `SelectDriver` | 无（纯 PHP） | 8,000-12,000 |

### 1.3 完整模块结构

```
app/code/Weline/WeAsync/
│
├── # ========== 照搬 Workerman 核心（15个文件）==========
├── Worker.php                        # 核心 Worker 类
├── Timer.php                         # 定时器
├── Autoloader.php                    # 自动加载
│
├── Connection/                       # 连接管理
│   ├── ConnectionInterface.php       # 连接接口
│   ├── TcpConnection.php             # TCP 连接
│   ├── UdpConnection.php             # UDP 连接
│   └── AsyncTcpConnection.php        # 异步 TCP 客户端
│
├── Event/                            # 事件循环
│   ├── EventInterface.php            # 事件接口
│   ├── Event.php                     # event 扩展驱动
│   ├── Ev.php                        # ev 扩展驱动
│   └── Select.php                    # stream_select 驱动
│
├── Protocol/                         # 协议实现
│   ├── ProtocolInterface.php         # 协议接口
│   ├── Http.php                      # HTTP/1.1 协议
│   ├── WebSocket.php                 # WebSocket 协议
│   ├── Text.php                      # 文本协议
│   └── Frame.php                     # 帧协议
│
├── Lib/
│   └── Timer.php                     # 定时器实现
│
├── # ========== 优化新增（12个文件）==========
├── WindowsCompat.php                 # Windows 多进程兼容
├── HotReload.php                     # 热重载
│
├── Protocol/
│   ├── Http2.php                     # HTTP/2 协议
│   └── Http2/
│       ├── HpackEncoder.php          # HPACK 头部压缩编码
│       ├── HpackDecoder.php          # HPACK 头部压缩解码
│       ├── Stream.php                # HTTP/2 流管理
│       └── Frame.php                 # HTTP/2 帧处理
│
└── Middleware/                       # 中间件
    ├── MiddlewareInterface.php       # 中间件接口
    ├── RateLimiter.php               # 限流中间件
    ├── Cors.php                      # CORS 中间件
    └── Storage/
        ├── StorageInterface.php      # 存储接口
        ├── MemoryStorage.php         # 内存存储
        └── RedisStorage.php          # Redis 存储
```

### 1.4 实现统计

| 类型 | 文件数 | 代码行数(预估) |
|------|--------|---------------|
| 照搬 Workerman | 15 | ~5,000 |
| 优化新增 | 12 | ~3,000 |
| **总计** | **27** | **~8,000** |

---

## 2. 核心类：Worker

照着 Workerman 的 Worker.php 实现，这是整个引擎的核心。

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync;

use Weline\WeAsync\Connection\TcpConnection;
use Weline\WeAsync\Event\EventInterface;

/**
 * WeAsync Worker
 * 
 * 高性能异步服务器核心类
 * 
 * 用法：
 * $worker = new Worker('http://0.0.0.0:8080');
 * $worker->count = 4;
 * $worker->onMessage = function($connection, $data) {
 *     $connection->send('Hello');
 * };
 * Worker::runAll();
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
            throw new \InvalidArgumentException("Invalid socket name: {$this->socketName}");
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
            'frame' => Protocol\Frame::class,
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
            'frame' => 'tcp',
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
        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::displayUI();
        static::forkWorkers();
        static::monitorWorkers();
    }
    
    /**
     * 检查运行环境
     */
    protected static function checkSapiEnv(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('WeAsync only runs in CLI mode');
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
        static::setProcessTitle('WeAsync: master process');
        
        // 初始化 PID 文件路径
        if (!static::$pidFile) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $startFile = end($backtrace)['file'] ?? '';
            $startFileName = basename($startFile, '.php');
            static::$pidFile = sys_get_temp_dir() . "/weasync.{$startFileName}.pid";
        }
        
        // 初始化日志文件路径
        if (!static::$logFile) {
            static::$logFile = sys_get_temp_dir() . '/weasync.log';
        }
        
        // 初始化状态
        static::$status = static::STATUS_STARTING;
        static::$masterPid = posix_getpid();
        
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
        
        // 自动选择最优事件循环
        $eventLoops = [
            'Weline\\WeAsync\\Event\\Ev' => extension_loaded('ev'),
            'Weline\\WeAsync\\Event\\Event' => extension_loaded('event'),
            'Weline\\WeAsync\\Event\\Select' => true,
        ];
        
        foreach ($eventLoops as $class => $available) {
            if ($available) {
                static::$eventLoopClass = $class;
                break;
            }
        }
    }
    
    /**
     * 解析命令行参数
     */
    protected static function parseCommand(): void
    {
        global $argv;
        
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
                break;
                
            case 'restart':
                static::stopAll();
                static::$daemonize = true;
                break;
                
            case 'reload':
                static::reloadAll();
                break;
                
            case 'status':
                static::showStatus();
                break;
                
            default:
                if ($command && $command !== 'start') {
                    echo "Usage: php yourfile.php {start|stop|restart|reload|status}\n";
                    exit(0);
                }
        }
    }
    
    /**
     * 守护进程化
     */
    protected static function daemonize(): void
    {
        if (!static::$daemonize) {
            return;
        }
        
        umask(0);
        
        $pid = pcntl_fork();
        
        if ($pid < 0) {
            throw new \RuntimeException('Fork failed');
        }
        
        if ($pid > 0) {
            exit(0);
        }
        
        if (posix_setsid() < 0) {
            throw new \RuntimeException('setsid failed');
        }
        
        // 再次 fork，防止获取控制终端
        $pid = pcntl_fork();
        
        if ($pid < 0) {
            throw new \RuntimeException('Second fork failed');
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
        global $STDIN, $STDOUT, $STDERR;
        
        $stdoutFile = static::$stdoutFile;
        
        if ($stdoutFile === '/dev/null') {
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            
            $STDIN = fopen('/dev/null', 'r');
            $STDOUT = fopen('/dev/null', 'a');
            $STDERR = fopen('/dev/null', 'a');
        } else {
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            
            $STDIN = fopen('/dev/null', 'r');
            $STDOUT = fopen($stdoutFile, 'a');
            $STDERR = fopen($stdoutFile, 'a');
        }
    }
    
    /**
     * 初始化所有 Worker
     */
    protected static function initWorkers(): void
    {
        foreach (static::$workers as $worker) {
            $worker->name = $worker->name ?: 'WeAsync';
            
            if (!$worker->user) {
                $worker->user = static::getCurrentUser();
            }
        }
    }
    
    /**
     * 获取当前用户
     */
    protected static function getCurrentUser(): string
    {
        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['name'] ?? '';
    }
    
    /**
     * 安装信号处理器
     */
    protected static function installSignal(): void
    {
        pcntl_signal(SIGINT, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGTERM, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGUSR1, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGUSR2, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGCHLD, [static::class, 'signalHandler'], false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
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
        static::$masterPid = posix_getpid();
        
        if (false === file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new \RuntimeException("Cannot save pid to " . static::$pidFile);
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
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                     WeAsync v{$version}                           ║\n";
        echo "║              High-Performance Async Engine                    ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  PHP Version:    {$phpVersion}                                    \n";
        echo "║  Event Loop:     {$eventLoop}                                     \n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Workers                                                      ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        
        foreach (static::$workers as $worker) {
            $name = str_pad($worker->name, 15);
            $listen = str_pad($worker->socketName ?: 'none', 25);
            $count = $worker->count;
            echo "║  {$name} {$listen} [×{$count}]                \n";
        }
        
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        if (static::$daemonize) {
            echo "Running in daemon mode. PID: " . static::$masterPid . "\n";
        } else {
            echo "Press Ctrl+C to stop.\n";
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
            throw new \RuntimeException('Fork failed');
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
        static::setProcessTitle("WeAsync: worker process [{$worker->name}]");
        
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
        if (!$this->user) {
            return;
        }
        
        $userInfo = posix_getpwnam($this->user);
        
        if (!$userInfo) {
            static::log("Warning: User {$this->user} not found");
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
                static::log("Warning: Cannot change user to {$this->user}");
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
        static::reinstallSignal();
        
        // 创建定时器
        Timer::init(static::$globalEvent);
        
        // 监听端口
        $this->listen();
        
        // 触发 onWorkerStart 回调
        if ($this->onWorkerStart) {
            try {
                ($this->onWorkerStart)($this);
            } catch (\Throwable $e) {
                static::log("onWorkerStart error: " . $e->getMessage());
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
            throw new \RuntimeException("Cannot create socket: [{$errno}] {$errstr}");
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
        if (PHP_OS === 'Linux') {
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
                    static::log("onConnect error: " . $e->getMessage());
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
                            static::log("Worker [{$worker->name}] exit with code {$exitCode}, restarting...");
                            
                            // 等待一小段时间再重启
                            usleep(100000);
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
        
        // 如果是 Master 进程
        if (static::$masterPid === posix_getpid()) {
            static::log("Stopping all workers...");
            
            // 发送 SIGTERM 给所有子进程
            foreach (static::$pidMap as $workerPids) {
                foreach ($workerPids as $pid) {
                    posix_kill($pid, SIGTERM);
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
                    static::log("onWorkerStop error: " . $e->getMessage());
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
        $pid = static::getMasterPid();
        
        if ($pid > 0) {
            posix_kill($pid, SIGUSR1);
            exit(0);
        }
        
        echo "WeAsync is not running.\n";
        exit(1);
    }
    
    /**
     * 显示状态
     */
    public static function showStatus(): void
    {
        $pid = static::getMasterPid();
        
        if ($pid > 0 && posix_kill($pid, 0)) {
            echo "WeAsync is running. Master PID: {$pid}\n";
        } else {
            echo "WeAsync is not running.\n";
        }
        
        exit(0);
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
        
        if ($pid > 0 && posix_kill($pid, 0)) {
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
        static::log("WeAsync stopped.");
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
}
```

---

## 3. TcpConnection（连接管理）

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync\Connection;

use Weline\WeAsync\Worker;
use Weline\WeAsync\Event\EventInterface;

/**
 * TCP 连接
 * 
 * 管理单个 TCP 连接的读写和状态
 */
class TcpConnection implements ConnectionInterface
{
    /**
     * 连接状态：建立中
     */
    public const STATUS_CONNECTING = 1;
    
    /**
     * 连接状态：已建立
     */
    public const STATUS_ESTABLISHED = 2;
    
    /**
     * 连接状态：关闭中
     */
    public const STATUS_CLOSING = 4;
    
    /**
     * 连接状态：已关闭
     */
    public const STATUS_CLOSED = 8;
    
    /**
     * 读取缓冲区大小（64KB）
     */
    public const READ_BUFFER_SIZE = 65535;
    
    /**
     * 默认最大发送缓冲区大小（1MB）
     */
    public const DEFAULT_MAX_SEND_BUFFER_SIZE = 1048576;
    
    /**
     * 默认最大包长度（10MB）
     */
    public const DEFAULT_MAX_PACKAGE_SIZE = 10485760;
    
    /**
     * 连接 ID 计数器
     */
    protected static int $idRecorder = 0;
    
    /**
     * 连接 ID
     */
    public int $id = 0;
    
    /**
     * Socket 资源
     * @var resource
     */
    protected $socket;
    
    /**
     * 远程地址
     */
    public string $remoteAddress = '';
    
    /**
     * 事件循环
     */
    protected ?EventInterface $eventLoop = null;
    
    /**
     * 所属 Worker
     */
    public ?Worker $worker = null;
    
    /**
     * 协议类名
     */
    public string $protocol = '';
    
    /**
     * 传输层协议
     */
    public string $transport = 'tcp';
    
    /**
     * 当前状态
     */
    protected int $status = self::STATUS_ESTABLISHED;
    
    /**
     * 接收缓冲区
     */
    protected string $recvBuffer = '';
    
    /**
     * 发送缓冲区
     */
    protected string $sendBuffer = '';
    
    /**
     * 最大发送缓冲区大小
     */
    public int $maxSendBufferSize = self::DEFAULT_MAX_SEND_BUFFER_SIZE;
    
    /**
     * 最大包长度
     */
    public static int $maxPackageSize = self::DEFAULT_MAX_PACKAGE_SIZE;
    
    /**
     * 当前包长度
     */
    protected int $currentPackageLength = 0;
    
    /**
     * 是否暂停接收
     */
    protected bool $isPaused = false;
    
    /**
     * 回调函数
     */
    public $onMessage = null;
    public $onClose = null;
    public $onError = null;
    public $onBufferFull = null;
    public $onBufferDrain = null;
    
    /**
     * 构造函数
     */
    public function __construct($socket, string $remoteAddress, ?EventInterface $eventLoop = null)
    {
        $this->id = ++static::$idRecorder;
        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
        $this->eventLoop = $eventLoop;
        
        // 设置非阻塞
        stream_set_blocking($this->socket, false);
        
        // 禁用 Nagle 算法（减少延迟）
        stream_set_read_buffer($this->socket, 0);
        
        // 监听读事件
        if ($this->eventLoop) {
            $this->eventLoop->add(
                $this->socket,
                EventInterface::EV_READ,
                [$this, 'baseRead']
            );
        }
    }
    
    /**
     * 读取数据（底层）
     */
    public function baseRead($socket, bool $checkEof = true): void
    {
        // 读取数据
        $buffer = @fread($socket, static::READ_BUFFER_SIZE);
        
        // 连接关闭或出错
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
            return;
        }
        
        // 追加到接收缓冲区
        $this->recvBuffer .= $buffer;
        
        // 处理数据
        $this->processRecvBuffer();
    }
    
    /**
     * 处理接收缓冲区
     */
    protected function processRecvBuffer(): void
    {
        // 没有协议，直接回调
        if ($this->protocol === '') {
            if ($this->recvBuffer !== '' && $this->onMessage) {
                $this->triggerMessage($this->recvBuffer);
                $this->recvBuffer = '';
            }
            return;
        }
        
        // 使用协议解析
        while ($this->recvBuffer !== '' && $this->status === self::STATUS_ESTABLISHED) {
            // 获取当前包长度
            if ($this->currentPackageLength === 0) {
                $this->currentPackageLength = ($this->protocol)::input(
                    $this->recvBuffer,
                    $this
                );
            }
            
            // 包不完整
            if ($this->currentPackageLength === 0) {
                return;
            }
            
            // 包长度错误
            if ($this->currentPackageLength < 0) {
                $this->close();
                return;
            }
            
            // 检查包长度限制
            if ($this->currentPackageLength > static::$maxPackageSize) {
                Worker::log("Package too large: {$this->currentPackageLength}");
                $this->close();
                return;
            }
            
            // 缓冲区数据不足
            if (strlen($this->recvBuffer) < $this->currentPackageLength) {
                return;
            }
            
            // 提取完整包
            $data = substr($this->recvBuffer, 0, $this->currentPackageLength);
            $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
            $this->currentPackageLength = 0;
            
            // 解码并触发消息回调
            $decoded = ($this->protocol)::decode($data, $this);
            $this->triggerMessage($decoded);
        }
    }
    
    /**
     * 触发消息回调
     */
    protected function triggerMessage($data): void
    {
        try {
            // 优先使用连接级回调
            if ($this->onMessage) {
                ($this->onMessage)($this, $data);
            } elseif ($this->worker && $this->worker->onMessage) {
                ($this->worker->onMessage)($this, $data);
            }
        } catch (\Throwable $e) {
            Worker::log("onMessage error: " . $e->getMessage());
            
            if ($this->onError) {
                ($this->onError)($this, $e->getCode(), $e->getMessage());
            } elseif ($this->worker && $this->worker->onError) {
                ($this->worker->onError)($this, $e->getCode(), $e->getMessage());
            }
        }
    }
    
    /**
     * 发送数据
     */
    public function send(mixed $data, bool $raw = false): bool
    {
        if ($this->status === self::STATUS_CLOSED) {
            return false;
        }
        
        // 协议编码
        if (!$raw && $this->protocol !== '') {
            $data = ($this->protocol)::encode($data, $this);
            
            if ($data === '') {
                return false;
            }
        }
        
        // 如果发送缓冲区为空，尝试直接发送
        if ($this->sendBuffer === '') {
            $len = @fwrite($this->socket, $data);
            
            // 全部发送成功
            if ($len === strlen($data)) {
                return true;
            }
            
            // 部分发送
            if ($len > 0) {
                $data = substr($data, $len);
            }
        }
        
        // 检查发送缓冲区大小
        if (strlen($this->sendBuffer) + strlen($data) >= $this->maxSendBufferSize) {
            // 触发缓冲区满回调
            if ($this->onBufferFull) {
                ($this->onBufferFull)($this);
            } elseif ($this->worker && $this->worker->onBufferFull) {
                ($this->worker->onBufferFull)($this);
            }
            return false;
        }
        
        // 追加到发送缓冲区
        $this->sendBuffer .= $data;
        
        // 注册写事件
        $this->eventLoop->add(
            $this->socket,
            EventInterface::EV_WRITE,
            [$this, 'baseWrite']
        );
        
        return true;
    }
    
    /**
     * 写入数据（底层）
     */
    public function baseWrite(): void
    {
        $len = @fwrite($this->socket, $this->sendBuffer);
        
        if ($len === strlen($this->sendBuffer)) {
            // 全部发送完成
            $this->sendBuffer = '';
            
            // 移除写事件
            $this->eventLoop->del($this->socket, EventInterface::EV_WRITE);
            
            // 触发缓冲区空回调
            if ($this->onBufferDrain) {
                ($this->onBufferDrain)($this);
            } elseif ($this->worker && $this->worker->onBufferDrain) {
                ($this->worker->onBufferDrain)($this);
            }
            
            // 如果是关闭中状态，现在关闭
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            
            return;
        }
        
        if ($len > 0) {
            $this->sendBuffer = substr($this->sendBuffer, $len);
        }
    }
    
    /**
     * 关闭连接
     */
    public function close(mixed $data = null): void
    {
        if ($this->status === self::STATUS_CLOSED || $this->status === self::STATUS_CLOSING) {
            return;
        }
        
        // 发送关闭前的数据
        if ($data !== null) {
            $this->send($data);
        }
        
        // 如果发送缓冲区不为空，设置为关闭中状态
        if ($this->sendBuffer !== '') {
            $this->status = self::STATUS_CLOSING;
            return;
        }
        
        $this->destroy();
    }
    
    /**
     * 销毁连接
     */
    public function destroy(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        
        $this->status = self::STATUS_CLOSED;
        
        // 移除事件监听
        if ($this->eventLoop) {
            $this->eventLoop->del($this->socket, EventInterface::EV_READ);
            $this->eventLoop->del($this->socket, EventInterface::EV_WRITE);
        }
        
        // 关闭 Socket
        @fclose($this->socket);
        
        // 从 Worker 中移除
        if ($this->worker) {
            unset($this->worker->connections[$this->id]);
            $this->worker->connectionCount--;
        }
        
        // 触发关闭回调
        $this->triggerClose();
        
        // 清理引用
        $this->socket = null;
        $this->eventLoop = null;
        $this->worker = null;
        $this->recvBuffer = '';
        $this->sendBuffer = '';
    }
    
    /**
     * 触发关闭回调
     */
    protected function triggerClose(): void
    {
        try {
            if ($this->onClose) {
                ($this->onClose)($this);
            } elseif ($this->worker && $this->worker->onClose) {
                ($this->worker->onClose)($this);
            }
        } catch (\Throwable $e) {
            Worker::log("onClose error: " . $e->getMessage());
        }
    }
    
    /**
     * 获取远程 IP
     */
    public function getRemoteIp(): string
    {
        $pos = strrpos($this->remoteAddress, ':');
        
        if ($pos === false) {
            return '';
        }
        
        return substr($this->remoteAddress, 0, $pos);
    }
    
    /**
     * 获取远程端口
     */
    public function getRemotePort(): int
    {
        $pos = strrpos($this->remoteAddress, ':');
        
        if ($pos === false) {
            return 0;
        }
        
        return (int) substr($this->remoteAddress, $pos + 1);
    }
    
    /**
     * 暂停接收
     */
    public function pauseRecv(): void
    {
        if ($this->isPaused || $this->status !== self::STATUS_ESTABLISHED) {
            return;
        }
        
        $this->isPaused = true;
        $this->eventLoop->del($this->socket, EventInterface::EV_READ);
    }
    
    /**
     * 恢复接收
     */
    public function resumeRecv(): void
    {
        if (!$this->isPaused || $this->status !== self::STATUS_ESTABLISHED) {
            return;
        }
        
        $this->isPaused = false;
        $this->eventLoop->add($this->socket, EventInterface::EV_READ, [$this, 'baseRead']);
    }
}
```

---

## 4. 事件循环接口

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync\Event;

/**
 * 事件循环接口
 */
interface EventInterface
{
    /**
     * 读事件
     */
    public const EV_READ = 1;
    
    /**
     * 写事件
     */
    public const EV_WRITE = 2;
    
    /**
     * 信号事件
     */
    public const EV_SIGNAL = 4;
    
    /**
     * 定时器事件（一次性）
     */
    public const EV_TIMER = 8;
    
    /**
     * 定时器事件（周期性）
     */
    public const EV_TIMER_ONCE = 16;
    
    /**
     * 添加事件
     *
     * @param mixed $fd 文件描述符/信号/时间间隔
     * @param int $flag 事件类型
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return bool
     */
    public function add($fd, int $flag, callable $callback, array $args = []): bool;
    
    /**
     * 删除事件
     */
    public function del($fd, int $flag): bool;
    
    /**
     * 运行事件循环
     */
    public function loop(): void;
    
    /**
     * 销毁事件循环
     */
    public function destroy(): void;
    
    /**
     * 清除所有定时器
     */
    public function clearAllTimer(): void;
}
```

---

## 5. HTTP 协议

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync\Protocol;

use Weline\WeAsync\Connection\TcpConnection;

/**
 * HTTP 协议
 */
class Http implements ProtocolInterface
{
    /**
     * 检查包长度
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // 检查是否包含完整 header
        $headerEnd = strpos($buffer, "\r\n\r\n");
        
        if ($headerEnd === false) {
            // header 超过 16KB，可能是攻击
            if (strlen($buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Entity Too Large\r\n\r\n");
                return 0;
            }
            return 0;
        }
        
        // 解析 Content-Length
        $method = strstr($buffer, ' ', true);
        
        // GET/HEAD/OPTIONS 等没有 body
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $headerEnd + 4;
        }
        
        // 获取 Content-Length
        $contentLength = 0;
        
        if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $buffer, $match)) {
            $contentLength = (int) $match[1];
        }
        
        // 检查 Transfer-Encoding: chunked
        if (preg_match('/\r\nTransfer-Encoding:\s*chunked/i', $buffer)) {
            return static::parseChunked($buffer, $headerEnd);
        }
        
        return $headerEnd + 4 + $contentLength;
    }
    
    /**
     * 解析 chunked 编码
     */
    protected static function parseChunked(string $buffer, int $headerEnd): int
    {
        $bodyStart = $headerEnd + 4;
        $body = substr($buffer, $bodyStart);
        
        $offset = 0;
        $length = strlen($body);
        
        while ($offset < $length) {
            // 查找 chunk size 行
            $lineEnd = strpos($body, "\r\n", $offset);
            
            if ($lineEnd === false) {
                return 0;
            }
            
            $chunkSize = hexdec(substr($body, $offset, $lineEnd - $offset));
            
            // 最后一个 chunk
            if ($chunkSize === 0) {
                // 检查是否有结尾的 \r\n
                if ($offset + $lineEnd + 4 > $length) {
                    return 0;
                }
                return $bodyStart + $lineEnd + 4;
            }
            
            $offset = $lineEnd + 2 + $chunkSize + 2;
            
            if ($offset > $length) {
                return 0;
            }
        }
        
        return 0;
    }
    
    /**
     * 解码 HTTP 请求
     */
    public static function decode(string $buffer, TcpConnection $connection): Request
    {
        return new Request($buffer);
    }
    
    /**
     * 编码 HTTP 响应
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        if ($data instanceof Response) {
            return (string) $data;
        }
        
        // 字符串直接包装为 200 响应
        if (is_string($data)) {
            $response = new Response(200, [], $data);
            return (string) $response;
        }
        
        // 数组转为 JSON
        if (is_array($data)) {
            $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
            return (string) $response;
        }
        
        return '';
    }
}
```

---

## 6. 使用示例

### 6.1 HTTP 服务器

```php
<?php
use Weline\WeAsync\Worker;
use Weline\WeAsync\Protocol\Request;

require_once __DIR__ . '/vendor/autoload.php';

// 创建 HTTP Worker
$worker = new Worker('http://0.0.0.0:8080');

// 设置进程数（CPU 核心数）
$worker->count = 4;

// 设置 Worker 名称
$worker->name = 'MyHttpServer';

// 收到消息回调
$worker->onMessage = function ($connection, Request $request) {
    // 返回响应
    $connection->send('Hello WeAsync!');
};

// Worker 启动时回调
$worker->onWorkerStart = function ($worker) {
    echo "Worker {$worker->id} started\n";
};

// 运行
Worker::runAll();
```

### 6.2 与框架集成

```php
<?php
use Weline\WeAsync\Worker;
use Weline\WeAsync\Protocol\Request;
use Weline\Framework\Runtime\RuntimeFactory;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'Weline';

$worker->onWorkerStart = function ($worker) {
    // 在每个 Worker 启动时初始化框架
    $runtime = RuntimeFactory::create();
    $runtime->bootstrap();
    
    // 将 runtime 存储到 Worker
    $worker->runtime = $runtime;
};

$worker->onMessage = function ($connection, Request $request) use ($worker) {
    // 转换为 PSR-7 请求
    $psrRequest = $request->toPsr7();
    
    // 框架处理请求
    $psrResponse = $worker->runtime->handle($psrRequest);
    
    // 发送响应
    $connection->send($psrResponse);
    
    // 重置状态
    $worker->runtime->reset();
};

Worker::runAll();
```

---

## 7. 目录结构（完整）

```
app/code/Weline/WeAsync/
├── Worker.php                    # 核心 Worker 类
├── Timer.php                     # 定时器外观
├── Autoloader.php               # 自动加载器
│
├── Connection/
│   ├── ConnectionInterface.php   # 连接接口
│   ├── TcpConnection.php         # TCP 连接
│   ├── UdpConnection.php         # UDP 连接
│   └── AsyncTcpConnection.php    # 异步 TCP 客户端
│
├── Event/
│   ├── EventInterface.php        # 事件循环接口
│   ├── Event.php                 # event 扩展实现
│   ├── Ev.php                    # ev 扩展实现
│   └── Select.php                # stream_select 实现
│
├── Protocol/
│   ├── ProtocolInterface.php     # 协议接口
│   ├── Http.php                  # HTTP 协议
│   ├── Request.php               # HTTP 请求
│   ├── Response.php              # HTTP 响应
│   ├── WebSocket.php             # WebSocket 协议
│   ├── Text.php                  # 文本协议
│   └── Frame.php                 # 帧协议
│
├── Lib/
│   └── Timer.php                 # 定时器实现
│
└── Console/
    ├── Start.php                 # 启动命令
    ├── Stop.php                  # 停止命令
    ├── Restart.php               # 重启命令
    ├── Reload.php                # 重载命令
    └── Status.php                # 状态命令
```

---

## 8. 实施计划

| 阶段 | 任务 | 预计时间 |
|------|------|----------|
| 1 | Worker 核心类 | 2 天 |
| 2 | TcpConnection | 1 天 |
| 3 | Event 驱动（3个） | 2 天 |
| 4 | HTTP 协议 | 1 天 |
| 5 | Timer 定时器 | 0.5 天 |
| 6 | 框架集成 | 1 天 |
| 7 | 测试和优化 | 2 天 |

---

## 9. 待办事项

- [ ] 实现 `Worker` 核心类
- [ ] 实现 `TcpConnection`
- [ ] 实现 `Event` 驱动（event 扩展）
- [ ] 实现 `Ev` 驱动（ev 扩展）
- [ ] 实现 `Select` 驱动（纯 PHP）
- [ ] 实现 `Http` 协议
- [ ] 实现 `Request` 和 `Response`
- [ ] 实现 `WebSocket` 协议
- [ ] 实现 `Timer` 定时器
- [ ] 框架 Runtime 集成
- [ ] 编写单元测试
- [ ] 性能基准测试

---

## 10. 相关文档

- [02-运行时抽象层](02-运行时抽象层.md) - Runtime 接口
- [05-事件循环集成](05-事件循环集成.md) - Fiber 协程
- [README](README.md) - 项目概述
