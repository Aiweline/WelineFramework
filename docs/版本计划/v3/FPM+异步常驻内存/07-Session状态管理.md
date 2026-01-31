# 07 - Session 状态管理

> **优先级**: ⭐⭐⭐  
> **依赖**: 03-ObjectManager改造, 04-请求响应抽象  
> **预计工作量**: 2-3 天

---

## 0. 与 WeAsync 的关系

在 **WeAsync**（照搬 Workerman）模式下，PHP 原生 Session 机制无法使用：

```
FPM 模式：
  session_start() → 读取文件 → 锁定 → 请求处理 → 写入 → 解锁

WeAsync 模式（多请求共享进程）：
  ❌ session_start() 会冲突
  ❌ 文件锁会阻塞并发请求
  ❌ $_SESSION 会跨请求污染
```

解决方案：使用 Redis/Database 存储 Session，不依赖 PHP 原生 Session。

---

## 1. 概述

WeAsync 常驻内存模式下，PHP 原生 Session 机制存在问题：
- 文件 Session 会锁定，阻塞并发请求
- Session 数据在请求间需要隔离
- 需要支持分布式 Session（多 Worker）

### 1.1 设计目标

| 目标 | 描述 |
|------|------|
| **无锁设计** | 避免 Session 锁阻塞 |
| **请求隔离** | 每个请求的 Session 数据独立 |
| **分布式支持** | 支持 Redis 等外部存储 |
| **向后兼容** | 保持现有 API |

---

## 2. Session 接口设计

### 2.1 SessionInterface

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session;

/**
 * Session 接口
 */
interface SessionInterface
{
    /**
     * 启动 Session
     */
    public function start(): bool;
    
    /**
     * 获取 Session ID
     */
    public function getId(): string;
    
    /**
     * 设置 Session ID
     */
    public function setId(string $id): void;
    
    /**
     * 重新生成 Session ID
     */
    public function regenerateId(bool $deleteOld = true): bool;
    
    /**
     * 获取 Session 数据
     */
    public function get(string $key, mixed $default = null): mixed;
    
    /**
     * 设置 Session 数据
     */
    public function set(string $key, mixed $value): void;
    
    /**
     * 检查 Session 键是否存在
     */
    public function has(string $key): bool;
    
    /**
     * 删除 Session 数据
     */
    public function delete(string $key): void;
    
    /**
     * 获取所有 Session 数据
     */
    public function all(): array;
    
    /**
     * 清空 Session 数据
     */
    public function clear(): void;
    
    /**
     * 保存 Session
     */
    public function save(): void;
    
    /**
     * 销毁 Session
     */
    public function destroy(): bool;
    
    /**
     * 是否已启动
     */
    public function isStarted(): bool;
}
```

### 2.2 SessionStorageInterface

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

/**
 * Session 存储接口
 */
interface SessionStorageInterface
{
    /**
     * 读取 Session 数据
     */
    public function read(string $id): array;
    
    /**
     * 写入 Session 数据
     */
    public function write(string $id, array $data, int $ttl): bool;
    
    /**
     * 删除 Session
     */
    public function destroy(string $id): bool;
    
    /**
     * 垃圾回收
     */
    public function gc(int $maxLifetime): int;
    
    /**
     * 检查 Session 是否存在
     */
    public function exists(string $id): bool;
}
```

---

## 3. Session 实现

### 3.1 Session 类

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Attribute\InstanceScope;
use Weline\Framework\Manager\Scope;
use Weline\Framework\Session\Storage\SessionStorageInterface;

#[InstanceScope(Scope::REQUEST)]
class Session implements SessionInterface
{
    private string $id = '';
    private array $data = [];
    private bool $started = false;
    private bool $modified = false;
    
    private const COOKIE_NAME = 'WELINESSID';
    private const DEFAULT_TTL = 7200; // 2小时
    
    public function __construct(
        private readonly SessionStorageInterface $storage,
        private readonly Request $request,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {}
    
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }
        
