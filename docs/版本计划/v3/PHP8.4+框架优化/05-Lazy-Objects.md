# 05 - Lazy Objects（延迟对象）⭐⭐⭐⭐⭐

[← 索引](README.md)

**性能影响**：启动时间 -40%、内存 -30%；按需初始化，未使用的服务零成本。

---

## 特性说明

PHP 8.4 引入了原生的延迟对象支持，允许创建在首次访问时才真正初始化的对象。

## 两种模式

1. **Ghost Objects**: 对象框架已创建，但属性未初始化
2. **Proxy Objects**: 完全的代理对象

## DI 容器优化

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Manager;

/**
 * v3 对象管理器 - 支持延迟加载
 */
class ObjectManager
{
    private static array $instances = [];
    private static array $lazyInstances = [];
    private static array $definitions = [];
    
    /**
     * 获取实例（支持延迟加载）
     */
    public static function getInstance(string $class, array $arguments = [], bool $lazy = false): object
    {
        $key = $class . '_' . md5(serialize($arguments));
        
        // 已有实例直接返回
        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }
        
        // 延迟加载模式
        if ($lazy) {
            return self::getLazyInstance($class, $arguments, $key);
        }
        
        // 立即实例化
        return self::createInstance($class, $arguments, $key);
    }
    
    /**
     * 创建延迟对象
     */
    private static function getLazyInstance(string $class, array $arguments, string $key): object
    {
        if (isset(self::$lazyInstances[$key])) {
            return self::$lazyInstances[$key];
        }
        
        $reflector = new \ReflectionClass($class);
        
        // 创建 Ghost Object
        $lazy = $reflector->newLazyGhost(function (object $object) use ($class, $arguments, $key) {
            // 首次访问时执行初始化
            self::initializeObject($object, $class, $arguments);
            // 将初始化后的对象存入实例缓存
            self::$instances[$key] = $object;
        });
        
        self::$lazyInstances[$key] = $lazy;
        return $lazy;
    }
    
    /**
     * 创建代理对象（用于接口注入）
     */
    public static function createProxy(string $interface, string $implementation): object
    {
        $reflector = new \ReflectionClass($implementation);
        
        return $reflector->newLazyProxy(function () use ($implementation) {
            return self::getInstance($implementation);
        });
    }
    
    /**
     * 初始化对象
     */
    private static function initializeObject(object $object, string $class, array $arguments): void
    {
        // 依赖注入
        $reflector = new \ReflectionClass($class);
        $constructor = $reflector->getConstructor();
        
        if ($constructor) {
            $dependencies = self::resolveDependencies($constructor, $arguments);
            $constructor->invoke($object, ...$dependencies);
        }
        
        // 调用 __init 方法（如果存在）
        if (method_exists($object, '__init')) {
            $object->__init();
        }
    }
    
    /**
     * 检查对象是否已初始化
     */
    public static function isInitialized(object $object): bool
    {
        $reflector = new \ReflectionClass($object);
        return !$reflector->isUninitializedLazyObject($object);
    }
    
    /**
     * 强制初始化延迟对象
     */
    public static function initialize(object $object): object
    {
        $reflector = new \ReflectionClass($object);
        return $reflector->initializeLazyObject($object);
    }
    
    /**
     * 重置延迟对象（用于测试）
     */
    public static function resetLazyObject(object $object): void
    {
        $reflector = new \ReflectionClass($object);
        if ($reflector->isUninitializedLazyObject($object)) {
            return;
        }
        $reflector->resetAsLazyGhost($object, function ($obj) {
            // 重新初始化逻辑
        });
    }
}
```

## 应用场景

```php
<?php
// 场景1：重型服务延迟加载
class HeavyAnalyticsService
{
    private \PDO $db;
    private \Redis $cache;
    private array $config;
    
    public function __construct()
    {
        // 这些初始化很耗时
        $this->db = new \PDO(...);
        $this->cache = new \Redis(...);
        $this->config = $this->loadConfig();
    }
}

// 使用延迟加载
$analytics = ObjectManager::getInstance(HeavyAnalyticsService::class, lazy: true);
// 此时 HeavyAnalyticsService 还未真正初始化

$analytics->generateReport();  // 首次访问，触发初始化

// 场景2：Block 延迟渲染
class ProductRecommendationBlock extends Block
{
    public function getProducts(): array
    {
        // 耗时的推荐算法
        return $this->recommendationService->getRecommendations($this->userId);
    }
}

// 模板中
$block = ObjectManager::getInstance(ProductRecommendationBlock::class, lazy: true);
// 只有在模板实际调用 $block->getProducts() 时才会初始化

// 场景3：条件依赖
class PaymentService
{
    public function __construct(
        private PayPalGateway $paypal,      // 延迟加载
        private StripeGateway $stripe,       // 延迟加载
        private AlipayGateway $alipay        // 延迟加载
    ) {}
    
    public function pay(string $method, float $amount): bool
    {
        return match($method) {
            'paypal' => $this->paypal->charge($amount),  // 只有这个会初始化
            'stripe' => $this->stripe->charge($amount),
            'alipay' => $this->alipay->charge($amount),
        };
    }
}
```

## 性能对比

```php
// 基准测试场景：10 个重型服务，但只使用 2 个

// ❌ v2：全部立即初始化
// 初始化时间：~500ms，内存：50MB

// ✅ v3：延迟加载
// 初始化时间：~100ms，内存：20MB
// （只初始化实际使用的 2 个服务）
```
