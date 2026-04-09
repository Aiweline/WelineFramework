<?php
declare(strict_types=1);

namespace Weline\Framework\Env;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Manager\ObjectManager;

/**
 * WelineEnv - 统一环境变量管理器
 *
 * 在 WLS + Fiber 并发环境下，统一管理所有全局变量的获取
 *
 * 设计原则：
 * 1. 基于 WeakMap<Fiber, self> 实现每个 Fiber 独立实例
 * 2. 内部通过 RequestContext 存储变量，与框架现有机制融合
 * 3. 提供 get(string $key)/set(string $key, mixed $value) 核心方法
 * 4. 提供快捷访问方法：getArea()/getLang()/getCurrency() 等
 * 5. 支持 $_GET/$_POST/$_COOKIE 的快捷访问
 *
 * 使用方式：
 *   WelineEnv::get('area')              // 获取 WELINE_AREA
 *   WelineEnv::get('user.lang')         // 获取 WELINE_USER_LANG
 *   WelineEnv::get('request.scheme')    // 获取 REQUEST_SCHEME
 *   WelineEnv::get('server.http_host')   // 获取 HTTP_HOST
 *
 * @author Weline Framework Team
 */
class WelineEnv
{
    /**
     * WeakMap 存储 - 每个 Fiber 的独立实例
     *
     * @var \WeakMap<\Fiber, self>|null
     */
    private static ?\WeakMap $instances = null;

    /**
     * 变量名映射表：别名 => $_SERVER 键名
     */
    private const MAPPINGS = [
        // 框架 WELINE_* 变量（按重要性排序）
        'area' => 'WELINE_AREA',
        'area_route' => 'WELINE_AREA_ROUTE',
        'website_id' => 'WELINE_WEBSITE_ID',
        'website_code' => 'WELINE_WEBSITE_CODE',
        'user.lang' => 'WELINE_USER_LANG',
        'user.currency' => 'WELINE_USER_CURRENCY',
        'user.id' => 'WELINE_USER_ID',
        'user.session_id' => 'WELINE_USER_SESSION_ID',

        // URL 解析状态
        'url_parsed' => 'WELINE_URL_PARSED',
        'is_backend' => 'WELINE_IS_BACKEND',
        'is_static_file' => 'WELINE_IS_STATIC_FILE',
        'parser_url' => 'WELINE_PARSER_URL',

        // 完整请求 URI
        'origin_request_uri' => 'WELINE_ORIGIN_REQUEST_URI',
        'full_request_uri' => 'WELINE_FULL_REQUEST_URI',

        // 标准 $_SERVER 变量（按使用频率排序）
        'request.method' => 'REQUEST_METHOD',
        'request.scheme' => 'REQUEST_SCHEME',
        'request.uri' => 'REQUEST_URI',
        'request.time' => 'REQUEST_TIME',
        'request.time_float' => 'REQUEST_TIME_FLOAT',
        'request.query_string' => 'QUERY_STRING',

        'server.http_host' => 'HTTP_HOST',
        'server.host' => 'HOST',
        'server.remote_addr' => 'REMOTE_ADDR',
        'server.user_agent' => 'HTTP_USER_AGENT',
        'server.accept' => 'HTTP_ACCEPT',
        'server.accept_language' => 'HTTP_ACCEPT_LANGUAGE',
        'server.accept_encoding' => 'HTTP_ACCEPT_ENCODING',
        'server.connection' => 'HTTP_CONNECTION',
        'server.content_type' => 'CONTENT_TYPE',
        'server.content_length' => 'CONTENT_LENGTH',
        'server.server_name' => 'SERVER_NAME',
        'server.server_port' => 'SERVER_PORT',
        'server.https' => 'HTTPS',
        'server.php_self' => 'PHP_SELF',
        'server.script_name' => 'SCRIPT_NAME',
        'server.script_filename' => 'SCRIPT_FILENAME',
        'server.path_info' => 'PATH_INFO',

        // HTTP 头变量
        'http_referer' => 'HTTP_REFERER',
        'http_origin' => 'HTTP_ORIGIN',
        'http_traceparent' => 'HTTP_TRACEPARENT',
        'http_x_forwarded_proto' => 'HTTP_X_FORWARDED_PROTO',
        'http_x_forwarded_host' => 'HTTP_X_FORWARDED_HOST',
        'http_weline_via_dispatcher' => 'HTTP_WELINE_VIA_DISPATCHER',
        'http_weline_original_scheme' => 'HTTP_WELINE_ORIGINAL_SCHEME',
        'http_weline_original_host' => 'HTTP_WELINE_ORIGINAL_HOST',
        'http_weline_original_port' => 'HTTP_WELINE_ORIGINAL_PORT',
        'http_x_requested_with' => 'HTTP_X_REQUESTED_WITH',

        // WLS 内部变量
        'wls.redirect_count' => 'WLS_REDIRECT_COUNT',
        'redirect_count' => 'REDIRECT_COUNT',
        'wls.request_count' => 'WLS_REQUEST_COUNT',
        'wls.instance' => 'WLS_INSTANCE',
        'wls.instance_name' => 'WLS_INSTANCE_NAME',
        'wls.process_tag' => 'WLS_PROCESS_TAG',
    ];

