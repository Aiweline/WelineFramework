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
                w_log_error("[StateManager] Static reset '{$key}' error: " . $e->getMessage());
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
                    w_log_error("[StateManager] Singleton reset '{$className}' error: " . $e->getMessage());
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
                    w_log_error('[StateManager] Pool reset error: ' . $e->getMessage());
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
                w_log_error("[StateManager] Reset callback '{$name}' error: " . $e->getMessage());
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
        
        // CacheFactory::$isCreating — 防止缓存工厂创建时循环调用
        self::registerStaticReset(\Weline\Framework\Cache\CacheFactory::class, 'isCreating', false);
        
        // CacheFactory::$creatingDriver — 防止缓存驱动创建时循环调用
        self::registerStaticReset(\Weline\Framework\Cache\CacheFactory::class, 'creatingDriver', false);
        
        // Taglib::$compileDepth — 嵌套编译深度，异常中断可能残留非零值
        self::registerStaticReset(\Weline\Framework\View\Taglib::class, 'compileDepth', 0);
        
        // ResultManager::$result — 控制器 success/error/info 结果，请求级，redirect 后消费
        \Weline\Framework\Manager\ResultManager::registerStateResets();
        
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
        
        // Session 实例清理 — WLS 下 SessionFactory 缓存的 Session 实例是请求级状态。
        // 不重置会导致：不同用户的请求共用同一个 Session ID（安全泄漏），
        //               或重载后新 Cookie 无法被读取（session 丢失）。
        self::registerResetCallback('session_instances', function () {
            // 清理 SessionFactory 缓存的请求级实例
            if (\class_exists(\Weline\Framework\Session\SessionFactory::class, false)) {
                \Weline\Framework\Session\SessionFactory::getInstance()->resetRequestInstances();
            }
        });
        
        // 缓存系统已重构为 w_cache() 全局函数，不再使用旧的 XxxCache 类
        // 缓存池的清理由 CachePoolInterface 统一管理
        
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
        self::registerStaticReset(\Weline\Framework\App\State::class, 'langLocalCache', null);
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
        
        // ========== 5.5 模型实例清理 ==========
        // WLS 下模型被 ObjectManager 缓存为单例，注入到控制器后跨请求复用。
        // 模型内部持有 items、_data、_model_fields_data 等请求级数据，
        // 如果不清理，上一请求的数据（包括主键 ID）会残留到下一请求。
        // 例如：第一个请求 save() 成功设置了 user_id = 5，
        //       第二个请求复用同一个模型实例，clearData() 不清理 _data 的主键，
        //       导致新增变成更新，或 unique constraint violation。
        // 解决：每次请求结束时清理所有 AbstractModel 子类的缓存实例。
        self::registerResetCallback('model_instances', function () {
            $instances = \Weline\Framework\Manager\ObjectManager::getInstances();
            foreach ($instances as $class => $instance) {
                if ($instance instanceof \Weline\Framework\Database\AbstractModel) {
                    \Weline\Framework\Manager\ObjectManager::removeInstance($class);
                }
            }
        });
        
        // ========== 5.6 Request 实例清理 ==========
        // WLS 下 Request 被 ObjectManager 缓存复用，其实例属性 request_id 在首次
        // getId() 调用后不会重置，导致多个请求使用相同的 request_id。
        // BackendActivityLog 等使用 request_id 作为唯一约束的表会触发冲突。
        // 解决：每次请求结束后清除 Request 实例，确保下次请求重新创建。
        self::registerResetCallback('request_instance', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Http\Request::class);
        });
        
        // ========== 5.7 Observer 实例清理 ==========
        // WLS 下 Observer 被 ObjectManager 缓存为单例，其构造函数注入的 Request 对象
        // 指向旧请求实例。虽然 WlsRuntime::handle() 会将新 WlsRequest 注册到 ObjectManager，
        // 但已缓存的 Observer 仍持有旧 Request 引用，导致 request_id 重复。
        // 例如：BackendControllerInit Observer 调用 $this->request->getId() 获取的是
        //       上一个请求的 request_id，导致 BackendActivityLog 唯一约束冲突。
        // 解决：每次请求结束时清理所有 ObserverInterface 实现类的缓存实例，
        //       确保下次请求重新创建 Observer 并注入最新的 Request。
        self::registerResetCallback('observer_instances', function () {
            $instances = \Weline\Framework\Manager\ObjectManager::getInstances();
            foreach ($instances as $class => $instance) {
                if ($instance instanceof \Weline\Framework\Event\ObserverInterface) {
                    \Weline\Framework\Manager\ObjectManager::removeInstance($class);
                }
            }
        });
        
        // ========== 5.8 Router 实例清理 ==========
        // WLS 下 Router\Core 被 ObjectManager 缓存为单例。虽然 __init() 有 $isNewRequest 检测，
        // 但 ObjectManager 从缓存返回实例时不会再次调用 __init()，导致：
        // 1. $this->request 仍指向旧请求对象
        // 2. $this->area_router、$this->is_backend 等属性未更新
        // 3. 后台请求可能使用前端 area_router，导致 404 错误
        // 解决：每次请求结束时清理 Router\Core 实例，确保下次请求重新创建并正确初始化。
        self::registerResetCallback('router_core_instance', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Router\Core::class);
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
        // mergedCacheConfig 是进程级热缓存（DB 合并后的 cache 配置），
        // 每请求清空会导致重复 DB 合并与性能回退，仅在 setConfig('cache')/reload 时失效。
        
        // ========== 8. 主题插槽渲染缓存 ==========
        // SlotRendererService 持有 layoutCache/widgetCache/orphanWidgets，都是请求级数据。
        // WLS 下实例被 ObjectManager 缓存后这些数组不会清零，发布后旧缓存导致渲染重复。
        self::registerResetCallback('slot_renderer_cache', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(
                \Weline\Theme\Service\SlotRendererService::class
            );
        });

        // ThemeConfig Block 在模板中常通过 ObjectManager::getInstance() 直接获取。
        // WLS 下若复用旧实例，会持有上一请求的 Session 引用，出现主题模式偶发回退。
        self::registerResetCallback('theme_config_blocks', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Backend\Block\ThemeConfig::class);
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Frontend\Block\ThemeConfig::class);
        });
        
        // Taglib Slot 静态注册表 — 编译期用于重复 slot ID 检测，不能跨请求残留
        self::registerStaticReset(\Weline\Theme\Taglib\Slot::class, 'registeredSlots', []);

        // ThemeData 请求态缓存（当前主题/区域/performance 缓存）在 WLS 下必须每请求清理
        self::registerResetCallback('theme_data_request_state', function () {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class, false)) {
                \Weline\Theme\Helper\ThemeData::resetRequestState();
            }
        });

        // 预览 token 检测结果是请求级静态状态，跨请求会导致预览串用户
        self::registerResetCallback('preview_token_request_state', function () {
            if (\class_exists(\Weline\Theme\Service\PreviewTokenService::class, false)) {
                \Weline\Theme\Service\PreviewTokenService::resetRequestState();
            }
        });

        // Session::flushOnShutdown 队列在常驻进程中不会自动按请求清理，需主动重置
        self::registerResetCallback('session_shutdown_queue', function () {
            if (\class_exists(\Weline\Framework\Session\Session::class, false)) {
                \Weline\Framework\Session\Session::resetRequestState();
            }
            // Session 实例持有当前请求上下文（session id/cookie/request），
            // 在 WLS 下必须移除单例，避免下个请求读到上次会话状态。
            \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Session\Session::class);
        });
        
        // ========== 9. 缓存事件防重复标志 ==========
        // CacheFlushedObserver：同一请求内多次 flush 只通知 WLS 一次，下次请求重置
        self::registerResetCallback('cache_flushed_observer', function () {
            \Weline\Server\Observer\CacheFlushedObserver::resetRequestState();
        });
        // WlsMemoryAdapter 统计重置（仅重置 hits/misses 统计，不清空内存缓存数据）
        self::registerResetCallback('wls_memory_adapter_reset', function () {
            \Weline\Framework\Cache\Adapter\WlsMemoryAdapter::resetRequestState();
        });
        
        // ========== 10. 菜单相关缓存 ==========
        // MenuUrlValidator::$menuPathsCache — 菜单路径验证缓存
        // 虽然菜单路径通常进程内不变，但如果菜单发生变更（动态添加/删除），
        // 旧缓存会导致验证结果错误。为安全起见，每请求重置。
        self::registerStaticReset(\Weline\Admin\Helper\MenuUrlValidator::class, 'menuPathsCache', null);
        
        // MenuRenderService — 单例实例持有 Request 对象
        // WLS 下 MenuRenderService 被 ObjectManager 缓存，其 $request 成员指向旧请求。
        // 虽然已将 backendUrlPrefix 改为动态获取，但为保险起见，每请求清除实例。
        self::registerResetCallback('menu_render_service', function () {
            \Weline\Framework\Manager\ObjectManager::removeInstance(
                \Weline\Admin\Service\MenuRenderService::class
            );
        });
        
        // ========== 11. ACL 权限缓存 ==========
        // Acl\Taglib\Acl — 权限检查标签中的请求级缓存
        // WLS 下静态缓存会跨请求保留，导致不同用户使用同一份权限缓存
        // 必须在每次请求结束时重置
        self::registerResetCallback('acl_taglib_reset', function () {
            \Weline\Acl\Taglib\Acl::resetRequestState();
        });

        // ========== 12. Taglib / Widget 请求级静态缓存 ==========
        // WMeta::$ids — Meta 标签唯一 ID 记录，跨请求会累积导致误判重复
        self::registerStaticReset(\Weline\Meta\Taglib\WMeta::class, 'ids', []);
        // Widget::$renderCache — Widget 渲染结果缓存，只应在单次请求内有效
        self::registerStaticReset(\Weline\Widget\Taglib\Widget::class, 'renderCache', []);

        // ========== 13. DataTable 字段模板缓存 ==========
        // Field::$templateFields — DataTable 字段定义缓存，必须每请求重置
        self::registerStaticReset(\Weline\DataTable\Taglib\Field::class, 'templateFields', []);

        // ========== 14. SessionFactory 事件解析缓存 ==========
        // resolveViaEvent() 的静态缓存可能在不同请求环境下不一致，需请求结束后重置
        self::registerStaticReset(\Weline\Framework\Session\SessionFactory::class, 'eventResolveCached', false);
        self::registerStaticReset(\Weline\Framework\Session\SessionFactory::class, 'eventResolveResult', null);

        // ========== 15. 事件观察者缓存 ==========
        // EventsManager::$observerCache 是实例缓存，WLS 下会跨请求保留，需主动清空
        self::registerResetCallback('events_manager_observer_cache', function () {
            if (!\class_exists(\Weline\Framework\Event\EventsManager::class, false)) {
                return;
            }
            $eventsManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            if (\method_exists($eventsManager, 'resetRequestState')) {
                $eventsManager->resetRequestState();
                return;
            }
            if (\method_exists($eventsManager, 'clearObserverCache')) {
                $eventsManager->clearObserverCache();
            }
        });

        // Widget 渲染缓存只允许请求内命中，防止跨请求模板参数串用
        self::registerResetCallback('widget_taglib_cache', function () {
            if (\class_exists(\Weline\Widget\Taglib\Widget::class, false)) {
                \Weline\Widget\Taglib\Widget::resetRequestState();
            }
        });

        // MessageManager 请求级状态
        self::registerResetCallback('message_manager_request_state', function () {
            if (\class_exists(\Weline\Framework\Manager\MessageManager::class, false)) {
                \Weline\Framework\Manager\MessageManager::resetRequestState();
            }
        });
    }
}
