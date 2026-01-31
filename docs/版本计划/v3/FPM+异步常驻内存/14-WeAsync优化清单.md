# 14 - WeAsync 优化清单

> **优先级**: ⭐⭐⭐⭐⭐  
> **依赖**: 10-WeAsync异步引擎  
> **状态**: 已确认

---

## 0. 概述

本文档记录 WeAsync（照搬 Workerman）的优化项。这些优化是在 Workerman 原有基础上的改进。

### 优化原则

1. **保持兼容** - 优化不破坏 Workerman 原有 API
2. **可选启用** - 新功能默认关闭或可配置
3. **性能优先** - 优化不能降低性能

---

## 1. Windows 兼容性优化 ✅ 已确认

### 1.1 问题描述

Workerman 在 Windows 上的限制：
- 不支持 `pcntl_fork()`，无法创建多进程
- 单文件只能实例化一个 Worker
- `count` 属性无效

### 1.2 优化方案

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync;

/**
 * Windows 兼容层
 */
class WindowsCompat
{
    /**
     * Windows 下使用 proc_open 启动多个独立 PHP 进程
     */
    public static function forkWorkers(Worker $worker): void
    {
        $count = $worker->count;
        $scriptFile = self::generateWorkerScript($worker);
        
        for ($i = 0; $i < $count; $i++) {
            $process = proc_open(
                PHP_BINARY . ' ' . $scriptFile . ' ' . $i,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                [
                    'WEASYNC_WORKER_ID' => $i,
                    'WEASYNC_RUNTIME' => '1',
                ]
            );
            
            if ($process) {
                Worker::$_windowsProcesses[$i] = [
                    'process' => $process,
                    'pipes' => $pipes,
                ];
            }
        }
    }
    
    /**
     * 生成独立的 Worker 启动脚本
     */
    private static function generateWorkerScript(Worker $worker): string
    {
        $config = serialize([
            'listen' => $worker->getSocketName(),
            'name' => $worker->name,
            'protocol' => $worker->protocol,
            'transport' => $worker->transport,
            'context' => $worker->context,
            'reusePort' => $worker->reusePort,
        ]);
        
        $script = <<<PHP
<?php
require_once '{$worker->autoloadFile}';
\$config = unserialize('{$config}');
\$worker = new \\Weline\\WeAsync\\Worker(\$config['listen']);
foreach (\$config as \$key => \$value) {
    if (property_exists(\$worker, \$key)) {
        \$worker->\$key = \$value;
    }
}
\$worker->runSingleProcess();
PHP;
        
        $scriptFile = sys_get_temp_dir() . '/weasync_worker_' . md5($config) . '.php';
        file_put_contents($scriptFile, $script);
        return $scriptFile;
    }
    
    /**
     * 检查是否为 Windows 系统
     */
    public static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }
}
```

### 1.3 Worker 类修改

```php
// Worker.php 中的修改

public static function runAll(): void
{
    self::checkEnvironment();
    self::init();
    self::parseCommand();
    self::lock();
    self::daemonize();
    self::initWorkers();
    self::installSignal();
    self::saveMasterPid();
    self::unlock();
    self::displayUI();
    
    // 优化：Windows 兼容
    if (WindowsCompat::isWindows()) {
        self::forkWorkersForWindows();
    } else {
        self::forkWorkersForLinux();
    }
    
    self::monitorWorkers();
}

protected static function forkWorkersForWindows(): void
{
    foreach (self::$_workers as $worker) {
        WindowsCompat::forkWorkers($worker);
    }
}
```

### 1.4 配置示例

```php
// 启动文件 start.php
use Weline\WeAsync\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4; // Windows 下也生效

$worker->onMessage = function($connection, $request) {
    $connection->send('Hello World');
};

