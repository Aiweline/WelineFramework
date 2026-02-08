# Weline_Framework 模块扩展文档

## 概述

Weline_Framework 模块提供了多个扩展点，允许其他模块扩展缓存和 Session 驱动功能。本文档详细说明如何使用这些扩展点。

## 快速开始

### 1. 创建扩展目录

在您的模块中创建以下目录结构：

```
app/code/YourModule/
└── extends/
    └── module/
        └── Weline_Framework/
            ├── Cache/
            │   └── YourCacheDriver.php
            └── Session/
                └── YourSessionDriver.php
```

### 2. 实现缓存驱动

创建缓存驱动类，继承 `File` 驱动或实现 `CacheDriverInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Extends\Module\Weline_Framework\Cache;

use Weline\Framework\Cache\Driver\File;

class YourCacheDriver extends File
{
    // 重写需要的方法
    public function get(string $key): mixed
    {
        // 自定义实现
        return parent::get($key);
    }
    
    public function set(string $key, mixed $value, int $duration = 1800): bool
    {
        // 自定义实现
        return parent::set($key, $value, $duration);
    }
}
```

### 3. 实现 Session 驱动

创建 Session 驱动类，继承 `File` 驱动或实现 `SessionDriverHandlerInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Extends\Module\Weline_Framework\Session;

use Weline\Framework\Session\Driver\File;

class YourSessionDriver extends File
{
    // 重写需要的方法
    public function set($name, $value): bool
    {
        // 自定义实现
        return parent::set($name, $value);
    }
    
    public function get($name = null): mixed
    {
        // 自定义实现
        return parent::get($name);
    }
}
```

## 详细说明

### Cache 缓存驱动扩展点

**路径**: `extends/module/Weline_Framework/Cache`

**接口**: `Weline\Framework\Cache\CacheDriverInterface`

**用途**: 扩展或替换缓存驱动实现，例如：
- 在 WLS 常驻内存模式下使用进程内内存缓存
- 实现 Redis、Memcached 等分布式缓存
- 添加缓存监控、统计功能

**要求**:
- 必须实现 `CacheDriverInterface` 接口或继承 `File` 驱动
- 必须实现所有接口方法
- 允许多个实现

#### 接口方法说明

- **get(string $key): mixed** - 获取缓存值
- **set(string $key, mixed $value, int $duration = 1800): bool** - 设置缓存值
- **exists(string $key): bool** - 检查缓存是否存在
- **delete(string $key): bool** - 删除缓存
- **flush(): bool** - 清空当前 identity 的所有缓存
- **clear(): bool** - 清理缓存

### Session 驱动扩展点

**路径**: `extends/module/Weline_Framework/Session`

**接口**: `Weline\Framework\Session\Driver\SessionDriverHandlerInterface`

**用途**: 扩展或替换 Session 驱动实现，例如：
- 在 WLS 常驻内存模式下使用进程内内存 Session
- 实现 Redis、数据库等分布式 Session
- 添加 Session 安全、加密功能

**要求**:
- 必须实现 `SessionDriverHandlerInterface` 接口或继承 `File` 驱动
- 必须实现所有接口方法
- 允许多个实现

#### 接口方法说明

- **set($name, $value): bool** - 设置 Session 值
- **get($name = null): mixed** - 获取 Session 值
- **delete($name): bool** - 删除 Session 值
- **getSessionId(): string** - 获取 Session ID
- **destroy(string $session_id): bool** - 销毁 Session
- **read(string $id): string|false** - 读取 Session 数据（SessionHandlerInterface）
- **write(string $id, string $session_data): bool** - 写入 Session 数据（SessionHandlerInterface）

## 使用场景

### 场景1：WLS 内存缓存接管

在 WLS 常驻内存模式下，将 File 缓存替换为进程内内存缓存，大幅提升性能：

```php
<?php
namespace Weline\Server\Extends\Module\Weline_Framework\Cache;

use Weline\Framework\Cache\Driver\File;

class WlsMemoryCache extends File
{
    private static array $memoryStore = [];
    
    public function get(string $key): mixed
    {
        $k = $this->buildKey($key);
        // 优先内存
        if (isset(self::$memoryStore[$this->identity][$k])) {
            return self::$memoryStore[$this->identity][$k]['v'];
        }
        // 回退文件
        $value = parent::get($key);
        if ($value !== false) {
            self::$memoryStore[$this->identity][$k] = ['v' => $value, 'e' => time() + 1800];
        }
        return $value;
    }
    
    public function set(string $key, mixed $value, int $duration = 1800): bool
    {
        $k = $this->buildKey($key);
        // 写内存 + 写文件
        self::$memoryStore[$this->identity][$k] = ['v' => $value, 'e' => time() + $duration];
        return parent::set($key, $value, $duration);
    }
}
```

### 场景2：通过事件接管驱动

通过监听 `driver_create_before` 事件，在特定条件下替换驱动：

```php
// event.xml
<event name="Weline_Framework_Cache::driver_create_before">
    <observer name="YourModule::cache_interceptor"
              instance="YourModule\Observer\CacheDriverInterceptor"/>
</event>

// Observer
class CacheDriverInterceptor implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (/* 你的条件 */) {
            $event->setData('driver_class', YourCacheDriver::class);
        }
    }
}
```

## 最佳实践

1. **命名规范**: 使用清晰、唯一的类名
2. **继承优先**: 建议继承 `File` 驱动，复用已有逻辑
3. **错误处理**: 完善异常处理和错误消息
4. **文档注释**: 为所有方法添加详细的文档注释
5. **双写策略**: 如使用内存缓存，建议同时写入持久化存储以保证重启后可恢复

## 常见问题

### Q: 如何知道我的扩展是否被加载？

A: 系统会在 `generated/extends.php` 文件中记录所有扩展信息，您可以查看该文件确认。

### Q: 扩展驱动和配置的驱动冲突怎么办？

A: 扩展驱动通过事件机制接管，优先级高于配置。您可以在 Observer 中添加条件判断。

## 相关文档

详细开发文档请参考：`doc/扩展开发文档.md`
