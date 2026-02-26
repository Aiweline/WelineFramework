# 缓存使用技能

## 触发关键词

Cache, 缓存, CacheFactory, CacheInterface, 缓存清理, cache:clear, 缓存驱动, Redis, File cache, 缓存过期, TTL, 持久化缓存, permanently

## 适用场景

- 创建模块缓存类
- 使用缓存读写数据
- 缓存清理和刷新
- 配置缓存驱动

---

## 1. 缓存架构

### 1.1 核心类

- **CacheFactory**：缓存工厂基类，继承创建模块专属缓存
- **CacheInterface**：缓存驱动接口，定义缓存操作方法
- **File/Redis/...**：具体缓存驱动实现

### 1.2 继承关系

```
CacheFactory (缓存工厂)
    └── YourModuleCache (模块缓存类)
            └── create() → CacheInterface (缓存驱动实例)
```

---

## 2. 创建模块缓存类

### 2.1 基础缓存类

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Cache;

use Weline\Framework\Cache\CacheFactory;

class YourModuleCache extends CacheFactory
{
    public function __construct(
        string $identity = 'your_module_cache',  // 缓存唯一标识
        string $tip = '您的模块缓存',             // 缓存说明
        bool $permanently = false                 // 是否持久化
    ) {
        parent::__construct($identity, $tip, $permanently);
    }
}
```

### 2.2 构造函数参数说明

| 参数 | 说明 |
|------|------|
| `$identity` | 缓存唯一标识，区分不同缓存实例 |
| `$tip` | 缓存说明，显示在后台管理界面 |
| `$permanently` | 是否持久化，`true` 时需 `cache:clean -f` 才能清理 |

### 2.3 持久化缓存示例

```php
class EventCache extends CacheFactory
{
    public function __construct(string $identity = 'framework_event')
    {
        parent::__construct($identity, '事件缓存', true);  // 持久化
    }
}
```

---

## 3. 使用缓存

### 3.1 在 Service 中使用

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Service;

use Weline\Framework\Cache\CacheInterface;
use Weline\YourModule\Cache\YourModuleCache;

class YourService
{
    private CacheInterface $cache;
    
    public function __construct(YourModuleCache $cacheFactory)
    {
        $this->cache = $cacheFactory->create();
    }
    
    public function getData(string $key): mixed
    {
        // 尝试从缓存获取
        $cached = $this->cache->get($key);
        if ($cached !== false) {
            return $cached;
        }
        
        // 缓存未命中，从数据源获取
        $data = $this->fetchFromDatabase($key);
        
        // 缓存结果（1小时）
        $this->cache->set($key, $data, 3600);
        
        return $data;
    }
}
```

### 3.2 直接实例化使用

```php
use Weline\Framework\Cache\CacheFactory;

$cacheFactory = new CacheFactory('my_cache', '我的缓存');
$cache = $cacheFactory->create();

$cache->set('key', 'value', 3600);
$value = $cache->get('key');
```

---

## 4. CacheInterface 方法

| 方法 | 说明 | 示例 |
|------|------|------|
| `get($key)` | 获取缓存 | `$cache->get('user_1')` |
| `set($key, $value, $ttl)` | 设置缓存 | `$cache->set('user_1', $data, 3600)` |
| `add($key, $value, $ttl)` | 添加缓存（仅当不存在） | `$cache->add('lock', 1, 60)` |
| `exists($key)` | 检查缓存存在 | `$cache->exists('user_1')` |
| `delete($key)` | 删除缓存 | `$cache->delete('user_1')` |
| `clear()` | 清理所有键值 | `$cache->clear()` |
| `flush()` | 刷新缓存（删除所有文件） | `$cache->flush()` |
| `getStats()` | 获取缓存统计 | `$cache->getStats()` |

### 4.1 TTL 默认值

```php
// 默认 1800 秒（30分钟）
$cache->set($key, $data);

// 自定义 TTL
$cache->set($key, $data, 3600);      // 1小时
$cache->set($key, $data, 86400);     // 1天
$cache->set($key, $data, 31536000);  // 1年
```

---

## 5. 多层缓存策略

### 5.1 请求内静态缓存 + 文件缓存

```php
class DataService
{
    private static ?array $staticCache = null;
    private CacheInterface $cache;
    
    public function getData(): ?array
    {
        // 第一层：请求内静态缓存（最快）
        if (self::$staticCache !== null) {
            return self::$staticCache;
        }
        
        // 第二层：文件/Redis 缓存
        $cacheKey = 'data_cache_key';
        if ($this->cache->exists($cacheKey)) {
            $data = $this->cache->get($cacheKey);
            self::$staticCache = $data;
            return $data;
        }
        
        // 缓存未命中，查询数据库
        $data = $this->queryDatabase();
        
        // 写入两层缓存
        $this->cache->set($cacheKey, $data, 3600);
        self::$staticCache = $data;
        
        return $data;
    }
}
```