Worker::runAll();
```

---

## 2. HTTP/2 原生支持 ✅ 已确认

### 2.1 问题描述

Workerman 不支持 HTTP/2，无法利用：
- 多路复用（单连接多请求）
- 头部压缩（HPACK）
- 服务器推送
- 二进制分帧

### 2.2 优化方案

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync\Protocol;

/**
 * HTTP/2 协议实现
 * 
 * 支持特性：
 * - 多路复用
 * - HPACK 头部压缩
 * - 流量控制
 * - 服务器推送
 */
class Http2
{
    // HTTP/2 帧类型
    public const FRAME_DATA = 0x0;
    public const FRAME_HEADERS = 0x1;
    public const FRAME_PRIORITY = 0x2;
    public const FRAME_RST_STREAM = 0x3;
    public const FRAME_SETTINGS = 0x4;
    public const FRAME_PUSH_PROMISE = 0x5;
    public const FRAME_PING = 0x6;
    public const FRAME_GOAWAY = 0x7;
    public const FRAME_WINDOW_UPDATE = 0x8;
    public const FRAME_CONTINUATION = 0x9;
    
    // HTTP/2 设置
    public const SETTINGS_HEADER_TABLE_SIZE = 0x1;
    public const SETTINGS_ENABLE_PUSH = 0x2;
    public const SETTINGS_MAX_CONCURRENT_STREAMS = 0x3;
    public const SETTINGS_INITIAL_WINDOW_SIZE = 0x4;
    public const SETTINGS_MAX_FRAME_SIZE = 0x5;
    public const SETTINGS_MAX_HEADER_LIST_SIZE = 0x6;
    
    // 连接前言
    public const CONNECTION_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    /**
     * HPACK 编码器/解码器
     */
    private HpackEncoder $hpackEncoder;
    private HpackDecoder $hpackDecoder;
    
    /**
     * 流管理
     */
    private array $streams = [];
    private int $lastStreamId = 0;
    
    /**
     * 设置
     */
    private array $localSettings = [
        self::SETTINGS_HEADER_TABLE_SIZE => 4096,
        self::SETTINGS_ENABLE_PUSH => 1,
        self::SETTINGS_MAX_CONCURRENT_STREAMS => 100,
        self::SETTINGS_INITIAL_WINDOW_SIZE => 65535,
        self::SETTINGS_MAX_FRAME_SIZE => 16384,
        self::SETTINGS_MAX_HEADER_LIST_SIZE => 8192,
    ];
    
    private array $remoteSettings = [];
    
    /**
     * 解析 HTTP/2 帧
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // 检查连接前言
        if (!isset($connection->http2Initialized)) {
            if (strlen($buffer) < 24) {
                return 0;
            }
            if (substr($buffer, 0, 24) !== self::CONNECTION_PREFACE) {
                $connection->close();
                return 0;
            }
            $connection->http2Initialized = true;
            $connection->http2 = new self();
            $buffer = substr($buffer, 24);
        }
        
        // 解析帧（最小 9 字节头部）
        if (strlen($buffer) < 9) {
            return 0;
        }
        
        // 帧头部：3字节长度 + 1字节类型 + 1字节标志 + 4字节流ID
        $length = unpack('N', "\x00" . substr($buffer, 0, 3))[1];
        $type = ord($buffer[3]);
        $flags = ord($buffer[4]);
        $streamId = unpack('N', substr($buffer, 5, 4))[1] & 0x7FFFFFFF;
        
        $totalLength = 9 + $length;
        if (strlen($buffer) < $totalLength) {
            return 0;
        }
        
        return $totalLength;
    }
    
    /**
     * 解码请求
     */
    public static function decode(string $buffer, TcpConnection $connection): ?Http2Request
    {
        $http2 = $connection->http2;
        $offset = 0;
        
        // 跳过连接前言
        if (substr($buffer, 0, 24) === self::CONNECTION_PREFACE) {
            $offset = 24;
        }
        
        // 解析帧
        while ($offset < strlen($buffer)) {
            if (strlen($buffer) - $offset < 9) {
                break;
            }
            
            $length = unpack('N', "\x00" . substr($buffer, $offset, 3))[1];
            $type = ord($buffer[$offset + 3]);
            $flags = ord($buffer[$offset + 4]);
            $streamId = unpack('N', substr($buffer, $offset + 5, 4))[1] & 0x7FFFFFFF;
            $payload = substr($buffer, $offset + 9, $length);
            
            $http2->processFrame($type, $flags, $streamId, $payload, $connection);
            
            $offset += 9 + $length;
        }
        
        // 检查是否有完整的请求
        foreach ($http2->streams as $id => $stream) {
            if ($stream['endHeaders'] && $stream['endStream']) {
                $request = $http2->buildRequest($stream);
                unset($http2->streams[$id]);
                return $request;
            }
        }
        
        return null;
    }
    
    /**
     * 编码响应
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        $http2 = $connection->http2;
        $streamId = $connection->currentStreamId ?? 1;
        
        if ($response instanceof Http2Response) {
            return $http2->encodeResponse($response, $streamId);
        }
        
        // 简单字符串响应
        $headers = [
            ':status' => '200',
            'content-type' => 'text/html; charset=utf-8',
            'content-length' => (string)strlen($response),
        ];
        
        return $http2->encodeResponse(new Http2Response(200, $headers, $response), $streamId);
    }
    
    /**
     * 处理帧
     */
    private function processFrame(int $type, int $flags, int $streamId, string $payload, TcpConnection $connection): void
    {
        switch ($type) {
            case self::FRAME_SETTINGS:
                $this->handleSettings($payload, $flags, $connection);
                break;
            case self::FRAME_HEADERS:
                $this->handleHeaders($streamId, $payload, $flags);
                break;
            case self::FRAME_DATA:
                $this->handleData($streamId, $payload, $flags);
                break;
            case self::FRAME_WINDOW_UPDATE:
                $this->handleWindowUpdate($streamId, $payload);
                break;
            case self::FRAME_PING:
                $this->handlePing($payload, $flags, $connection);
                break;
            case self::FRAME_GOAWAY:
                $connection->close();
                break;
        }
    }
    
    /**
     * 服务器推送
     */
    public function push(TcpConnection $connection, string $path, array $headers, string $body): void
    {
        if (!$this->remoteSettings[self::SETTINGS_ENABLE_PUSH] ?? true) {
            return;
        }
        
        $promisedStreamId = $this->lastStreamId + 2;
        $this->lastStreamId = $promisedStreamId;
        
        // 发送 PUSH_PROMISE 帧
        $pushPromise = $this->buildPushPromiseFrame($connection->currentStreamId, $promisedStreamId, $path, $headers);
        $connection->send($pushPromise, true);
        
        // 发送响应
        $response = $this->encodeResponse(new Http2Response(200, $headers, $body), $promisedStreamId);
        $connection->send($response, true);
    }
}
```

