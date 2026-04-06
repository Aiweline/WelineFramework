<?php
declare(strict_types=1);

/**
 * Weline Framework - 请求上下文
 * 
 * 提供请求级状态存储，支持 Fiber 协程
 * 替代静态变量存储请求数据，避免跨请求污染
 * 
 * 架构重构说明：
 * - 统一封装所有 $_SERVER['WELINE_*'] 访问
 * - 提供 AREA 常量，替代硬编码字符串
 * - WLS 模式使用 Fiber Local Storage 隔离
 * - FPM 模式使用静态变量（每次请求自动重置）
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

/**
 * 请求上下文
 * 
 * 功能：
 * - 存储请求级状态数据
 * - 支持 Fiber 协程环境下的上下文隔离
 * - 请求结束自动清理
 * - 替代静态变量存储请求相关数据
 * - 统一封装 WELINE_* 服务器变量访问
 */
class RequestContext
{
    // ==================== 区域常量 ====================
    
    /**
     * 前端区域
     */
    public const AREA_FRONTEND = 'frontend';
    
    /**
     * 后端区域（PC 管理后台）
     */
    public const AREA_BACKEND = 'backend';
    
    /**
     * 前端 REST API 区域
     */
    public const AREA_REST_FRONTEND = 'rest_frontend';
    
    /**
     * 后端 REST API 区域
     */
    public const AREA_REST_BACKEND = 'rest_backend';
    
    // ==================== 区域常量结束 ====================
    /**
     * 上下文数据存储
     * 
     * 在非 Fiber 环境下使用静态变量
     * 在 Fiber 环境下使用 Fiber::getCurrent() 隔离
     */
    private static array $storage = [];
    
    /**
     * WLS Fiber 本地存储
     * 
     * 使用 WeakMap 以 Fiber 对象为键存储上下文数据
     * Fiber 结束后自动释放内存
     */
    private static ?\WeakMap $fiberStorage = null;
    
    /**
     * 当前请求 ID
     */
    private static ?string $requestId = null;
    private static ?\WeakMap $fiberRequestIds = null;
    
    /**
     * 请求开始时间
     */
    private static float $startTime = 0;
    private static ?\WeakMap $fiberStartTimes = null;
    
    /**
     * 已注册的清理回调
     */
    private static array $cleanupCallbacks = [];
    private static ?\WeakMap $fiberCleanupCallbacks = null;
    
    // ==================== WELINE_* 服务器变量 Backing Storage ====================
    
    /**
     * 当前区域（同步到 $_SERVER['WELINE_AREA']）
     */
    private static string $_area = self::AREA_FRONTEND;
    
    /**
     * 区域路由前缀（同步到 $_SERVER['WELINE_AREA_ROUTE']）
     */
    private static string $_areaRoute = '';
    
    /**
     * 网站 ID（同步到 $_SERVER['WELINE_WEBSITE_ID']）
     */
    private static int $_websiteId = 0;
    
    /**
     * 网站代码（同步到 $_SERVER['WELINE_WEBSITE_CODE']）
     */
    private static string $_websiteCode = '';
    
    /**
     * 网站 URL（同步到 $_SERVER['WELINE_WEBSITE_URL']）
     */
    private static string $_websiteUrl = '';
    
    /**
     * 用户语言（同步到 $_SERVER['WELINE_USER_LANG']）
     */
    private static string $_userLang = 'zh_Hans_CN';
    
    /**
     * 用户货币（同步到 $_SERVER['WELINE_USER_CURRENCY']）
     */
    private static string $_userCurrency = 'CNY';
    
    /**
     * 初始化请求上下文
     * 
     * 每个请求开始时调用
     * 
     * @return void
     */
    public static function init(): void
    {
        self::setRequestIdValue(self::generateRequestId());
        self::setStartTimeValue(\microtime(true));
        $storage = self::getStorage();
        $storage = [];
        self::setStorage($storage);
        self::setCleanupCallbacksValue([]);
        
        // 从 $_SERVER 同步 WELINE_* 变量
        self::syncFromServer();
    }
    
    /**
     * 生成请求 ID
     * 
     * @return string
     */
    private static function generateRequestId(): string
    {
        if (\function_exists('hrtime')) {
            return \bin2hex(\random_bytes(8)) . '-' . \hrtime(true);
        }
        return \bin2hex(\random_bytes(8)) . '-' . (int) (\microtime(true) * 1000000);
    }
    
    /**
     * 获取请求 ID
     * 
     * @return string|null
     */
    public static function getRequestId(): ?string
    {
        return self::getRequestIdValue();
    }

    /**
     * 获取请求 ID（getId 别名，供 WlsFiberContext 使用）
     */
    public static function getId(): ?string
    {
        return self::getRequestIdValue();
    }

