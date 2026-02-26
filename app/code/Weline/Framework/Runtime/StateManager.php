<?php
declare(strict_types=1);

/**
 * Weline Framework - 状态管理器
 * 
 * 管理 WLS 模式下需要在请求间重置的静态状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

/**
 * 状态管理器
 * 
 * 功能：
 * - 追踪需要重置的静态变量
 * - 请求结束时调用 reset() 方法
 * - 清理请求级缓存和状态
 * - 自动重置已注册的静态变量
 */
class StateManager
{
    /**
     * 实例范围常量
     */
    public const SCOPE_SINGLETON = 'singleton';  // 进程级单例（默认）
    public const SCOPE_REQUEST = 'request';       // 请求级实例，请求结束销毁
    public const SCOPE_TRANSIENT = 'transient';   // 每次获取新实例
    public const SCOPE_POOLED = 'pooled';         // 对象池复用
    
    /**
     * 已注册的重置回调
     * 格式：['name' => callable]
     */
    private static array $resetCallbacks = [];
    
    /**
     * 请求级实例
     * 格式：['className' => instance]
     */
    private static array $requestScopedInstances = [];
    
    /**
     * 实例范围配置
     * 格式：['className' => scope]
     */
    private static array $instanceScopes = [];
    
    /**
     * 对象池
     * 格式：['className' => [instance1, instance2, ...]]
     */
    private static array $objectPool = [];
    
    /**
     * 对象池大小限制
     */
    private const POOL_MAX_SIZE = 100;
    
    /**
     * 已注册的静态变量重置配置
     * 格式：['className::propertyName' => defaultValue]
     */
    private static array $staticResets = [];
    
    /**
     * 需要重置的单例实例类
     * 格式：['className' => true]
     */
    private static array $singletonResets = [];
    
    /**
     * 注册重置回调
     * 
     * @param string $name 回调名称（用于去重和调试）
     * @param callable $callback 回调函数
     * @return void
     */
    public static function registerResetCallback(string $name, callable $callback): void
    {
        self::$resetCallbacks[$name] = $callback;
    }
    
    /**
     * 取消注册重置回调
     */
    public static function unregisterResetCallback(string $name): void
    {
        unset(self::$resetCallbacks[$name]);
    }
    
    /**
     * 注册静态变量重置
     * 
     * 类可以在初始化时调用此方法注册需要每个请求后重置的静态变量。
     * 这样就不需要在 WlsRuntime 中硬编码重置逻辑了。
     * 
     * 使用示例：
     * ```php
     * // 在类的静态初始化块或构造函数中
     * StateManager::registerStaticReset(MyClass::class, 'cacheData', []);
     * StateManager::registerStaticReset(MyClass::class, 'counter', 0);
     * ```
     * 
     * @param string $className 类名
     * @param string $propertyName 静态属性名
     * @param mixed $defaultValue 默认值（重置后的值）
     * @return void
     */
    public static function registerStaticReset(string $className, string $propertyName, mixed $defaultValue = null): void
    {
        $key = $className . '::' . $propertyName;
        self::$staticResets[$key] = [
            'class' => $className,
            'property' => $propertyName,
            'default' => $defaultValue,
        ];
    }
    
    /**
     * 批量注册静态变量重置
     * 
     * @param string $className 类名
     * @param array $properties ['propertyName' => defaultValue, ...]
     * @return void
     */
    public static function registerStaticResets(string $className, array $properties): void
    {
        foreach ($properties as $propertyName => $defaultValue) {
            self::registerStaticReset($className, $propertyName, $defaultValue);
        }
    }
    
    /**
     * 取消注册静态变量重置
     */
    public static function unregisterStaticReset(string $className, string $propertyName): void
    {
        $key = $className . '::' . $propertyName;
        unset(self::$staticResets[$key]);
    }
    
    /**
     * 注册需要重置的单例类
     * 
     * 某些单例类需要在每个请求后重新创建实例。
     * 注册后，reset() 会清除 ObjectManager 中缓存的该类实例。
     * 
     * @param string $className 类名
     * @return void
     */
    public static function registerSingletonReset(string $className): void
    {
        self::$singletonResets[$className] = true;
    }
    