### 2.3 友好配置

```php
<?php
// config/weasync.php

return [
    'http2' => [
        // 是否启用 HTTP/2
        'enabled' => true,
        
        // HTTP/2 设置
        'settings' => [
            // 头部表大小（HPACK）
            'header_table_size' => 4096,
            
            // 是否允许服务器推送
            'enable_push' => true,
            
            // 最大并发流数
            'max_concurrent_streams' => 100,
            
            // 初始窗口大小
            'initial_window_size' => 65535,
            
            // 最大帧大小
            'max_frame_size' => 16384,
            
            // 最大头部列表大小
            'max_header_list_size' => 8192,
        ],
        
        // 是否自动升级 HTTP/1.1 到 HTTP/2
        'auto_upgrade' => true,
        
        // ALPN 协议协商
        'alpn_protocols' => ['h2', 'http/1.1'],
    ],
    
    'ssl' => [
        'cert' => '/path/to/cert.pem',
        'key' => '/path/to/key.pem',
        
        // HTTP/2 需要 TLS 1.2+
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
    ],
];
```

### 2.4 使用示例

```php
use Weline\WeAsync\Worker;
use Weline\WeAsync\Protocol\Http2;

// 方式1：直接启用 HTTP/2
$worker = new Worker('http2://0.0.0.0:443');
$worker->transport = 'ssl';
$worker->context = [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ],
];

$worker->onMessage = function($connection, $request) {
    // 服务器推送
    $connection->http2->push($connection, '/style.css', [
        'content-type' => 'text/css',
    ], 'body { color: red; }');
    
    $connection->send('Hello HTTP/2!');
};

// 方式2：使用配置文件
$worker = Worker::createFromConfig('config/weasync.php');

Worker::runAll();
```

---

## 3. 限流中间件 ✅ 已确认（可选，内置框架）

### 3.1 设计目标

- 可选启用
- 支持多种限流算法
- 支持 IP 限流、用户限流、API 限流
- 与框架中间件系统集成

### 3.2 实现方案

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync\Middleware;

use Weline\WeAsync\Connection\TcpConnection;
use Weline\WeAsync\Protocol\Http\Request;

/**
 * 限流中间件
 * 
 * 支持算法：
 * - 令牌桶（Token Bucket）
 * - 漏桶（Leaky Bucket）
 * - 滑动窗口（Sliding Window）
 * - 固定窗口（Fixed Window）
 */
class RateLimiter implements MiddlewareInterface
{
    // 限流算法
    public const ALGORITHM_TOKEN_BUCKET = 'token_bucket';
    public const ALGORITHM_LEAKY_BUCKET = 'leaky_bucket';
    public const ALGORITHM_SLIDING_WINDOW = 'sliding_window';
    public const ALGORITHM_FIXED_WINDOW = 'fixed_window';
    
