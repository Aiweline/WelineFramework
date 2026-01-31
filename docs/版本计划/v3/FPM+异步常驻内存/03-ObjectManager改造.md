# 03 - ObjectManager 改造

> **优先级**: ⭐⭐⭐⭐⭐  
> **依赖**: 01-架构设计, 02-运行时抽象层  
> **预计工作量**: 3-4 天

---

## 0. 与 WeAsync 的关系

在 **WeAsync**（照搬 Workerman）模式下，多个请求在同一个 Worker 进程中处理，ObjectManager 的静态实例会跨请求保留。因此需要改造以支持：

```
FPM 模式：每个请求一个进程，天然隔离
WeAsync 模式：多个请求共享一个进程，需要手动隔离

┌─────────────────────────────────────────────────────────────┐
│                    WeAsync Worker 进程                       │
├─────────────────────────────────────────────────────────────┤
│  全局单例（共享）      │  请求级实例（隔离）                   │
│  ├─ Router            │  ├─ Request                         │
│  ├─ Config            │  ├─ Response                        │
│  └─ EventsManager     │  └─ Session                         │
└─────────────────────────────────────────────────────────────┘
```

---

## 1. 概述

ObjectManager 是框架的 DI 容器核心，需要改造以支持：
- 请求级实例隔离（WeAsync 多请求共享进程）
- 应用级实例复用
- 状态可重置

### 1.1 当前问题

```php
// 当前实现：所有实例存储在静态变量中
private static array $instances = [];

// 问题 1：常驻模式下，请求 A 的 Request 对象会污染请求 B
// 问题 2：协程模式下，协程 A 和协程 B 共享同一个 Request 对象
```

### 1.2 改造目标

| 目标 | 描述 |
|------|------|
| **请求隔离** | Request/Session 等请求级实例每个请求独立 |
| **协程隔离** | 协程模式下每个协程有独立的请求上下文 |
| **应用复用** | Router/Config 等应用级实例全局共享 |
| **可重置** | 实现 `ResettableInterface`，支持状态重置 |

---

## 2. 实例范围定义

### 2.1 Scope 枚举

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Manager;

enum Scope: string
{
    /**
     * 全局单例：应用生命周期内只有一个实例
     * 存储在 ObjectManager::$singletonInstances
     */
    case SINGLETON = 'singleton';
    
    /**
     * 请求单例：每个请求一个实例
     * 存储在 RequestContext::$instances
     * 非协程模式使用
     */
    case REQUEST = 'request';
    
    /**
     * 协程单例：每个协程一个实例
     * 存储在 CoroutineContext::$instances
     * 协程模式下自动使用，替代 REQUEST
     */
    case COROUTINE = 'coroutine';
    
    /**
     * 瞬态：每次获取都创建新实例
     * 不缓存
     */
    case TRANSIENT = 'transient';
    
    /**
     * 池化：从连接池获取
     * 存储在 ConnectionPool
     */
    case POOLED = 'pooled';
}
```

### 2.2 InstanceScope 属性注解

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Manager\Attribute;

use Attribute;
use Weline\Framework\Manager\Scope;

/**
 * 标记类的实例范围
 * 
 * 用法：
 * #[InstanceScope(Scope::REQUEST)]
 * class Request { }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InstanceScope
{
    public function __construct(
        public readonly Scope $scope = Scope::SINGLETON,
    ) {}
}
```

### 2.3 框架类的默认范围

```php
<?php
// 配置文件：app/etc/di/scopes.php
return [
    // 请求级实例
    Scope::REQUEST->value => [
        \Weline\Framework\Http\Request::class,
        \Weline\Framework\Http\Response::class,
        \Weline\Framework\Session\Session::class,
        \Weline\Framework\Session\SessionManager::class,
        \Weline\Framework\Http\Cookie::class,
    ],
    
    // 全局单例（默认）
    Scope::SINGLETON->value => [
        \Weline\Framework\Router\Core::class,
        \Weline\Framework\Event\EventsManager::class,
        \Weline\Framework\App\Env::class,
        \Weline\Framework\Cache\CacheFactory::class,
    ],
    
    // 池化实例
    Scope::POOLED->value => [
        \PDO::class,
        \Redis::class,
    ],
    
    // 瞬态实例
    Scope::TRANSIENT->value => [
        \Weline\Framework\DataObject\DataObject::class,
    ],
];
```

---

## 3. ObjectManager 改造

### 3.1 新增属性

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Manager;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ResettableInterface;

class ObjectManager implements ManagerInterface, ResettableInterface
{
    /**
     * 全局单例实例（应用级）
     */
    private static array $singletonInstances = [];
    
    /**
     * 实例范围配置缓存
     */
    private static array $scopeConfig = [];
    