        // 从 Cookie 获取 Session ID
        $this->id = $this->request->getCookie(self::COOKIE_NAME) ?? '';
        
        // 生成新 ID 或加载现有数据
        if ($this->id === '' || !$this->storage->exists($this->id)) {
            $this->id = $this->generateId();
            $this->data = [];
        } else {
            $this->data = $this->storage->read($this->id);
        }
        
        $this->started = true;
        return true;
    }
    
    public function getId(): string
    {
        return $this->id;
    }
    
    public function setId(string $id): void
    {
        $this->id = $id;
    }
    
    public function regenerateId(bool $deleteOld = true): bool
    {
        $oldId = $this->id;
        $this->id = $this->generateId();
        
        if ($deleteOld && $oldId !== '') {
            $this->storage->destroy($oldId);
        }
        
        $this->modified = true;
        return true;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $this->data[$key] ?? $default;
    }
    
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        $this->modified = true;
    }
    
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($this->data[$key]);
    }
    
    public function delete(string $key): void
    {
        $this->ensureStarted();
        unset($this->data[$key]);
        $this->modified = true;
    }
    
    public function all(): array
    {
        $this->ensureStarted();
        return $this->data;
    }
    
    public function clear(): void
    {
        $this->ensureStarted();
        $this->data = [];
        $this->modified = true;
    }
    
    public function save(): void
    {
        if (!$this->started) {
            return;
        }
        
        // 只有修改过才写入
        if ($this->modified) {
            $this->storage->write($this->id, $this->data, $this->ttl);
        }
        
        // 设置 Cookie
        Cookie::set(self::COOKIE_NAME, $this->id, $this->ttl);
    }
    
    public function destroy(): bool
    {
        if (!$this->started) {
            return false;
        }
        
        $this->storage->destroy($this->id);
        $this->data = [];
        $this->id = '';
        $this->started = false;
        
        // 删除 Cookie
        Cookie::delete(self::COOKIE_NAME);
        
        return true;
    }
    
    public function isStarted(): bool
    {
        return $this->started;
    }
    
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }
    
    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
```

---

## 4. 存储驱动实现

### 4.1 Redis 存储

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

/**
 * Redis Session 存储
 */
final class RedisStorage implements SessionStorageInterface
{
    private const PREFIX = 'session:';
    
    public function __construct(
        private readonly \Redis $redis,
    ) {}
    
    public function read(string $id): array
    {
        $data = $this->redis->get(self::PREFIX . $id);
        
        if ($data === false) {
            return [];
        }
        
        return json_decode($data, true) ?? [];
    }
    
    public function write(string $id, array $data, int $ttl): bool
    {
        return $this->redis->setex(
            self::PREFIX . $id,
            $ttl,
            json_encode($data)
        );
    }
    
    public function destroy(string $id): bool
    {
        return $this->redis->del(self::PREFIX . $id) > 0;
    }
    
    public function gc(int $maxLifetime): int
    {
        // Redis 自动过期，无需 GC
        return 0;
    }
    
    public function exists(string $id): bool
    {
        return $this->redis->exists(self::PREFIX . $id) > 0;
    }
}
```

### 4.2 数据库存储

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Framework\Database\DbManager\DbManagerInterface;

/**
 * 数据库 Session 存储
 */
final class DatabaseStorage implements SessionStorageInterface
{
    private const TABLE = 'weline_sessions';
    
    public function __construct(
        private readonly DbManagerInterface $db,
    ) {}
    
    public function read(string $id): array
    {
        $row = $this->db->table(self::TABLE)
            ->where('session_id', $id)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->find();
        
        if (!$row) {
            return [];
        }
        
        return json_decode($row['data'], true) ?? [];
    }
    
    public function write(string $id, array $data, int $ttl): bool
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $jsonData = json_encode($data);
        