    /**
     * 配置
     */
    private array $config = [
        // 是否启用
        'enabled' => true,
        
        // 限流算法
        'algorithm' => self::ALGORITHM_SLIDING_WINDOW,
        
        // IP 限流
        'ip' => [
            'enabled' => true,
            'max_requests' => 100,      // 最大请求数
            'window_seconds' => 60,      // 时间窗口（秒）
            'max_connections' => 50,     // 最大连接数
            'whitelist' => ['127.0.0.1', '::1'],
            'blacklist' => [],
        ],
        
        // API 路由限流
        'routes' => [
            '/api/*' => [
                'max_requests' => 60,
                'window_seconds' => 60,
            ],
            '/login' => [
                'max_requests' => 5,
                'window_seconds' => 60,
            ],
        ],
        
        // 用户限流（需要认证）
        'user' => [
            'enabled' => false,
            'max_requests' => 1000,
            'window_seconds' => 3600,
        ],
        
        // 全局限流
        'global' => [
            'enabled' => false,
            'max_requests' => 10000,
            'window_seconds' => 1,
        ],
        
        // 响应配置
        'response' => [
            'status_code' => 429,
            'message' => 'Too Many Requests',
            'headers' => [
                'Retry-After' => '{retry_after}',
                'X-RateLimit-Limit' => '{limit}',
                'X-RateLimit-Remaining' => '{remaining}',
                'X-RateLimit-Reset' => '{reset}',
            ],
        ],
    ];
    
    /**
     * 存储驱动
     */
    private RateLimitStorageInterface $storage;
    
    /**
     * 当前连接数统计
     */
    private static array $connectionCounts = [];
    
    public function __construct(array $config = [], ?RateLimitStorageInterface $storage = null)
    {
        $this->config = array_replace_recursive($this->config, $config);
        $this->storage = $storage ?? new MemoryStorage();
    }
    
    /**
     * 处理请求
     */
    public function process(Request $request, TcpConnection $connection, callable $next): mixed
    {
        if (!$this->config['enabled']) {
            return $next($request, $connection);
        }
        
        $ip = $connection->getRemoteIp();
        
        // 检查黑名单
        if ($this->isBlacklisted($ip)) {
            return $this->rejectRequest($connection, 'IP blacklisted');
        }
        
        // 检查白名单
        if ($this->isWhitelisted($ip)) {
            return $next($request, $connection);
        }
        
        // IP 连接数限流
        if (!$this->checkConnectionLimit($ip)) {
            return $this->rejectRequest($connection, 'Too many connections');
        }
        
        // IP 请求频率限流
        if (!$this->checkIpRateLimit($ip)) {
            return $this->rejectRequest($connection, 'IP rate limit exceeded');
        }
        
        // 路由限流
        $path = $request->path();
        if (!$this->checkRouteRateLimit($ip, $path)) {
            return $this->rejectRequest($connection, 'Route rate limit exceeded');
        }
        
        // 全局限流
        if (!$this->checkGlobalRateLimit()) {
            return $this->rejectRequest($connection, 'Global rate limit exceeded');
        }
        
        return $next($request, $connection);
    }
    
    /**
     * 滑动窗口算法
     */
    private function slidingWindowCheck(string $key, int $maxRequests, int $windowSeconds): array
    {
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;
        
        // 获取窗口内的请求记录
        $requests = $this->storage->getWindow($key, $windowStart);
        $count = count($requests);
        
        if ($count >= $maxRequests) {
            $oldestRequest = min($requests);
            $retryAfter = ceil($oldestRequest + $windowSeconds - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, $retryAfter),
                'reset' => ceil($now + $windowSeconds),
            ];
        }
        
        // 记录本次请求
        $this->storage->addRequest($key, $now, $windowSeconds);
        
        return [
            'allowed' => true,
            'remaining' => $maxRequests - $count - 1,
            'retry_after' => 0,
            'reset' => ceil($now + $windowSeconds),
        ];
    }
    
    /**
     * 令牌桶算法
     */
    private function tokenBucketCheck(string $key, int $maxTokens, int $refillRate): array
    {
        $now = microtime(true);
        $bucket = $this->storage->getBucket($key);
        
        if ($bucket === null) {
            $bucket = [
                'tokens' => $maxTokens - 1,
                'last_refill' => $now,
            ];
        } else {
            // 补充令牌
            $elapsed = $now - $bucket['last_refill'];
            $refill = floor($elapsed * $refillRate);
            $bucket['tokens'] = min($maxTokens, $bucket['tokens'] + $refill);
            $bucket['last_refill'] = $now;
            
            // 消费令牌
            if ($bucket['tokens'] < 1) {
                $waitTime = (1 - $bucket['tokens']) / $refillRate;
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after' => ceil($waitTime),
                    'reset' => ceil($now + $waitTime),
                ];
            }
            
            $bucket['tokens']--;
        }
        
        $this->storage->setBucket($key, $bucket);
        
        return [
            'allowed' => true,
            'remaining' => floor($bucket['tokens']),
            'retry_after' => 0,
            'reset' => ceil($now + ($maxTokens - $bucket['tokens']) / $refillRate),
        ];
    }
    
    /**
     * 拒绝请求
     */
    private function rejectRequest(TcpConnection $connection, string $reason): void
    {
        $config = $this->config['response'];
        $statusCode = $config['status_code'];
        $message = $config['message'];
        
        $headers = "HTTP/1.1 {$statusCode} {$message}\r\n";
        $headers .= "Content-Type: application/json\r\n";
        
        foreach ($config['headers'] as $name => $value) {
            $headers .= "{$name}: {$value}\r\n";
        }
        
        $body = json_encode([
            'error' => $message,
            'reason' => $reason,
        ]);
        
        $headers .= "Content-Length: " . strlen($body) . "\r\n\r\n";
        
        $connection->send($headers . $body);
        $connection->close();
    }
    
    /**
     * 连接建立时增加计数
     */
    public function onConnect(TcpConnection $connection): void
    {
        $ip = $connection->getRemoteIp();
        self::$connectionCounts[$ip] = (self::$connectionCounts[$ip] ?? 0) + 1;
    }
    
    /**
     * 连接关闭时减少计数
     */
    public function onClose(TcpConnection $connection): void
    {
        $ip = $connection->getRemoteIp();
        if (isset(self::$connectionCounts[$ip])) {
            self::$connectionCounts[$ip]--;
            if (self::$connectionCounts[$ip] <= 0) {
                unset(self::$connectionCounts[$ip]);
            }
        }
    }
}