    /**
     * 类范围缓存（通过属性注解或配置解析）
     */
    private static array $classScopes = [];
    
    /**
     * 是否为常驻模式
     */
    private static bool $persistentMode = false;
    
    // ... 保留现有属性
}
```

### 3.2 改造 getInstance 方法

```php
<?php
use Weline\Framework\Coroutine\CoroutineContext;
use Fiber;

public static function getInstance(
    string $class = '', 
    array $arguments = [], 
    bool $shared = true, 
    bool $cache = false
): mixed {
    if ($class === '') {
        return self::$instance ??= new self();
    }
    
    self::$instance ??= new self();
    
    // 获取类的实例范围
    $scope = self::getClassScope($class);
    
    // REQUEST 和 COROUTINE 统一处理：自动检测当前环境
    if ($scope === Scope::REQUEST || $scope === Scope::COROUTINE) {
        return self::getContextInstance($class, $arguments);
    }
    
    return match($scope) {
        Scope::SINGLETON => self::getSingletonInstance($class, $arguments, $shared, $cache),
        Scope::TRANSIENT => self::createNewInstance($class, $arguments),
        Scope::POOLED => self::getPooledInstance($class, $arguments),
        default => self::getSingletonInstance($class, $arguments, $shared, $cache),
    };
}

/**
 * 获取上下文感知的实例（协程/请求）
 * 
 * 自动检测当前是否在协程中运行：
 * - 协程中：使用 CoroutineContext
 * - 非协程：使用 RequestContext
 */
private static function getContextInstance(string $class, array $arguments): mixed
{
    // 检测是否在协程中
    $fiber = Fiber::getCurrent();
    
    if ($fiber !== null) {
        // 协程环境：使用协程上下文
        return self::getCoroutineInstance($class, $arguments);
    }
    
    // 非协程环境：使用请求上下文
    return self::getRequestInstance($class, $arguments);
}

/**
 * 获取协程级实例
 */
private static function getCoroutineInstance(string $class, array $arguments): mixed
{
    $context = CoroutineContext::current();
    
    if ($context === null) {
        // 协程上下文不存在，创建临时实例
        return self::createNewInstance($class, $arguments);
    }
    
    // 检查协程上下文中是否已存在
    if ($context->hasInstance($class)) {
        return $context->getInstance($class);
    }
    
    // 创建新实例并存储到协程上下文
    $instance = self::createNewInstance($class, $arguments);
    $context->setInstance($class, $instance);
    
    return $instance;
}

/**
 * 获取类的实例范围
 */
private static function getClassScope(string $class): Scope
{
    // 优先使用缓存
    if (isset(self::$classScopes[$class])) {
        return self::$classScopes[$class];
    }
    
    // 1. 检查配置文件
    $scope = self::getScopeFromConfig($class);
    if ($scope !== null) {
        return self::$classScopes[$class] = $scope;
    }
    
    // 2. 检查属性注解
    $scope = self::getScopeFromAttribute($class);
    if ($scope !== null) {
        return self::$classScopes[$class] = $scope;
    }
    
    // 3. 默认为单例
    return self::$classScopes[$class] = Scope::SINGLETON;
}

/**
 * 获取全局单例实例
 */
private static function getSingletonInstance(
    string $class, 
    array $arguments, 
    bool $shared, 
    bool $cache
): mixed {
    if ($shared && isset(self::$singletonInstances[$class])) {
        return self::$singletonInstances[$class];
    }
    
    $instance = self::createNewInstance($class, $arguments);
    
    if ($shared) {
        self::$singletonInstances[$class] = $instance;
    }
    
    return $instance;
}

/**
 * 获取请求级实例
 */
private static function getRequestInstance(string $class, array $arguments): mixed
{
    $context = RequestContext::current();
    
    // 检查请求上下文中是否已存在
    if ($context->hasInstance($class)) {
        return $context->getInstance($class);
    }
    
    // 创建新实例并存储到请求上下文
    $instance = self::createNewInstance($class, $arguments);
    $context->setInstance($class, $instance);
    
    return $instance;
}

/**
 * 创建新实例（通用逻辑）
 */
private static function createNewInstance(string $class, array $arguments): mixed
{
    // 现有的实例化逻辑
    $new_class = self::parserClass($class);
    $arguments = self::resolveConstructorArguments($new_class, $arguments);
    $refClass = self::getReflectionInstance($new_class);
    $new_object = self::instantiateObject($refClass, $new_class, $arguments);
    return self::initClassInstance($class, $new_object);
}

/**
 * 获取池化实例
 */