        // 使用 REPLACE INTO 或 ON DUPLICATE KEY UPDATE
        return $this->db->table(self::TABLE)
            ->insertOrUpdate([
                'session_id' => $id,
                'data' => $jsonData,
                'expires_at' => $expiresAt,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
    
    public function destroy(string $id): bool
    {
        return $this->db->table(self::TABLE)
            ->where('session_id', $id)
            ->delete() > 0;
    }
    
    public function gc(int $maxLifetime): int
    {
        return $this->db->table(self::TABLE)
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();
    }
    
    public function exists(string $id): bool
    {
        return $this->db->table(self::TABLE)
            ->where('session_id', $id)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->count() > 0;
    }
}
```

### 4.3 内存存储（用于 FPM 兼容）

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

/**
 * 内存 Session 存储（仅用于 FPM 模式）
 * 
 * 包装 PHP 原生 Session
 */
final class NativeStorage implements SessionStorageInterface
{
    public function read(string $id): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id($id);
            session_start();
        }
        
        return $_SESSION ?? [];
    }
    
    public function write(string $id, array $data, int $ttl): bool
    {
        $_SESSION = $data;
        session_write_close();
        return true;
    }
    
    public function destroy(string $id): bool
    {
        session_destroy();
        return true;
    }
    
    public function gc(int $maxLifetime): int
    {
        return 0;
    }
    
    public function exists(string $id): bool
    {
        return true;
    }
}
```

---

## 5. Session 工厂

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RuntimeFactory;
use Weline\Framework\Session\Storage\DatabaseStorage;
use Weline\Framework\Session\Storage\NativeStorage;
use Weline\Framework\Session\Storage\RedisStorage;
use Weline\Framework\Session\Storage\SessionStorageInterface;

/**
 * Session 存储工厂
 */
final class SessionStorageFactory
{
    public static function create(): SessionStorageInterface
    {
        $runtime = RuntimeFactory::detectType();
        $config = Env::getInstance()->getConfig('session') ?? [];
        
        // 常驻模式强制使用外部存储
        if ($runtime->isPersistent()) {
            $driver = $config['driver'] ?? 'redis';
        } else {
            $driver = $config['driver'] ?? 'native';
        }
        
        return match($driver) {
            'redis' => self::createRedisStorage($config),
            'database' => self::createDatabaseStorage($config),
            'native' => new NativeStorage(),
            default => throw new \RuntimeException("Unknown session driver: {$driver}"),
        };
    }
    
    private static function createRedisStorage(array $config): RedisStorage
    {
        $redis = new \Redis();
        $redis->connect(
            $config['redis']['host'] ?? '127.0.0.1',
            $config['redis']['port'] ?? 6379
        );
        
        if (!empty($config['redis']['password'])) {
            $redis->auth($config['redis']['password']);
        }
        
        if (!empty($config['redis']['database'])) {
            $redis->select($config['redis']['database']);
        }
        
        return new RedisStorage($redis);
    }
    
    private static function createDatabaseStorage(array $config): DatabaseStorage
    {
        $db = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Database\DbManager\DbManagerInterface::class
        );
        
        return new DatabaseStorage($db);
    }
}
```

---

## 6. 配置示例

```php
<?php
// app/etc/env.php
return [
    'session' => [
        'driver' => 'redis', // native, redis, database
        'ttl' => 7200,       // 2小时
        
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
        ],
    ],
];
```

---

## 7. 待办事项

- [ ] 定义 `SessionInterface`
- [ ] 定义 `SessionStorageInterface`
- [ ] 实现 `Session` 类
- [ ] 实现 `RedisStorage`
- [ ] 实现 `DatabaseStorage`
- [ ] 实现 `NativeStorage`
- [ ] 实现 `SessionStorageFactory`
- [ ] 添加配置支持
- [ ] 编写单元测试

---

## 8. 相关文档

- [03-ObjectManager改造](03-ObjectManager改造.md) - DI 容器
- [04-请求响应抽象](04-请求响应抽象.md) - HTTP 抽象
- [08-全局状态隔离](08-全局状态隔离.md) - 状态隔离