/**
 * 限流存储接口
 */
interface RateLimitStorageInterface
{
    public function getWindow(string $key, float $windowStart): array;
    public function addRequest(string $key, float $timestamp, int $ttl): void;
    public function getBucket(string $key): ?array;
    public function setBucket(string $key, array $bucket): void;
}

/**
 * 内存存储（单进程）
 */
class MemoryStorage implements RateLimitStorageInterface
{
    private array $windows = [];
    private array $buckets = [];
    
    public function getWindow(string $key, float $windowStart): array
    {
        if (!isset($this->windows[$key])) {
            return [];
        }
        
        // 清理过期记录
        $this->windows[$key] = array_filter(
            $this->windows[$key],
            fn($ts) => $ts >= $windowStart
        );
        
        return $this->windows[$key];
    }
    
    public function addRequest(string $key, float $timestamp, int $ttl): void
    {
        $this->windows[$key][] = $timestamp;
    }
    
    public function getBucket(string $key): ?array
    {
        return $this->buckets[$key] ?? null;
    }
    
    public function setBucket(string $key, array $bucket): void
    {
        $this->buckets[$key] = $bucket;
    }
}

/**
 * Redis 存储（多进程共享）
 */
class RedisStorage implements RateLimitStorageInterface
{
    private \Redis $redis;
    private string $prefix = 'weasync:ratelimit:';
    
    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }
    
    public function getWindow(string $key, float $windowStart): array
    {
        $fullKey = $this->prefix . 'window:' . $key;
        $this->redis->zRemRangeByScore($fullKey, '-inf', (string)$windowStart);
        return $this->redis->zRange($fullKey, 0, -1);
    }
    
    public function addRequest(string $key, float $timestamp, int $ttl): void
    {
        $fullKey = $this->prefix . 'window:' . $key;
        $this->redis->zAdd($fullKey, $timestamp, (string)$timestamp);
        $this->redis->expire($fullKey, $ttl);
    }
    
    public function getBucket(string $key): ?array
    {
        $fullKey = $this->prefix . 'bucket:' . $key;
        $data = $this->redis->get($fullKey);
        return $data ? json_decode($data, true) : null;
    }
    
    public function setBucket(string $key, array $bucket): void
    {
        $fullKey = $this->prefix . 'bucket:' . $key;
        $this->redis->set($fullKey, json_encode($bucket));
    }
}
```

### 3.3 使用示例

```php
use Weline\WeAsync\Worker;
use Weline\WeAsync\Middleware\RateLimiter;
use Weline\WeAsync\Middleware\RedisStorage;

$worker = new Worker('http://0.0.0.0:8080');

// 方式1：使用默认配置
$worker->addMiddleware(new RateLimiter());

// 方式2：自定义配置
$worker->addMiddleware(new RateLimiter([
    'ip' => [
        'max_requests' => 100,
        'window_seconds' => 60,
    ],
    'routes' => [
        '/api/login' => [
            'max_requests' => 5,
            'window_seconds' => 300,
        ],
    ],
]));

// 方式3：使用 Redis 存储（多进程共享）
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$worker->addMiddleware(new RateLimiter(
    config: ['algorithm' => RateLimiter::ALGORITHM_TOKEN_BUCKET],
    storage: new RedisStorage($redis)
));

$worker->onMessage = function($connection, $request) {
    $connection->send('Hello World');
};

Worker::runAll();
```

---

## 4. 热重载 ✅ 已确认（内置）

### 4.1 设计目标

- 开发模式自动检测文件变更
- 自动重启 Worker 进程
- 最小化重启时间
- 不影响生产环境

### 4.2 实现方案

```php
<?php
declare(strict_types=1);