    /**
     * 设置请求 ID（Fiber 恢复上下文时使用）
     */
    public static function setId(?string $id): void
    {
        self::setRequestIdValue($id);
    }
    
    /**
     * 获取请求开始时间
     * 
     * @return float
     */
    public static function getStartTime(): float
    {
        return self::getStartTimeValue();
    }
    
    /**
     * 获取请求耗时（毫秒）
     * 
     * @return float
     */
    public static function getElapsedMs(): float
    {
        return (\microtime(true) - self::getStartTimeValue()) * 1000;
    }
    
    /**
     * 设置上下文数据
     * 
     * @param string $key 键名
     * @param mixed $value 值
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $storage = self::getStorage();
        $storage[$key] = $value;
        self::setStorage($storage);
    }
    
    /**
     * 获取上下文数据
     * 
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $storage = self::getStorage();
        return $storage[$key] ?? $default;
    }
    
    /**
     * 检查键是否存在
     * 
     * @param string $key 键名
     * @return bool
     */
    public static function has(string $key): bool
    {
        $storage = self::getStorage();
        return \array_key_exists($key, $storage);
    }
    
    /**
     * 删除上下文数据
     * 
     * @param string $key 键名
     * @return void
     */
    public static function remove(string $key): void
    {
        $storage = self::getStorage();
        unset($storage[$key]);
        self::setStorage($storage);
    }
    
    /**
     * 获取所有上下文数据
     * 
     * @return array
     */
    public static function all(): array
    {
        $storage = self::getStorage();
        return $storage;
    }
    
    /**
     * 注册清理回调
     * 
     * 请求结束时会调用所有注册的清理回调
     * 
     * @param callable $callback 回调函数
     * @param string|null $name 回调名称（用于去重）
     * @return void
     */
    public static function onCleanup(callable $callback, ?string $name = null): void
    {
        $cleanupCallbacks = self::getCleanupCallbacksValue();
        if ($name !== null) {
            $cleanupCallbacks[$name] = $callback;
        } else {
            $cleanupCallbacks[] = $callback;
        }
        self::setCleanupCallbacksValue($cleanupCallbacks);
    }
    
    /**
     * 清理请求上下文
     * 
     * 每个请求结束时调用
     * 
     * @return void
     */
    public static function cleanup(): void
    {
        // 执行所有清理回调
        foreach (self::getCleanupCallbacksValue() as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // 记录错误但不中断清理流程
                w_log_error('[RequestContext] Cleanup callback error: ' . $e->getMessage());
            }
        }
        
        // 清理存储
        $storage = self::getStorage();
        $storage = [];
        self::setStorage($storage);
        self::setCleanupCallbacksValue([]);
        self::setRequestIdValue(null);
        self::setStartTimeValue(0.0);
        
