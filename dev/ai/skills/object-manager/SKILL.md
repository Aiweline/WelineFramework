# ObjectManager 和依赖注入技能

## 触发关键词

ObjectManager, 依赖注入, DI, Dependency Injection, getInstance, Factory, 工厂, 单例, singleton, 非单例, __init, 初始化方法, FactoryObjectInterface, 对象管理, 容器, container

## 适用场景

- 获取类实例（依赖注入）
- 创建 Factory 工厂类
- 单例 vs 非单例模式选择
- `__init()` 初始化方法使用
- 接口到实现类的映射

---

## 1. ObjectManager 基础用法

### 1.1 获取 ObjectManager 实例

```php
use Weline\Framework\Manager\ObjectManager;

$objectManager = ObjectManager::getInstance();
```

### 1.2 获取类实例

```php
// 单例模式（默认）- 推荐
$service = ObjectManager::getInstance(MyService::class);

// 非单例模式 - 每次创建新实例
$model = ObjectManager::getInstance(MyModel::class, [], false);

// 带参数创建
$config = ObjectManager::getInstance(ConfigProvider::class, ['db_config' => $dbConf]);
```

### 1.3 方法签名

```php
public static function getInstance(
    string $class = '',       // 类名（空返回 ObjectManager 自身）
    array $arguments = [],    // 构造函数额外参数
    bool $shared = true,      // 是否共享实例（单例）
    bool $cache = false       // 是否使用文件缓存
): mixed
```

---

## 2. 构造函数依赖注入

### 2.1 基本规范

框架自动解析构造函数参数并注入依赖：

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Cache\CacheInterface;
use Weline\YourModule\Model\YourModel;

class YourService
{
    private Request $request;
    private CacheInterface $cache;
    private YourModel $model;

    public function __construct(
        Request $request,
        CacheInterface $cache,  // 接口会通过 Factory 解析
        YourModel $model,
        array $data = []        // 可选参数，有默认值
    ) {
        $this->request = $request;
        $this->cache = $cache;
        $this->model = $model;
    }
}
```

### 2.2 PHP 8+ 构造函数属性提升

```php
public function __construct(
    private readonly Request $request,
    private readonly CacheInterface $cache,
    private readonly YourModel $model
) {
}
```

### 2.3 依赖注入规则

| 规则 | 说明 |
|------|------|
| 类型提示 | 使用类型提示，框架自动解析 |
| 接口注入 | 接口通过 `InterfaceFactory` 解析 |
| 默认值 | 有默认值的参数使用默认值 |
| 必需依赖 | 无默认值的依赖自动注入 |

---

## 3. 单例 vs 非单例

### 3.1 单例模式（默认）

```php
// shared = true（默认），同一请求内返回相同实例
$service = ObjectManager::getInstance(MyService::class);
$service2 = ObjectManager::getInstance(MyService::class);
// $service === $service2 为 true
```

### 3.2 非单例模式

```php
// shared = false，每次创建新实例
$model = ObjectManager::getInstance(MyModel::class, [], false);
```

### 3.3 选择指南

| 场景 | 模式 | 理由 |
|------|------|------|
| Service（无状态） | 单例 | 性能优化 |
| Model（需独立状态） | 非单例 | 避免状态污染 |
| Helper/Utility | 单例 | 工具类无状态 |
| Block（视图块） | 单例 | 通常无状态 |

### 3.4 Model 使用示例

```php
// 在 Service 中查询多个独立的 Model
public function getOrders(int $userId): array
{
    // 每次获取独立实例
    $user = ObjectManager::getInstance(User::class, [], false);
    $user->load($userId);

    $order = ObjectManager::getInstance(Order::class, [], false);
    return $order->where('user_id', $userId)->select()->fetch()->getItems();
}
```

---

## 4. Factory 工厂类

### 4.1 接口工厂（FactoryObjectInterface）

将接口映射到具体实现：

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

class YourServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): YourServiceInterface
    {
        return ObjectManager::getInstance(YourServiceImpl::class);
    }
}
```