namespace Weline\WeAsync;

/**
 * 热重载
 * 
 * 监控文件变更，自动重载 Worker
 */
class HotReload
{
    /**
     * 配置
     */
    private array $config = [
        // 是否启用
        'enabled' => false,
        
        // 监控目录
        'watch_dirs' => [],
        
        // 监控文件扩展名
        'extensions' => ['php', 'phtml', 'json', 'xml', 'yaml', 'yml'],
        
        // 排除目录
        'exclude_dirs' => ['vendor', 'node_modules', '.git', 'var', 'generated'],
        
        // 检查间隔（秒）
        'interval' => 1,
        
        // 防抖时间（毫秒）- 多个文件同时变更只触发一次
        'debounce' => 500,
        
        // 重载方式：graceful（优雅）/ force（强制）
        'mode' => 'graceful',
        
        // 是否显示变更文件
        'verbose' => true,
    ];
    
    /**
     * 文件修改时间缓存
     */
    private array $fileMtimes = [];
    
    /**
     * 上次重载时间
     */
    private float $lastReloadTime = 0;
    
    /**
     * 待处理的变更文件
     */
    private array $pendingChanges = [];
    
    /**
     * 定时器 ID
     */
    private ?int $timerId = null;
    
    /**
     * 防抖定时器 ID
     */
    private ?int $debounceTimerId = null;
    
    public function __construct(array $config = [])
    {
        $this->config = array_replace_recursive($this->config, $config);
    }
    
    /**
     * 启动热重载
     */
    public function start(): void
    {
        if (!$this->config['enabled']) {
            return;
        }
        
        if (Worker::$daemonize) {
            Worker::log("热重载：守护进程模式下不启用");
            return;
        }
        
        // 初始化文件列表
        $this->initFileMtimes();
        
        Worker::log("热重载：已启用，监控 " . count($this->fileMtimes) . " 个文件");
        
        // 定时检查文件变更
        $this->timerId = Timer::add($this->config['interval'], function() {
            $this->checkChanges();
        });
    }
    
    /**
     * 停止热重载
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
        }
        
        if ($this->debounceTimerId !== null) {
            Timer::del($this->debounceTimerId);
            $this->debounceTimerId = null;
        }
    }
    
    /**
     * 初始化文件修改时间
     */
    private function initFileMtimes(): void
    {
        $this->fileMtimes = [];
        
        foreach ($this->config['watch_dirs'] as $dir) {
            $this->scanDirectory($dir);
        }
    }
    
    /**
     * 递归扫描目录
     */
    private function scanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                // 检查是否排除目录
                $dirName = $file->getFilename();
                if (in_array($dirName, $this->config['exclude_dirs'])) {
                    continue;
                }
            } else {
                // 检查文件扩展名
                $ext = $file->getExtension();
                if (!in_array($ext, $this->config['extensions'])) {
                    continue;
                }
                
                $path = $file->getRealPath();
                $this->fileMtimes[$path] = $file->getMTime();
            }
        }
    }
    
    /**
     * 检查文件变更
     */
    private function checkChanges(): void
    {
        $changes = [];
        
        foreach ($this->fileMtimes as $path => $mtime) {
            if (!file_exists($path)) {
                // 文件被删除
                $changes[] = ['type' => 'deleted', 'path' => $path];
                unset($this->fileMtimes[$path]);
            } else {
                $currentMtime = filemtime($path);
                if ($currentMtime > $mtime) {
                    // 文件被修改
                    $changes[] = ['type' => 'modified', 'path' => $path];
                    $this->fileMtimes[$path] = $currentMtime;
                }
            }
        }
        
        // 检查新增文件
        foreach ($this->config['watch_dirs'] as $dir) {
            $this->checkNewFiles($dir, $changes);
        }
        
        if (!empty($changes)) {
            $this->pendingChanges = array_merge($this->pendingChanges, $changes);
            $this->scheduleReload();
        }
    }
    
    /**
     * 检查新增文件
     */
    private function checkNewFiles(string $dir, array &$changes): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $dirName = $file->getFilename();
                if (in_array($dirName, $this->config['exclude_dirs'])) {
                    continue;
                }
            } else {
                $ext = $file->getExtension();
                if (!in_array($ext, $this->config['extensions'])) {
                    continue;
                }
                
                $path = $file->getRealPath();
                if (!isset($this->fileMtimes[$path])) {
                    $changes[] = ['type' => 'created', 'path' => $path];
                    $this->fileMtimes[$path] = $file->getMTime();
                }
            }
        }
    }
    
    /**
     * 调度重载（防抖）
     */
    private function scheduleReload(): void
    {
        // 取消之前的防抖定时器
        if ($this->debounceTimerId !== null) {
            Timer::del($this->debounceTimerId);
        }
        
        // 设置新的防抖定时器
        $this->debounceTimerId = Timer::add(
            $this->config['debounce'] / 1000,
            function() {
                $this->doReload();
            },
            [],
            false // 不重复
        );
    }
    
    /**
     * 执行重载
     */
    private function doReload(): void
    {
        $this->debounceTimerId = null;
        
        if (empty($this->pendingChanges)) {
            return;
        }
        
        $changes = $this->pendingChanges;
        $this->pendingChanges = [];
        
        if ($this->config['verbose']) {
            Worker::log("热重载：检测到 " . count($changes) . " 个文件变更");
            foreach ($changes as $change) {
                $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $change['path']);
                Worker::log("  [{$change['type']}] {$relativePath}");
            }
        }
        
        Worker::log("热重载：正在重启 Worker...");
        $this->lastReloadTime = microtime(true);
        
        // 发送信号给主进程
        if ($this->config['mode'] === 'graceful') {
            // 优雅重启：等待当前请求完成
            posix_kill(Worker::$masterPid, SIGUSR1);
        } else {
            // 强制重启
            posix_kill(Worker::$masterPid, SIGUSR2);
        }
    }
    
    /**
     * 获取上次重载时间
     */
    public function getLastReloadTime(): float
    {
        return $this->lastReloadTime;
    }
    
    /**
     * 获取监控的文件数量
     */
    public function getWatchedFileCount(): int
    {
        return count($this->fileMtimes);
    }
}
```

### 4.3 Worker 集成

```php
// Worker.php 中添加热重载支持