private static function getPooledInstance(string $class, array $arguments): mixed
{
    // 从连接池获取
    $pool = ConnectionPoolManager::getPool($class);
    return $pool->acquire();
}
```

### 3.3 实现 ResettableInterface

```php
<?php
/**
 * 重置请求级状态
 */
public function reset(): void
{
    // 注意：不清理 $singletonInstances，只清理请求级状态
    // RequestContext 的清理由 Runtime 负责
}

/**
 * 重置所有状态（用于测试或进程退出）
 */
public static function resetAll(): void
{
    self::$singletonInstances = [];
    self::$instances = []; // 兼容旧代码
    self::$reflections = [];
    self::$classScopes = [];
    self::$methodParamsMetadata = [];
    // ... 其他静态缓存
}

/**
 * 重置请求级实例（供 Runtime 调用）
 */
public static function resetRequestInstances(): void
{
    // 请求级实例存储在 RequestContext 中
    // 由 RequestContext::destroy() 清理
    
    // 清理可能污染的缓存
    self::$methodParamsMetadata = [];
}

/**
 * 获取状态信息（用于调试）
 */
public function getStateInfo(): array
{
    return [
        'singleton_count' => count(self::$singletonInstances),
        'singleton_classes' => array_keys(self::$singletonInstances),
        'reflection_count' => count(self::$reflections),
        'scope_cache_count' => count(self::$classScopes),
    ];
}
```

### 3.4 设置常驻模式

```php
<?php
/**
 * 启用常驻模式
 * 
 * 在常驻模式下：
 * - 请求级实例存储在 RequestContext
 * - 单例实例全局共享
 */
public static function enablePersistentMode(): void
{
    self::$persistentMode = true;
}

/**
 * 检查是否为常驻模式
 */
public static function isPersistentMode(): bool
{
    return self::$persistentMode;
}
```

---

## 4. 从配置和属性读取范围

### 4.1 从配置文件读取

```php
<?php
private static function getScopeFromConfig(string $class): ?Scope
{
    if (empty(self::$scopeConfig)) {
        $configFile = APP_ETC_PATH . 'di/scopes.php';
        if (is_file($configFile)) {
            self::$scopeConfig = require $configFile;
        }
    }
    
    foreach (self::$scopeConfig as $scopeValue => $classes) {
        if (in_array($class, $classes, true)) {
            return Scope::from($scopeValue);
        }
    }
    
    return null;
}
```

### 4.2 从属性注解读取

```php
<?php
private static function getScopeFromAttribute(string $class): ?Scope
{
    try {
        $refClass = self::getReflectionInstance($class);
        $attributes = $refClass->getAttributes(
            Attribute\InstanceScope::class
        );
        
        if (!empty($attributes)) {
            /** @var Attribute\InstanceScope $instanceScope */
            $instanceScope = $attributes[0]->newInstance();
            return $instanceScope->scope;
        }
    } catch (\ReflectionException) {
        // 忽略
    }
    
    return null;
}
```

---

## 5. 使用示例

### 5.1 标记请求级类

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Manager\Attribute\InstanceScope;
use Weline\Framework\Manager\Scope;

#[InstanceScope(Scope::REQUEST)]
class Request
{
    // 每个请求都会创建新实例
}
```

### 5.2 标记瞬态类

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\DataObject;

use Weline\Framework\Manager\Attribute\InstanceScope;
use Weline\Framework\Manager\Scope;

#[InstanceScope(Scope::TRANSIENT)]
class DataObject
{
    // 每次获取都创建新实例
}
```

### 5.3 默认单例（不需要标记）

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Router;

// 不标记默认为 SINGLETON
class Core
{
    // 全局共享一个实例
}
```

---

## 6. 兼容性处理

### 6.1 保持旧 API 兼容

```php
<?php
// 旧代码仍然可用
$request = ObjectManager::getInstance(Request::class);

// 在 FPM 模式下行为不变
// 在常驻模式下自动使用 RequestContext
```

### 6.2 迁移策略

1. **阶段一**：添加新代码，保持旧行为
2. **阶段二**：为框架核心类添加 `#[InstanceScope]` 注解
3. **阶段三**：启用常驻模式时激活新行为

---

## 7. 待办事项

- [ ] 定义 `Scope` 枚举
- [ ] 定义 `InstanceScope` 属性注解
- [ ] 改造 `getInstance()` 方法
- [ ] 实现 `ResettableInterface`
- [ ] 添加范围配置文件
- [ ] 为框架类添加范围注解
- [ ] 编写单元测试
- [ ] 编写迁移文档

---

## 8. 相关文档

- [01-架构设计](01-架构设计.md) - 整体架构
- [02-运行时抽象层](02-运行时抽象层.md) - 运行时接口
- [08-全局状态隔离](08-全局状态隔离.md) - 状态隔离