### 4.2 命名规则

| 接口 | 工厂类 |
|------|-------|
| `CacheInterface` | `CacheInterfaceFactory` |
| `YourServiceInterface` | `YourServiceInterfaceFactory` |

### 4.3 自动处理机制

- 以 `Factory` 结尾的类会被自动检测
- 如果实现了 `FactoryObjectInterface`，自动调用 `create()` 方法
- 返回值作为实际注入的实例

### 4.4 缓存工厂

继承 `CacheFactory` 创建模块缓存：

```php
class MyModuleCacheFactory extends CacheFactory
{
    public function __construct(string $identity = 'my_module', string $tip = '', bool $permanently = false)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
```

---

## 5. `__init()` 初始化方法

### 5.1 定义与作用

`__init()` 是框架提供的初始化方法，在 `__construct()` 之后自动调用。

**必须通过 `ObjectManager::getInstance()` 创建实例才会调用！**

### 5.2 调用时机

```
__construct() → 依赖注入完成 → __init() 调用
```

### 5.3 典型用法

```php
class MyService
{
    private MyDependency $dep;
    private ?SomeData $data = null;

    // 构造函数：接收依赖
    public function __construct(MyDependency $dep, array $config = [])
    {
        $this->dep = $dep;
    }

    // __init()：业务初始化（需要使用注入的依赖）
    public function __init()
    {
        // 在这里可以安全地使用 $this->dep
        $this->data = $this->dep->loadSomeData();
    }
}
```

### 5.4 Block 中的 `__init()`

Block 类必须调用 `parent::__init()`：

```php
public function __init()
{
    parent::__init();  // 必须调用！
    
    // 初始化逻辑
    $this->assign('data', $this->loadData());
}
```

### 5.5 `__construct()` vs `__init()` 对比

| 特性 | `__construct()` | `__init()` |
|------|-----------------|------------|
| 调用时机 | 对象创建时 | `__construct()` 后 |
| 参数 | 支持依赖注入 | 无参数 |
| 典型用途 | 接收依赖、初始化属性 | 业务初始化、加载数据 |
| 调用父类 | `parent::__construct()` | `parent::__init()` |

---

## 6. 常见错误

### 6.1 直接 new 而不用 ObjectManager

```php
// ❌ 错误：依赖不会自动注入
$service = new MyService();

// ✅ 正确：使用 ObjectManager
$service = ObjectManager::getInstance(MyService::class);
```

### 6.2 Model 使用单例导致状态污染

```php
// ❌ 错误：单例模式下 Model 状态会污染
$user1 = ObjectManager::getInstance(User::class);
$user1->load(1);

$user2 = ObjectManager::getInstance(User::class);
// $user2 === $user1，已经加载了 ID=1 的数据！

// ✅ 正确：使用非单例
$user1 = ObjectManager::getInstance(User::class, [], false);
$user1->load(1);

$user2 = ObjectManager::getInstance(User::class, [], false);
$user2->load(2);  // 独立实例
```

### 6.3 忘记调用 parent::__init()

```php
// ❌ 错误：忘记调用父类
public function __init()
{
    $this->assign('data', $this->loadData());
}

// ✅ 正确
public function __init()
{
    parent::__init();
    $this->assign('data', $this->loadData());
}
```

### 6.4 Factory 类没有实现接口

```php
// ❌ 错误：没有实现 FactoryObjectInterface
class MyInterfaceFactory
{
    public function create() { ... }
}

// ✅ 正确
class MyInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MyInterface { ... }
}
```

---

## 7. 规范总结

| 项目 | 规范 |
|------|------|
| 获取实例 | `ObjectManager::getInstance(Class::class)` |
| 单例 | Service、Helper、Block 等无状态类 |
| 非单例 | Model 等需要独立状态的类 |
| 接口注入 | 创建 `InterfaceFactory` 实现 `FactoryObjectInterface` |
| 初始化 | 使用 `__init()` 进行业务初始化 |
| 父类调用 | `__construct` 和 `__init` 都要调用父类方法 |