        // 重置 WELINE_* 变量
        self::resetWelineVars();
    }
    
    /**
     * 判断上下文是否已初始化
     * 
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::getRequestIdValue() !== null;
    }
    
    // =============== WLS 隔离存储 ===============
    
    /**
     * 获取当前存储（WLS 模式使用 Fiber Local，FPM 模式使用静态变量）
     * 
     * @return array
     */
    private static function getStorage(): array
    {
        // 常驻内存模式且在 Fiber 中
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                if (self::$fiberStorage === null) {
                    self::$fiberStorage = new \WeakMap();
                }
                if (!isset(self::$fiberStorage[$fiber])) {
                    self::$fiberStorage[$fiber] = [];
                }
                return self::$fiberStorage[$fiber];
            }
        }
        // FPM 模式或非 Fiber 环境
        return self::$storage;
    }

    private static function setStorage(array $storage): void
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                if (self::$fiberStorage === null) {
                    self::$fiberStorage = new \WeakMap();
                }
                self::$fiberStorage[$fiber] = $storage;
                return;
            }
        }

        self::$storage = $storage;
    }

    private static function getRequestIdValue(): ?string
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null && self::$fiberRequestIds !== null && isset(self::$fiberRequestIds[$fiber])) {
                return self::$fiberRequestIds[$fiber];
            }
        }

        return self::$requestId;
    }

    private static function setRequestIdValue(?string $requestId): void
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                if (self::$fiberRequestIds === null) {
                    self::$fiberRequestIds = new \WeakMap();
                }
                self::$fiberRequestIds[$fiber] = $requestId;
                return;
            }
        }

        self::$requestId = $requestId;
    }

    private static function getStartTimeValue(): float
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null && self::$fiberStartTimes !== null && isset(self::$fiberStartTimes[$fiber])) {
                return (float) self::$fiberStartTimes[$fiber];
            }
        }

        return self::$startTime;
    }

    private static function setStartTimeValue(float $startTime): void
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                if (self::$fiberStartTimes === null) {
                    self::$fiberStartTimes = new \WeakMap();
                }
                self::$fiberStartTimes[$fiber] = $startTime;
                return;
            }
        }

        self::$startTime = $startTime;
    }

    private static function getCleanupCallbacksValue(): array
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null && self::$fiberCleanupCallbacks !== null && isset(self::$fiberCleanupCallbacks[$fiber])) {
                $cleanupCallbacks = self::$fiberCleanupCallbacks[$fiber];
                return \is_array($cleanupCallbacks) ? $cleanupCallbacks : [];
            }
        }

        return self::$cleanupCallbacks;
    }

    private static function setCleanupCallbacksValue(array $cleanupCallbacks): void
    {
        if (Runtime::isPersistent()) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                if (self::$fiberCleanupCallbacks === null) {
                    self::$fiberCleanupCallbacks = new \WeakMap();
                }
                self::$fiberCleanupCallbacks[$fiber] = $cleanupCallbacks;
                return;
            }
        }

        self::$cleanupCallbacks = $cleanupCallbacks;
    }
    
    // =============== WELINE_* 服务器变量访问（统一入口） ===============
    
    /**
     * 获取当前区域
     * 
     * @return string AREA_FRONTEND | AREA_BACKEND | AREA_REST_FRONTEND | AREA_REST_BACKEND
     */
    public static function getWelineArea(): string
    {
        return self::$_area;
    }
    
    /**
     * 设置当前区域（同时同步到 $_SERVER）
     * 
     * @param string $area 区域值
     */
    public static function setWelineArea(string $area): void
    {
        self::$_area = $area;
        $_SERVER['WELINE_AREA'] = $area;
    }
    
    /**
     * 获取区域路由前缀
     * 
     * @return string
     */
    public static function getWelineAreaRoute(): string
    {
        return self::$_areaRoute;
    }
    
    /**
     * 设置区域路由前缀（同时同步到 $_SERVER）
     * 
     * @param string $route 路由前缀
     */
    public static function setWelineAreaRoute(string $route): void
    {
        self::$_areaRoute = $route;
        $_SERVER['WELINE_AREA_ROUTE'] = $route;
    }
    
    /**
     * 获取网站 ID
     * 
     * @return int
     */
    public static function getWelineWebsiteId(): int
    {
        return self::$_websiteId;
    }
    
    /**
     * 设置网站 ID（同时同步到 $_SERVER）
     * 
     * @param int $websiteId 网站 ID
     */
    public static function setWelineWebsiteId(int $websiteId): void
    {
        self::$_websiteId = $websiteId;
        $_SERVER['WELINE_WEBSITE_ID'] = $websiteId;
    }
    
    /**
     * 获取网站代码
     * 
     * @return string
     */
    public static function getWelineWebsiteCode(): string
    {
        return self::$_websiteCode;
    }
    
    /**
     * 设置网站代码（同时同步到 $_SERVER）
     * 
     * @param string $code 网站代码
     */
    public static function setWelineWebsiteCode(string $code): void
    {
        self::$_websiteCode = $code;
        $_SERVER['WELINE_WEBSITE_CODE'] = $code;
    }
    
    /**
     * 获取网站 URL
     * 
     * @return string
     */
    public static function getWelineWebsiteUrl(): string
    {
        return self::$_websiteUrl;
    }
    
    /**
     * 设置网站 URL（同时同步到 $_SERVER）
     * 
     * @param string $url 网站 URL
     */
    public static function setWelineWebsiteUrl(string $url): void
    {
        self::$_websiteUrl = $url;
        $_SERVER['WELINE_WEBSITE_URL'] = $url;
    }
    
    /**
     * 获取用户语言
     * 
     * @return string
     */
    public static function getWelineUserLang(): string
    {
        return self::$_userLang;
    }
    
    /**
     * 设置用户语言（同时同步到 $_SERVER）
     * 
     * @param string $lang 语言代码
     */
    public static function setWelineUserLang(string $lang): void
    {
        self::$_userLang = $lang;
        $_SERVER['WELINE_USER_LANG'] = $lang;
    }
    
    /**
     * 获取用户货币
     * 
     * @return string
     */
    public static function getWelineUserCurrency(): string
    {
        return self::$_userCurrency;
    }
    
    /**
     * 设置用户货币（同时同步到 $_SERVER）
     * 
     * @param string $currency 货币代码
     */
    public static function setWelineUserCurrency(string $currency): void
    {
        self::$_userCurrency = $currency;
        $_SERVER['WELINE_USER_CURRENCY'] = $currency;
    }
    
    // =============== 区域判断便捷方法 ===============
    
    /**
     * 是否为后端区域（PC 后台或后端 API）
     * 
     * @return bool
     */
    public static function isBackendArea(): bool
    {
        $area = self::getWelineArea();
        return $area === self::AREA_BACKEND || $area === self::AREA_REST_BACKEND;
    }
    
    /**
     * 是否为前端区域（PC 前端或前端 API）
     * 
     * @return bool
     */
    public static function isFrontendArea(): bool
    {
        $area = self::getWelineArea();
        return $area === self::AREA_FRONTEND || $area === self::AREA_REST_FRONTEND;
    }
    
    /**
     * 是否为 REST API 区域
     * 
     * @return bool
     */
    public static function isRestArea(): bool
    {
        $area = self::getWelineArea();
        return $area === self::AREA_REST_FRONTEND || $area === self::AREA_REST_BACKEND;
    }
    
    /**
     * 是否为 PC 区域（非 API）
     * 
     * @return bool
     */
    public static function isPcArea(): bool
    {
        $area = self::getWelineArea();
        return $area === self::AREA_FRONTEND || $area === self::AREA_BACKEND;
    }
    
    // =============== 从 $_SERVER 同步数据（初始化时调用） ===============
    
    /**
     * 从 $_SERVER 同步 WELINE_* 变量到 RequestContext
     * 
     * 在请求开始时调用，确保 RequestContext 与 $_SERVER 同步
     */
    public static function syncFromServer(): void
    {
        self::$_area = $_SERVER['WELINE_AREA'] ?? self::AREA_FRONTEND;
        self::$_areaRoute = $_SERVER['WELINE_AREA_ROUTE'] ?? '';
        self::$_websiteId = (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0);
        self::$_websiteCode = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
        self::$_websiteUrl = $_SERVER['WELINE_WEBSITE_URL'] ?? '';
        self::$_userLang = $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN';
        self::$_userCurrency = $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY';
    }
    
    /**
     * 重置 WELINE_* 变量到默认值
     * 
     * 在请求结束时调用，避免 WLS 下跨请求污染
     * 同时重置 $_SERVER 中的对应值，确保下一个请求不会继承旧状态
     * 
     * 重要：必须 unset $_SERVER 变量而非设为空字符串，
     *       否则 syncFromServer() 中的 ?? 运算符无法正确回退到默认值
     */
    public static function resetWelineVars(): void
    {
        // 重置静态变量到默认值
        self::$_area = self::AREA_FRONTEND;
        self::$_areaRoute = '';
        self::$_websiteId = 0;
        self::$_websiteCode = '';
        self::$_websiteUrl = '';
        self::$_userLang = 'zh_Hans_CN';
        self::$_userCurrency = 'CNY';
        
        // 重置 $_SERVER 中的 WELINE_* 变量
        // 注意：使用 unset 而非赋空字符串，确保 syncFromServer() 的 ?? 运算符能正确工作
        $_SERVER['WELINE_AREA'] = self::AREA_FRONTEND;
        $_SERVER['WELINE_AREA_ROUTE'] = '';
        $_SERVER['WELINE_IS_BACKEND'] = false;
        unset($_SERVER['WELINE_WEBSITE_ID']);
        unset($_SERVER['WELINE_WEBSITE_CODE']);
        unset($_SERVER['WELINE_WEBSITE_URL']);
        unset($_SERVER['WELINE_USER_LANG']);
        unset($_SERVER['WELINE_USER_CURRENCY']);
    }
    
    // =============== 便捷方法（保持向后兼容） ===============
    
    /**
     * 获取/设置当前用户 ID
     */
    public static function userId(?int $userId = null): ?int
    {
        if ($userId !== null) {
            self::set('user_id', $userId);
        }
        return self::get('user_id');
    }
    
    /**
     * 获取/设置当前网站 ID（代理到 WELINE 方法）
     */
    public static function websiteId(?int $websiteId = null): ?int
    {
        if ($websiteId !== null) {
            self::setWelineWebsiteId($websiteId);
        }
        return self::getWelineWebsiteId() ?: null;
    }
    
    /**
     * 获取/设置当前语言（代理到 WELINE 方法）
     */
    public static function locale(?string $locale = null): ?string
    {
        if ($locale !== null) {
            self::setWelineUserLang($locale);
        }
        return self::getWelineUserLang();
    }
    
    /**
     * 获取/设置当前货币（代理到 WELINE 方法）
     */
    public static function currency(?string $currency = null): ?string
    {
        if ($currency !== null) {
            self::setWelineUserCurrency($currency);
        }
        return self::getWelineUserCurrency();
    }
    
    /**
     * 获取/设置当前区域（代理到 WELINE 方法）
     */
    public static function area(?string $area = null): ?string
    {
        if ($area !== null) {
            self::setWelineArea($area);
        }
        return self::getWelineArea();
    }
    
    /**
     * 获取/设置当前路由
     */
    public static function route(?array $route = null): ?array
    {
        if ($route !== null) {
            self::set('route', $route);
        }
        return self::get('route');
    }
}