    /**
     * $_GET 参数缓存
     */
    private array $getParams = [];

    /**
     * $_POST 参数缓存
     */
    private array $postParams = [];

    /**
     * $_COOKIE 参数缓存
     */
    private array $cookieParams = [];

    /**
     * $_FILES 参数缓存
     */
    private array $filesParams = [];

    /**
     * 覆盖记录（用于调试和追踪）
     *
     * @var array<string, array{value: mixed, reason: string, fiber_id: int|string, trace: array}>
     */
    private array $overrides = [];

    /**
     * 是否已初始化
     */
    private bool $initialized = false;

    /**
     * 获取当前 Fiber 的实例（Fiber 安全）
     */
    public static function getInstance(): self
    {
        if (self::$instances === null) {
            self::$instances = new \WeakMap();
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            // 主线程或非 Fiber 环境，使用全局实例
            static $globalInstance = null;
            $globalInstance ??= new self();
            return $globalInstance;
        }

        // 直接使用 Fiber 对象作为 WeakMap key
        if (!isset(self::$instances[$fiber])) {
            $instance = new self();
            self::$instances[$fiber] = $instance;
        }

        return self::$instances[$fiber];
    }

    /**
     * 获取当前 Fiber 的 ID
     *
     * @return string
     */
    public static function getFiberId(): string
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return 'main';
        }
        return (string) \spl_object_id($fiber);
    }

    /**
     * 获取环境变量（统一入口）
     *
     * @param string $key 支持点号分隔：'user.lang', 'area', 'server.http_host'
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->getVar($key, $default);
    }

    /**
     * 设置环境变量（仅当前请求/Fiber 生效）
     *
     * @param string $key 变量键
     * @param mixed $value 变量值
     * @param string $reason 设置原因（用于调试）
     * @return void
     */
    public static function set(string $key, mixed $value, string $reason = ''): void
    {
        self::getInstance()->setVar($key, $value, $reason);
    }

    /**
     * 获取 $_GET 参数
     *
     * @param string|null $key 参数键，null 则返回全部
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getGet(?string $key = null, mixed $default = null): mixed
    {
        $instance = self::getInstance();
        if (!$instance->initialized) {
            $instance->initFromGlobals();
        }

        if ($key === null) {
            return $instance->getParams;
        }

        return $instance->getParams[$key] ?? $default;
    }

    /**
     * 获取 $_POST 参数
     *
     * @param string|null $key 参数键，null 则返回全部
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getPost(?string $key = null, mixed $default = null): mixed
    {
        $instance = self::getInstance();
        if (!$instance->initialized) {
            $instance->initFromGlobals();
        }

        if ($key === null) {
            return $instance->postParams;
        }

        return $instance->postParams[$key] ?? $default;
    }

    /**
     * 获取 $_COOKIE 参数
     *
     * @param string|null $key 参数键，null 则返回全部
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getCookie(?string $key = null, mixed $default = null): mixed
    {
        $instance = self::getInstance();
        if (!$instance->initialized) {
            $instance->initFromGlobals();
        }

        if ($key === null) {
            return $instance->cookieParams;
        }

        return $instance->cookieParams[$key] ?? $default;
    }

    /**
     * 获取 $_FILES 参数
     *
     * @param string|null $key 参数键，null 则返回全部
     * @return mixed
     */
    public static function getFiles(?string $key = null): mixed
    {
        $instance = self::getInstance();
        if (!$instance->initialized) {
            $instance->initFromGlobals();
        }

        if ($key === null) {
            return $instance->filesParams;
        }

        return $instance->filesParams[$key] ?? null;
    }

    /**
     * 初始化（从全局变量）
     */
    public function initFromGlobals(): void
    {
        if ($this->initialized) {
            return;
        }

        // 在 WLS 模式下，GlobalsEmulator 会模拟 $_GET/$_POST 等
        // 所以这里可以直接从全局变量读取
        $this->getParams = $_GET ?? [];
        $this->postParams = $_POST ?? [];
        $this->cookieParams = $_COOKIE ?? [];
        $this->filesParams = $_FILES ?? [];

        $this->initialized = true;
    }

    /**
     * 从 Request 对象初始化
     *
     * @param \Weline\Framework\Http\Request $request
     * @return void
     */
    public function initFromRequest(object $request): void
    {
        if (\method_exists($request, 'getQueryParams')) {
            $this->getParams = $request->getQueryParams() ?? [];
        }
        if (\method_exists($request, 'getPostParams')) {
            $this->postParams = $request->getPostParams() ?? [];
        }
        if (\method_exists($request, 'getFiles')) {
            $this->filesParams = $request->getFiles() ?? [];
        }

        // 从 Request 对象获取 $_SERVER 变量，同步到 RequestContext
        $this->syncServerFromRequest($request);

        $this->initialized = true;
    }

    /**
     * 从 Request 对象同步 $_SERVER 变量到 RequestContext
     */
    private function syncServerFromRequest(object $request): void
    {
        // 获取 $_SERVER 变量并同步到 RequestContext
        $serverVars = [];
        if (\method_exists($request, 'getServer')) {
            $serverVars = $request->getServer() ?? [];
        }

        foreach (self::MAPPINGS as $alias => $serverKey) {
            if (isset($serverVars[$serverKey])) {
                RequestContext::set('env.' . $alias, $serverVars[$serverKey]);
            }
        }
    }

    /**
     * 重置（请求结束时调用）
     */
    public function reset(): void
    {
        $this->getParams = [];
        $this->postParams = [];
        $this->cookieParams = [];
        $this->filesParams = [];
        $this->overrides = [];
        $this->initialized = false;
    }

    /**
     * 快照当前状态（用于 Fiber 上下文保存）
     *
     * @return array
     */
    public function capture(): array
    {
        return [
            'get' => $this->getParams,
            'post' => $this->postParams,
            'cookie' => $this->cookieParams,
            'files' => $this->filesParams,
            'overrides' => $this->overrides,
        ];
    }

    /**
     * 恢复快照（用于 Fiber 上下文恢复）
     *
     * @param array $snapshot
     * @return void
     */
    public function restore(array $snapshot): void
    {
        $this->getParams = $snapshot['get'] ?? [];
        $this->postParams = $snapshot['post'] ?? [];
        $this->cookieParams = $snapshot['cookie'] ?? [];
        $this->filesParams = $snapshot['files'] ?? [];
        $this->overrides = $snapshot['overrides'] ?? [];
    }

    // ==================== 快捷访问方法 ====================

    /**
     * 获取当前区域（frontend/backend）
     */
    public static function getArea(): string
    {
        return (string) self::get('area', 'frontend');
    }

    /**
     * 设置当前区域
     */
    public static function setArea(string $area): void
    {
        self::set('area', $area, 'Url::parser');
    }

    /**
     * 获取当前语言
     */
    public static function getLang(): string
    {
        return (string) self::get('user.lang', 'zh_Hans_CN');
    }

    /**
     * 设置当前语言
     */
    public static function setLang(string $lang): void
    {
        self::set('user.lang', $lang, 'Url::parser');
    }

    /**
     * 获取当前货币
     */
    public static function getCurrency(): string
    {
        return (string) self::get('user.currency', 'CNY');
    }

    /**
     * 设置当前货币
     */
    public static function setCurrency(string $currency): void
    {
        self::set('user.currency', $currency, 'Url::parser');
    }

    /**
     * 获取网站 ID
     */
    public static function getWebsiteId(): ?int
    {
        $id = self::get('website_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * 设置网站 ID
     */
    public static function setWebsiteId(int $id): void
    {
        self::set('website_id', (string) $id, 'Website resolution');
    }

    /**
     * 获取网站 Code
     */
    public static function getWebsiteCode(): string
    {
        return (string) self::get('website_code', '');
    }

    /**
     * 获取请求方案（http/https）
     */
    public static function getRequestScheme(): string
    {
        return (string) self::get('request.scheme', 'http');
    }

    /**
     * 是否 HTTPS
     */
    public static function isHttps(): bool
    {
        return self::getRequestScheme() === 'https';
    }

    /**
     * 获取 HTTP Host
     */
    public static function getHttpHost(): string
    {
        return (string) self::get('server.http_host', 'localhost');
    }

    /**
     * 获取客户端 IP
     */
    public static function getClientIp(): string
    {
        return (string) self::get('server.remote_addr', '0.0.0.0');
    }

    /**
     * 获取请求方法
     */
    public static function getRequestMethod(): string
    {
        return strtoupper((string) self::get('request.method', 'GET'));
    }

    /**
     * 获取请求 URI
     */
    public static function getRequestUri(): string
    {
        return (string) self::get('request.uri', '/');
    }

    /**
     * 获取用户 ID
     */
    public static function getUserId(): ?int
    {
        $id = self::get('user.id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * 获取 Session ID
     */
    public static function getSessionId(): ?string
    {
        return self::get('user.session_id');
    }

    /**
     * 获取 WLS 重定向次数
     */
    public static function getRedirectCount(): int
    {
        return (int) self::get('wls.redirect_count', 0);
    }

    /**
     * 增加 WLS 重定向次数
     */
    public static function incRedirectCount(): void
    {
        $count = self::getRedirectCount();
        self::set('wls.redirect_count', (string) ($count + 1), 'WlsRuntime redirect');
    }

    /**
     * 是否为后台请求
     */
    public static function isBackend(): bool
    {
        return self::getArea() === 'backend';
    }

    /**
     * 是否为前台请求
     */
    public static function isFrontend(): bool
    {
        return self::getArea() === 'frontend';
    }

    /**
     * 是否 URL 已解析
     */
    public static function isUrlParsed(): bool
    {
        return (bool) self::get('url_parsed', false);
    }

    /**
     * 获取 WLS 实例名
     */
    public static function getWlsInstanceName(): string
    {
        return (string) self::get('wls.instance_name', '');
    }

    /**
     * 获取 WLS 进程标签
     */
    public static function getWlsProcessTag(): string
    {
        return (string) self::get('wls.process_tag', '');
    }

    // ==================== 内部实现 ====================

    /**
     * 获取变量
     */
    private function getVar(string $key, mixed $default): mixed
    {
        // 1. 先检查是否有覆盖
        if (isset($this->overrides[$key])) {
            return $this->overrides[$key]['value'];
        }

        // 2. 从 RequestContext 获取（WLS 模式下已同步）
        $rcValue = RequestContext::get('env.' . $key);
        if ($rcValue !== null) {
            return $rcValue;
        }

        // 3. 映射键名，从 $_SERVER 读取
        $serverKey = $this->mapToServerKey($key);
        if ($serverKey !== null && isset($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        // 4. 回退到默认值
        return $default;
    }

    /**
     * 设置变量
     */
    private function setVar(string $key, mixed $value, string $reason): void
    {
        $fiberId = self::getFiberId();

        $this->overrides[$key] = [
            'value' => $value,
            'reason' => $reason,
            'fiber_id' => $fiberId,
            'trace' => $this->getCallerTrace(),
        ];

        // 同时同步到 RequestContext
        RequestContext::set('env.' . $key, $value);

        // 如果是 $_SERVER 键名映射，也更新 $_SERVER（保持兼容性）
        $serverKey = $this->mapToServerKey($key);
        if ($serverKey !== null) {
            $_SERVER[$serverKey] = $value;
        }
    }

    /**
     * 映射别名到 $_SERVER 键名
     */
    private function mapToServerKey(string $key): ?string
    {
        return self::MAPPINGS[$key] ?? null;
    }

    /**
     * 获取调用栈
     */
    private function getCallerTrace(): array
    {
        $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $result = [];
        foreach ($trace as $i => $t) {
            if ($i === 0) {
                continue; // 跳过当前方法
            }
            if (($t['file'] ?? '') === __FILE__) {
                continue; // 跳过框架内部
            }
            $result[] = ($t['file'] ?? '') . ':' . ($t['line'] ?? 0);
            if (\count($result) >= 5) {
                break;
            }
        }
        return $result;
    }

    /**
     * 检查是否已初始化
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * 获取所有覆盖记录（用于调试）
     */
    public function getOverrides(): array
    {
        return $this->overrides;
    }
}