    /**
     * 取消注册单例重置
     */
    public static function unregisterSingletonReset(string $className): void
    {
        unset(self::$singletonResets[$className]);
    }
    
    /**
     * 执行静态变量重置
     */
    private static function resetStaticProperties(): void
    {
        foreach (self::$staticResets as $key => $config) {
            try {
                $className = $config['class'];
                $propertyName = $config['property'];
                $defaultValue = $config['default'];
                
                if (!\class_exists($className, false)) {
                    continue; // 类未加载，跳过
                }
                
                $reflection = new \ReflectionClass($className);
                if (!$reflection->hasProperty($propertyName)) {
                    continue; // 属性不存在，跳过
                }
                
                $property = $reflection->getProperty($propertyName);
                if (!$property->isStatic()) {
                    continue; // 非静态属性，跳过
                }
                
                // 确保可访问
                $property->setAccessible(true);
                
                // 重置为默认值
                $property->setValue(null, $defaultValue);
                
            } catch (\Throwable $e) {
                \error_log("[StateManager] Static reset '{$key}' error: " . $e->getMessage());
            }
        }
    }
    
    /**
     * 重置注册的单例实例
     */
    private static function resetSingletonInstances(): void
    {
        if (empty(self::$singletonResets)) {
            return;
        }
        
        foreach (self::$singletonResets as $className => $enabled) {
            if ($enabled && \class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
                try {
                    \Weline\Framework\Manager\ObjectManager::removeInstance($className);
                } catch (\Throwable $e) {
                    \error_log("[StateManager] Singleton reset '{$className}' error: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * 配置实例范围
     * 
     * @param string $className 类名
     * @param string $scope 范围常量
     * @return void
     */
    public static function setInstanceScope(string $className, string $scope): void
    {
        self::$instanceScopes[$className] = $scope;
    }
    
    /**
     * 获取实例范围
     * 
     * @param string $className 类名
     * @return string 默认 SCOPE_SINGLETON
     */
    public static function getInstanceScope(string $className): string
    {
        return self::$instanceScopes[$className] ?? self::SCOPE_SINGLETON;
    }
    
    /**
     * 存储请求级实例
     */
    public static function setRequestInstance(string $className, object $instance): void
    {
        self::$requestScopedInstances[$className] = $instance;
    }
    
    /**
     * 获取请求级实例
     */
    public static function getRequestInstance(string $className): ?object
    {
        return self::$requestScopedInstances[$className] ?? null;
    }
    
    /**
     * 检查请求级实例是否存在
     */
    public static function hasRequestInstance(string $className): bool
    {
        return isset(self::$requestScopedInstances[$className]);
    }
    
    /**
     * 从对象池获取实例
     */
    public static function getPooledInstance(string $className): ?object
    {
        if (isset(self::$objectPool[$className]) && !empty(self::$objectPool[$className])) {
            return \array_pop(self::$objectPool[$className]);
        }
        return null;
    }
    
    /**
     * 归还实例到对象池
     */
    public static function returnToPool(string $className, object $instance): void
    {
        if (!isset(self::$objectPool[$className])) {
            self::$objectPool[$className] = [];
        }
        
        // 限制池大小
        if (\count(self::$objectPool[$className]) < self::POOL_MAX_SIZE) {
            // 重置实例状态（如果有 reset 方法）
            if (\method_exists($instance, 'reset')) {
                try {
                    $instance->reset();
                } catch (\Throwable $e) {
                    \error_log('[StateManager] Pool reset error: ' . $e->getMessage());
                    return; // 不归还失败的实例
                }
            }
            
            self::$objectPool[$className][] = $instance;
        }
    }
    
    /**
     * 执行所有重置操作
     * 
     * 每个请求结束时调用
     */
    public static function reset(): void
    {
        // 1. 重置静态变量
        self::resetStaticProperties();
        
        // 2. 重置单例实例
        self::resetSingletonInstances();
        
        // 3. 执行所有重置回调
        foreach (self::$resetCallbacks as $name => $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                \error_log("[StateManager] Reset callback '{$name}' error: " . $e->getMessage());
            }
        }
        
        // 4. 清理请求级实例
        self::$requestScopedInstances = [];
        
        // 5. 重置 HeaderCollector
        if (\class_exists(\Weline\Framework\Http\HeaderCollector::class, false)) {
            \Weline\Framework\Http\HeaderCollector::reset();
        }
    }
    
    /**
     * 完全清理（进程结束时）
     */
    public static function cleanup(): void
    {
        self::reset();
        self::$resetCallbacks = [];
        self::$instanceScopes = [];
        self::$objectPool = [];
        self::$staticResets = [];
        self::$singletonResets = [];
    }
    
    /**
     * 获取状态统计信息（用于调试）
     */
    public static function getStats(): array
    {
        $poolStats = [];
        foreach (self::$objectPool as $className => $instances) {
            $poolStats[$className] = \count($instances);
        }
        
        return [
            'reset_callbacks' => \count(self::$resetCallbacks),
            'request_instances' => \count(self::$requestScopedInstances),
            'instance_scopes' => \count(self::$instanceScopes),
            'pool_stats' => $poolStats,
            'static_resets' => \count(self::$staticResets),
            'singleton_resets' => \count(self::$singletonResets),
        ];
    }
    
    /**
     * 获取所有已注册的静态变量重置配置（调试用）
     */
    public static function getStaticResets(): array
    {
        return self::$staticResets;
    }
    
    /**
     * 获取所有已注册的单例重置类（调试用）
     */
    public static function getSingletonResets(): array
    {
        return \array_keys(self::$singletonResets);
    }
    
    // =============== 预定义的状态重置 ===============
    
    /**
     * 注册框架核心重置回调
     * 
     * 原则：FPM 下每次请求都是全新状态，WLS 下必须手动重置到同等状态。
     * 所有持有请求级数据的静态变量和递归保护标志，都必须在此注册。
     * 
     * 分类：
     * 1. URL/路由解析缓存 — 每次请求 URL 不同，必须清除
     * 2. 递归保护标志 — FPM 下每次都是 false，WLS 下如果异常中断可能残留 true
     * 3. 请求上下文 — RequestContext、SSE 连接等请求级状态
     * 4. 用户/会话状态 — Env::$user 等与当前请求用户绑定的数据
     */
    public static function registerFrameworkResets(): void
    {
        // ========== 1. URL / 路由解析缓存 ==========
        
        // Url 类：parserServer, parserSites, parserCurrencies, parserLanguages,
        //         parserMatchs, parserSiteMatchs, parserUrlCache, splitUrlCache,
        //         parserCache, parsingInProgress, decode_urls
        \Weline\Framework\Http\Url::registerStateResets();
        
        // Request::$url_paths — URL 路径解析缓存
        self::registerStaticReset(\Weline\Framework\Http\Request::class, 'url_paths', []);
        
        // ProcessUrlCache — 请求内 URL 缓存
        // $staticCache 是 private，反射扫描 public 属性会遗漏。
        // 此缓存标注为"请求内静态缓存"，FPM 下每次请求自动归零，
        // WLS 下必须显式重置，否则上个请求的路由缓存会泄漏到下个请求。
        self::registerResetCallback('process_url_cache_static', function () {
            try {
                $ref = new \ReflectionClass(\Weline\Framework\Router\Cache\ProcessUrlCache::class);
                if ($ref->hasProperty('staticCache')) {
                    $prop = $ref->getProperty('staticCache');
                    $prop->setAccessible(true);
                    $prop->setValue(null, null);
                }
            } catch (\Throwable $e) {
                // 忽略反射错误
            }
        });
        
        // ========== 2. 递归保护标志 ==========
        // FPM 下这些标志每次请求都是 false。
        // WLS 下如果请求处理过程中抛出异常，标志可能残留为 true，
        // 导致下一个请求跳过关键逻辑（创建 Session、创建缓存驱动等）。
        
        // SessionManager::$isCreating — 防止 Session 创建时循环调用
        self::registerStaticReset(\Weline\Framework\Session\SessionManager::class, 'isCreating', false);
        
        // CacheFactory::$isCreating — 防止缓存工厂创建时循环调用
        self::registerStaticReset(\Weline\Framework\Cache\CacheFactory::class, 'isCreating', false);
        
        // CacheFactory::$creatingDriver — 防止缓存驱动创建时循环调用
        self::registerStaticReset(\Weline\Framework\Cache\CacheFactory::class, 'creatingDriver', false);
        
        // Taglib::$compileDepth — 嵌套编译深度，异常中断可能残留非零值
        self::registerStaticReset(\Weline\Framework\View\Taglib::class, 'compileDepth', 0);
        
        // ========== 3. 请求上下文 ==========
        
        // RequestContext — 请求 ID、区域、语言、货币等请求级上下文
        self::registerResetCallback('request_context', function () {
            RequestContext::cleanup();
        });
        
        // SseContext — SSE 连接、启用标志、回调等请求级状态
        self::registerResetCallback('sse_context', function () {
            if (\class_exists(\Weline\Framework\Http\Sse\SseContext::class, false)) {
                \Weline\Framework\Http\Sse\SseContext::reset();
            }
        });
        
        // ========== 4. 用户 / 会话状态 ==========
        
        // Env::$user — 当前请求的用户标识，不能泄漏到下一个请求
        self::registerStaticReset(\Weline\Framework\App\Env::class, 'user', '');
        
        // Session 实例清理 — WLS 下 Session 单例被 ObjectManager 缓存。
        // Session::$lazyStarted 和 Session::$session（驱动实例）是请求级状态：
        //   - $lazyStarted 阻止后续请求重新执行 ensureStarted()
        //   - $session 持有首次请求的 WlsMemorySession（绑定了首次请求的 Cookie/Session ID）
        // 不重置会导致：不同用户的请求共用同一个 Session ID（安全泄漏），
        //               或重载后新 Cookie 无法被读取（session 丢失）。
        self::registerResetCallback('session_instances', function () {
            // 清理所有 Session 子类单例，确保下个请求重新创建并读取当前 $_COOKIE
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\App\Session\BackendSession::class);
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\App\Session\FrontendSession::class);
            if (\class_exists(\Weline\Framework\App\Session\BackendApiSession::class, false)) {
                \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\App\Session\BackendApiSession::class);
            }
            if (\class_exists(\Weline\Framework\App\Session\FrontendApiSession::class, false)) {
                \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\App\Session\FrontendApiSession::class);
            }
            
            // 清理 SessionManager 缓存的驱动实例
            // SessionManager 使用私有 static $instance 单例模式，$_session 缓存了驱动。
            // 必须清除 $_session 使下个请求根据新的 $_COOKIE 创建新驱动。
            if (\class_exists(\Weline\Framework\Session\SessionManager::class, false)) {
                try {
                    $ref = new \ReflectionClass(\Weline\Framework\Session\SessionManager::class);
                    $instProp = $ref->getProperty('instance');
                    $instProp->setAccessible(true);
                    if ($instProp->isInitialized(null)) {
                        $manager = $instProp->getValue(null);
                        $sessProp = $ref->getProperty('_session');
                        $sessProp->setAccessible(true);
                        $sessProp->setValue($manager, null);
                    }
                } catch (\Throwable $e) {
                    // 忽略反射错误
                }
            }
        });
        
        // CurrencyCache::$staticCache — 请求内货币缓存
        self::registerResetCallback('currency_cache', function () {
            if (\class_exists(\Weline\Currency\Cache\CurrencyCache::class, false)) {
                $ref = new \ReflectionClass(\Weline\Currency\Cache\CurrencyCache::class);
                if ($ref->hasProperty('staticCache')) {
                    $prop = $ref->getProperty('staticCache');
                    $prop->setAccessible(true);
                    $prop->setValue(null, null);
                }
            }
        });
        
        // LanguageCache::$staticCache — 请求内语言缓存
        // 语言列表在进程级别应该保持稳定，但为避免语言配置变更后不生效，
        // 在 cache:clear 或特定事件时应清理（这里不做请求级重置，仅注册以备将来需要）
        // 注意：语言列表是进程级缓存，不需要每请求重置
        
        // ========== 5. 请求级对象实例清理 ==========
        // FPM 下 Response 每次请求都是新实例。
        // WLS 下 ObjectManager 缓存的 Response 可能残留上个请求的 body/headers。
        
        self::registerResetCallback('request_scoped_objects', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Http\Response::class);
        });
        
        // Template 单例实例清理 — WLS 下 Template 使用 static 单例，init() 仅首次创建时调用。
        // _data 数组中的 title、req、env、local 以及 view_dir、template_dir 等目录路径
        // 全部是请求级数据，会残留到下一个请求，导致页面标题错乱、模板路径指向上个模块。
        self::registerResetCallback('template_instance', function () {
            \Weline\Framework\View\Template::resetInstance();
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\View\Template::class);
        });
        
        // State 实例清理 — State::$is_backend 在构造函数中根据当前请求设置。
        // WLS 下 State 被 ObjectManager 缓存后构造函数不再调用，$is_backend 可能残留上个请求的值。
        // 重置 static 变量 + 清除实例，确保下个请求重新创建 State 并正确判断 backend/frontend。
        self::registerStaticReset(\Weline\Framework\App\State::class, 'is_backend', false);
        self::registerResetCallback('state_instance', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\App\State::class);
        });
        
        // 控制器实例清理 — WLS 下控制器被 ObjectManager 缓存为单例。
        // 控制器的 __init() 只在首次创建时调用，导致 $this->request 指向旧请求对象。
        // 例如：第一个 GET 请求创建了控制器，$this->request = WlsRequest(GET)；
        //       第二个 POST 请求复用了缓存的控制器，$this->request 仍然是 GET 的，
        //       于是 isPost() 返回 false → "无效的请求方法"。
        // 解决：每次请求开始时清理控制器缓存，确保 __init() 重新执行获取最新 Request。
        self::registerResetCallback('controller_instances', function () {
            $instances = \Weline\Framework\Manager\ObjectManager::getInstances();
            foreach ($instances as $class => $instance) {
                if ($instance instanceof \Weline\Framework\Controller\Core) {
                    \Weline\Framework\Manager\ObjectManager::removeInstance($class);
                }
            }
        });
        
        // ========== 6. 数据库连接事务清理 ==========
        // FPM 下进程结束时未提交的事务由数据库自动回滚。
        // WLS 下连接池跨请求复用，必须在请求结束时手动回滚残留事务并归还连接。
        
        self::registerResetCallback('db_connection_cleanup', function () {
            if (\class_exists(\Weline\Framework\Database\Connection\Pool\ConnectionPool::class, false)) {
                \Weline\Framework\Database\Connection\Pool\ConnectionPool::requestEndCleanup();
            }
        });
        
        // ========== 7. 维护模式缓存 ==========
        // 每个请求都应检查最新的维护状态，不依赖进程级缓存。
        
        self::registerStaticReset(\Weline\Framework\App\Env::class, 'maintenanceCached', null);
        self::registerStaticReset(\Weline\Framework\App\Env::class, 'maintenanceLastCheck', 0.0);
        self::registerStaticReset(\Weline\Framework\App\Env::class, 'mergedCacheConfig', null);
        
        // ========== 8. 主题插槽渲染缓存 ==========
        // SlotRendererService 持有 layoutCache/widgetCache/orphanWidgets，都是请求级数据。
        // WLS 下实例被 ObjectManager 缓存后这些数组不会清零，发布后旧缓存导致渲染重复。
        self::registerResetCallback('slot_renderer_cache', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(
                \Weline\Theme\Service\SlotRendererService::class
            );
        });
        
        // Taglib Slot 静态注册表 — 编译期用于重复 slot ID 检测，不能跨请求残留
        self::registerStaticReset(\Weline\Theme\Taglib\Slot::class, 'registeredSlots', []);
        
        // ========== 9. 缓存事件防重复标志 ==========
        // CacheFlushedObserver：同一请求内多次 flush 只通知 WLS 一次，下次请求重置
        self::registerResetCallback('cache_flushed_observer', function () {
            \Weline\Server\Observer\CacheFlushedObserver::resetRequestState();
        });
        // CacheFactory 事件分发递归保护
        self::registerStaticReset(\Weline\Framework\Cache\CacheFactory::class, 'dispatchingCacheEvent', false);
    }
}