class Worker
{
    /**
     * 热重载实例
     */
    public static ?HotReload $hotReload = null;
    
    /**
     * 启用热重载
     */
    public static function enableHotReload(array $config = []): void
    {
        // 自动设置监控目录
        if (empty($config['watch_dirs'])) {
            $config['watch_dirs'] = [
                dirname(__DIR__) . '/app/code',  // 模块目录
            ];
        }
        
        $config['enabled'] = true;
        self::$hotReload = new HotReload($config);
    }
    
    /**
     * 在 Master 进程中启动热重载
     */
    protected static function installSignal(): void
    {
        // ... 原有信号处理 ...
        
        // SIGUSR1: 优雅重启所有 Worker
        pcntl_signal(SIGUSR1, function() {
            self::log("收到 SIGUSR1 信号，优雅重启 Worker");
            foreach (self::$_pidMap as $workerId => $pids) {
                foreach ($pids as $pid) {
                    posix_kill($pid, SIGTERM);
                }
            }
        });
        
        // SIGUSR2: 强制重启所有 Worker
        pcntl_signal(SIGUSR2, function() {
            self::log("收到 SIGUSR2 信号，强制重启 Worker");
            foreach (self::$_pidMap as $workerId => $pids) {
                foreach ($pids as $pid) {
                    posix_kill($pid, SIGKILL);
                }
            }
        });
    }
    
    /**
     * Worker 启动后初始化热重载
     */
    protected static function initWorkers(): void
    {
        // ... 原有初始化 ...
        
        // 在 Master 进程中启动热重载
        if (self::$hotReload && posix_getpid() === self::$masterPid) {
            self::$hotReload->start();
        }
    }
}
```

### 4.4 使用示例

```php
use Weline\WeAsync\Worker;

// 方式1：简单启用
Worker::enableHotReload();

// 方式2：自定义配置
Worker::enableHotReload([
    'watch_dirs' => [
        __DIR__ . '/app',
        __DIR__ . '/config',
    ],
    'extensions' => ['php', 'phtml', 'json'],
    'exclude_dirs' => ['vendor', 'var', 'generated'],
    'interval' => 2,      // 2秒检查一次
    'debounce' => 1000,   // 1秒防抖
    'mode' => 'graceful', // 优雅重启
    'verbose' => true,    // 显示变更文件
]);

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;

$worker->onMessage = function($connection, $request) {
    $connection->send('Hello World');
};

Worker::runAll();
```

### 4.5 开发模式自动启用

```php
// 框架集成：开发模式自动启用热重载

// bin/weasync
#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Weline\WeAsync\Worker;

// 检测环境
$env = getenv('APP_ENV') ?: 'production';

if ($env === 'development' || $env === 'dev') {
    Worker::enableHotReload([
        'watch_dirs' => [
            __DIR__ . '/../app/code',
            __DIR__ . '/../config',
        ],
    ]);
    
    echo "开发模式：热重载已启用\n";
}