### 5.2 WLS 模式下静态缓存重置

```php
// 在 StateManager 中注册重置
StateManager::register(DataService::class, function() {
    DataService::resetStaticCache();
});

// 在类中实现重置方法
public static function resetStaticCache(): void
{
    self::$staticCache = null;
}
```

---

## 6. 缓存键命名规范

### 6.1 格式

```
{模块标识}_{功能}_{唯一标识}
```

### 6.2 示例

```php
// ✅ 正确：带前缀的唯一键
$cache->set('order_detail_' . $orderId, $data);
$cache->set('user_profile_' . $userId, $data);
$cache->set('product_list_category_' . $categoryId, $data);

// ❌ 错误：通用键名可能冲突
$cache->set('config', $data);
$cache->set('list', $data);
```

---

## 7. 缓存清理

### 7.1 命令行清理

```bash
# 清理所有缓存（非持久化）
php bin/w cache:clear

# 强制清理所有缓存（包括持久化）
php bin/w cache:clear -f

# 清理指定缓存
php bin/w cache:clear -i your_module_cache
```

### 7.2 代码中清理

```php
// 清理模块缓存
$cacheFactory = new YourModuleCache();
$cache = $cacheFactory->create();
$cache->clear();  // 清理所有键值

// 删除特定键
$cache->delete('specific_key');

// 刷新缓存文件
$cache->flush();
```

---

## 8. 缓存配置

### 8.1 env.php 缓存配置

```php
// app/etc/env.php
return [
    'cache' => [
        'default' => 'file',  // 默认驱动
        'drivers' => [
            'file' => [
                'path' => 'var/cache/',
            ],
            'redis' => [
                'server' => '127.0.0.1',
                'port' => 6379,
                'database' => 1,
            ],
        ],
        'status' => [
            'your_module_cache' => 1,  // 1=启用，0=禁用
            'framework_controller' => 1,
            'router_cache' => 1,
        ],
    ],
];
```

### 8.2 指定缓存驱动

```php
// 创建时指定驱动
$cache = $cacheFactory->create('redis');

// 使用默认驱动
$cache = $cacheFactory->create();
```

---

## 9. 框架内置缓存类

| 缓存类 | 标识 | 说明 |
|-------|------|------|
| `EventCache` | `framework_event` | 事件缓存（持久化） |
| `ControllerCache` | `framework_controller` | 控制器缓存 |
| `RouterCache` | `router_cache` | 路由缓存 |
| `ViewCache` | `framework_view` | 视图缓存 |
| `PhraseCache` | `framework_phrase` | 翻译缓存 |
| `PluginCache` | `framework_plugin` | 插件缓存 |

---

## 10. 常见错误

### 10.1 缓存键冲突

```php
// ❌ 错误：通用键名
$cache->set('config', $data);

// ✅ 正确：带前缀的唯一键
$cache->set('your_module_config_' . $configId, $data);
```

### 10.2 未检查缓存返回值

```php
// ❌ 错误：未区分 false 和空值
$data = $cache->get($key);
if (!$data) { ... }  // 如果缓存值是空数组，会误判

// ✅ 正确：使用 exists 或严格比较
if ($cache->exists($key)) {
    $data = $cache->get($key);
}

// 或
$data = $cache->get($key);
if ($data !== false) { ... }
```

### 10.3 WLS 下静态缓存泄漏

```php
// ❌ 错误：静态缓存可能跨请求泄漏
private static $cache = [];

// ✅ 正确：注册到 StateManager 重置
// 参见 weline-server 技能
```

### 10.4 缓存过期时间不合理

```php
// ❌ 错误：永久缓存可能导致数据过期
$cache->set($key, $data);  // 默认 1800 秒

// ✅ 正确：根据业务设置合理的 TTL
$cache->set($key, $data, 300);    // 高频更新数据：5分钟
$cache->set($key, $data, 3600);   // 普通数据：1小时
$cache->set($key, $data, 86400);  // 低频更新数据：1天
```

---

## 11. 最佳实践总结

| 项目 | 规范 |
|------|------|
| 缓存类命名 | `YourModuleCache` 继承 `CacheFactory` |
| 缓存标识 | 小写下划线格式：`your_module_cache` |
| 缓存键 | 带模块前缀：`module_function_id` |
| TTL 设置 | 根据数据更新频率合理设置 |
| 持久化 | 框架核心缓存用 `$permanently = true` |
| WLS 兼容 | 静态缓存需注册 StateManager 重置 |