// 加载 Worker 配置
require __DIR__ . '/../start.php';
```

---

## 5. 其他已确认优化

### 5.1 MySQL Gone Away 自动处理

```php
// 在连接池中自动重连
class ConnectionPool
{
    public function acquire(): PDO
    {
        $conn = $this->getConnection();
        
        try {
            // 检测连接是否有效
            $conn->query('SELECT 1');
        } catch (\PDOException $e) {
            if ($this->isConnectionLost($e)) {
                // 重建连接
                $conn = $this->createConnection();
            } else {
                throw $e;
            }
        }
        
        return $conn;
    }
    
    private function isConnectionLost(\PDOException $e): bool
    {
        $lostMessages = [
            'MySQL server has gone away',
            'Lost connection to MySQL server',
            'Connection timed out',
        ];
        
        foreach ($lostMessages as $msg) {
            if (stripos($e->getMessage(), $msg) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
```

### 5.2 WebSocket 干净关闭

```php
// 在 TcpConnection 中处理 WebSocket 关闭
public function close($data = '', $raw = false): void
{
    if ($this->protocol === 'websocket' && $this->_status === self::STATUS_ESTABLISHED) {
        // 发送 Close Frame
        $closeCode = 1000; // 正常关闭
        $closeFrame = "\x88" . chr(2) . pack('n', $closeCode);
        
        // 如果有关闭原因
        if ($data) {
            $closeFrame = "\x88" . chr(2 + strlen($data)) . pack('n', $closeCode) . $data;
        }
        
        @fwrite($this->_socket, $closeFrame);
        
        // 延迟关闭，等待客户端响应
        $this->_status = self::STATUS_CLOSING;
        Timer::add(1, function() {
            $this->destroy();
        }, [], false);
    } else {
        $this->destroy();
    }
}
```

### 5.3 启动环境检测

```php
public static function checkEnvironment(): void
{
    $warnings = [];
    
    // 检查 event 扩展
    if (!extension_loaded('event') && !extension_loaded('ev')) {
        $warnings[] = "未安装 event/ev 扩展，最大连接数限制 1024";
    }
    
    // 检查 pcntl 扩展（Linux）
    if (!WindowsCompat::isWindows() && !extension_loaded('pcntl')) {
        $warnings[] = "未安装 pcntl 扩展，无法使用多进程";
    }
    
    // 检查 ulimit
    if (function_exists('posix_getrlimit')) {
        $limit = posix_getrlimit();
        if (($limit['soft openfiles'] ?? 0) < 10000) {
            $warnings[] = "ulimit -n = {$limit['soft openfiles']}，建议 ≥ 65535";
        }
    }
    
    // 检查 PHP 版本
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $warnings[] = "PHP 版本 " . PHP_VERSION . "，建议 ≥ 8.1（Fiber 支持）";
    }
    
    // 输出警告
    foreach ($warnings as $warning) {
        self::log("⚠️ " . $warning);
    }
}
```

---

## 6. 实现文件清单

### 优化新增文件

| 文件 | 功能 | 代码行数(预估) |
|------|------|---------------|
| `WindowsCompat.php` | Windows 多进程兼容 | ~150 |
| `HotReload.php` | 热重载 | ~300 |
| `Protocol/Http2.php` | HTTP/2 协议 | ~800 |
| `Protocol/Http2/HpackEncoder.php` | HPACK 编码 | ~300 |
| `Protocol/Http2/HpackDecoder.php` | HPACK 解码 | ~300 |
| `Protocol/Http2/Stream.php` | HTTP/2 流管理 | ~200 |
| `Protocol/Http2/Frame.php` | HTTP/2 帧处理 | ~200 |
| `Middleware/MiddlewareInterface.php` | 中间件接口 | ~30 |
| `Middleware/RateLimiter.php` | 限流中间件 | ~400 |
| `Middleware/Cors.php` | CORS 中间件 | ~100 |
| `Middleware/Storage/StorageInterface.php` | 存储接口 | ~20 |
| `Middleware/Storage/MemoryStorage.php` | 内存存储 | ~80 |
| `Middleware/Storage/RedisStorage.php` | Redis 存储 | ~100 |

### 需要修改的照搬文件

| 文件 | 修改内容 |
|------|----------|
| `Worker.php` | 集成 WindowsCompat、HotReload、环境检测 |
| `Connection/TcpConnection.php` | WebSocket 干净关闭 |

### 总计

| 类型 | 文件数 | 代码行数 |
|------|--------|----------|
| 优化新增 | 13 | ~3,000 |
| 修改照搬 | 2 | ~200 修改 |

---

## 相关文档

- [10-WeAsync异步引擎](10-Worker进程管理.md) - 核心实现（照搬 Workerman）
- [06-数据库连接池](06-数据库连接池.md) - 连接池设计
- [11-兼容性层](11-兼容性层.md) - FPM/WeAsync 兼容
