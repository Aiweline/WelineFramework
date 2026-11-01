<?php
declare(strict_types=1);

namespace Weline\Framework\Env;

use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;

/**
 * Thin facade over the active framework Context.
 *
 * The old API surface is kept so existing code can be moved gradually without
 * keeping request state in this class anymore.
 *
 * 运行时读写不再使用 $_SERVER：get/set 只走 Context / RequestContext；SERVER_MAPPINGS 仅用于
 * initFromSnapshot() 将「入口快照里的 server 数组」同步到 RequestContext（由 FpmRuntime 等传入一次）。
 */
class WelineEnv
{
    /** @see initFromSnapshot() 入口快照键 ↔ Context input.server 键（非全局 $_SERVER 直读） */
    private const SERVER_MAPPINGS = [
        'area' => 'WELINE_AREA',
        'area_route' => 'WELINE_AREA_ROUTE',
        'website_id' => 'WELINE_WEBSITE_ID',
        'website_code' => 'WELINE_WEBSITE_CODE',
        'website_url' => 'WELINE_WEBSITE_URL',
        'user.lang' => 'WELINE_USER_LANG',
        'user.currency' => 'WELINE_USER_CURRENCY',
        'user.id' => 'WELINE_USER_ID',
        'user.session_id' => 'WELINE_USER_SESSION_ID',
        'url_parsed' => 'WELINE_URL_PARSED',
        'is_backend' => 'WELINE_IS_BACKEND',
        'is_static_file' => 'WELINE_IS_STATIC_FILE',
        'is_media' => 'WELINE_IS_MEDIA',
        'parser_url' => 'WELINE_PARSER_URL',
        'origin_request_uri' => 'WELINE_ORIGIN_REQUEST_URI',
        'full_request_uri' => 'WELINE_FULL_REQUEST_URI',
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
        'server.server_software' => 'SERVER_SOFTWARE',
        'server.https' => 'HTTPS',
        'server.php_self' => 'PHP_SELF',
        'server.script_name' => 'SCRIPT_NAME',
        'server.script_filename' => 'SCRIPT_FILENAME',
        'server.path_info' => 'PATH_INFO',
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
        'wls.redirect_count' => 'WLS_REDIRECT_COUNT',
        'redirect_count' => 'REDIRECT_COUNT',
        'wls.request_count' => 'WLS_REQUEST_COUNT',
        'wls.instance' => 'WLS_INSTANCE',
        'wls.instance_name' => 'WLS_INSTANCE_NAME',
        'wls.process_tag' => 'WLS_PROCESS_TAG',
    ];

    private const CONTEXT_MAPPINGS = [
        'area' => 'route.area',
        'area_route' => 'route.area_route',
        'website_id' => 'route.website_id',
        'website_code' => 'route.website_code',
        'website_url' => 'route.website_url',
        'user.lang' => 'route.language',
        'user.currency' => 'route.currency',
        'user.id' => 'session.user_id',
        'user.session_id' => 'session.id',
        'url_parsed' => 'route.url_parsed',
        'is_backend' => 'route.is_backend',
        'is_static_file' => 'route.is_static',
        'is_media' => 'route.is_media',
        'origin_request_uri' => 'input.origin_request_uri',
        'full_request_uri' => 'input.full_request_uri',
        'request.method' => 'input.method',
        'request.body' => 'input.body',
        'request.scheme' => 'input.scheme',
        'request.uri' => 'input.uri',
        'server.http_host' => 'input.host',
        'server.remote_addr' => 'input.ip',
        'wls.redirect_count' => 'runtime.redirect_count',
        'redirect_count' => 'runtime.redirect_count',
        'wls.request_count' => 'runtime.request_count',
        'wls.instance' => 'meta.instance',
        'wls.instance_name' => 'meta.instance',
        'wls.process_tag' => 'meta.process_tag',
    ];

    private static ?self $instance = null;

    private array $overrides = [];

    public static function getInstance(): self
    {
        self::$instance ??= new self();
        return self::$instance;
    }

    public static function getFiberId(): string
    {
        $fiber = \Fiber::getCurrent();
        return $fiber === null ? 'main' : (string)\spl_object_id($fiber);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $contextPath = self::CONTEXT_MAPPINGS[$key] ?? null;
            if ($contextPath !== null && $context->has($contextPath)) {
                return $context->get($contextPath, $default);
            }

            $rawValue = $context->getRuntimeAttr($key, null);
            if ($rawValue !== null) {
                return $rawValue;
            }
        }

        if (\class_exists(RequestContext::class, false)) {
            $requestContextValue = RequestContext::get('env.' . $key);
            if ($requestContextValue !== null) {
                return $requestContextValue;
            }
        }

        return $default;
    }

    public static function set(string $key, mixed $value, string $reason = ''): void
    {
        $context = Context::getCurrent();
        if ($context === null) {
            $context = new Context();
            Context::enter($context);
        }

        $contextPath = self::CONTEXT_MAPPINGS[$key] ?? null;
        if ($contextPath !== null) {
            $context->set($contextPath, $value);
        } else {
            $context->setRuntimeAttr($key, $value);
        }

        $serverKey = self::SERVER_MAPPINGS[$key] ?? null;
        if ($serverKey !== null) {
            $context->set('input.server.' . $serverKey, $value);
            self::syncContextFromServerValue($context, $serverKey, $value);
        }

        if (\class_exists(RequestContext::class, false)) {
            RequestContext::set('env.' . $key, $value);
        }

        self::getInstance()->recordOverride($key, $value, $reason);
    }

    public static function server(?string $key = null, mixed $default = null): mixed
    {
        $context = self::currentRequestContext();
        if ($context !== null) {
            return $key === null ? $context->server() : $context->server($key, $default);
        }

        if (!self::canFallbackToServerGlobal()) {
            return $key === null ? [] : $default;
        }

        if ($key === null) {
            return \is_array($_SERVER ?? null) ? $_SERVER : [];
        }

        return $_SERVER[$key] ?? $default;
    }

    public static function serverAll(): array
    {
        $server = self::server(null, []);
        return \is_array($server) ? $server : [];
    }

    /**
     * Context 优先、超全局兜底的 server 读取。
     *
     * 与 {@see self::server()} 的区别：key 在 Context 里**缺失**时才回落超全局；
     * 存在但为空串仍以 Context 为准。用于 `WLS_WORKER_ID`、`WLS_PORT` 这类
     * 没有进 Context 映射表的进程/传输键，保证改造前后取值一致。
     */
    public static function serverOrGlobal(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::serverAllOrGlobal();
        }

        $context = self::currentRequestContext();
        if ($context !== null && $context->has('input.server.' . $key)) {
            return $context->server($key, $default);
        }

        return \is_array($_SERVER ?? null) ? ($_SERVER[$key] ?? $default) : $default;
    }

    /**
     * 该 server 键是否**存在**（哪怕值是 0 / 空串）。
     *
     * 与 {@see self::serverOrGlobal()} 配套：有些调用点要区分"键存在且为 0"与"键不存在"，
     * 例如站点 id 显式为 0（全局作用域）时不得回落到 cookie。
     */
    public static function hasServerOrGlobal(string $key): bool
    {
        $context = self::currentRequestContext();
        if ($context !== null && $context->has('input.server.' . $key)) {
            return true;
        }

        return \is_array($_SERVER ?? null) && \array_key_exists($key, $_SERVER);
    }

    /**
     * 整张 server 表：以超全局为底，Context 快照覆盖在上面（Context 的键优先）。
     *
     * 用 array_replace 而不是二选一：Context 只保证"本次请求见过的键"，
     * 直接返回 Context 会丢掉它没见过的进程键（WLS_WORKER_ID 等）。
     */
    public static function serverAllOrGlobal(): array
    {
        $server = \is_array($_SERVER ?? null) ? $_SERVER : [];

        $context = self::currentRequestContext();
        if ($context !== null) {
            $contextServer = $context->server();
            if (\is_array($contextServer) && $contextServer !== []) {
                $server = \array_replace($server, $contextServer);
            }
        }

        return $server;
    }

    /** 入站 cookie 整表：同样以超全局为底，Context 覆盖（Context 的键优先）。 */
    public static function inboundCookieBag(): array
    {
        $bag = \is_array($_COOKIE ?? null) ? $_COOKIE : [];

        $context = self::currentRequestContext();
        if ($context !== null) {
            $contextBag = $context->cookie();
            if (\is_array($contextBag) && $contextBag !== []) {
                $bag = \array_replace($bag, $contextBag);
            }
        }

        return $bag;
    }

    /**
     * 把一次 server 覆盖镜像到进程超全局（FPM 下给仍直读 `$_SERVER` 的旧代码用）。
     *
     * 与 {@see self::setServer()} 配合：setServer 写 Context，本方法只补超全局镜像。
     * 超全局写入口集中在这里，便于后续把所有读取点收敛到门面后一次性删除。
     */
    public static function mirrorServerToGlobal(string $key, mixed $value): void
    {
        $_SERVER[$key] = $value;
    }

    /** 同上，针对 query 超全局（`setGet()` 只写 Context，这里补镜像）。 */
    public static function mirrorGetToGlobal(string $key, mixed $value): void
    {
        $_GET[$key] = $value;
    }

    public static function setServer(string $key, mixed $value, string $reason = ''): void
    {
        $context = Context::current();
        $context->set('input.server.' . $key, $value);
        self::syncContextFromServerValue($context, $key, $value);

        $alias = self::serverAliasForKey($key);
        if ($alias !== null) {
            self::set($alias, $value, $reason !== '' ? $reason : 'WelineEnv::setServer');
            return;
        }

        if (\class_exists(RequestContext::class, false)) {
            RequestContext::set('env.server.' . $key, $value);
        }
    }

    public static function removeServer(string $key): void
    {
        $context = Context::current();
        $server = $context->server();
        if (\is_array($server) && \array_key_exists($key, $server)) {
            unset($server[$key]);
            $context->set('input.server', $server);
        }

        $alias = self::serverAliasForKey($key);
        if ($alias !== null) {
            $contextPath = self::CONTEXT_MAPPINGS[$alias] ?? null;
            if ($contextPath !== null) {
                $context->remove($contextPath);
            }
            if (\class_exists(RequestContext::class, false)) {
                RequestContext::remove('env.' . $alias);
            }
            return;
        }

        if (\class_exists(RequestContext::class, false)) {
            RequestContext::remove('env.server.' . $key);
        }
    }

    public static function replaceServer(array $server, string $reason = ''): void
    {
        self::getInstance()->initFromSnapshot(
            (array)self::getGet(null, []),
            (array)self::getPost(null, []),
            (array)self::getCookie(null, []),
            (array)self::getFiles(null),
            $server
        );

        if ($reason !== '') {
            foreach ($server as $key => $value) {
                if (\is_string($key)) {
                    self::getInstance()->recordOverride('server.' . $key, $value, $reason);
                }
            }
        }
    }

    public static function setGet(string $key, mixed $value): void
    {
        $context = Context::current();
        $query = $context->query();
        if (!\is_array($query)) {
            $query = [];
        }
        $query[$key] = $value;
        $context->set('input.query', $query);
    }

    public static function replaceGet(array $get): void
    {
        Context::current()->set('input.query', $get);
    }

    public static function getGet(?string $key = null, mixed $default = null): mixed
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            return $key === null ? $context->query() : $context->query($key, $default);
        }

        if ($key === null) {
            return \is_array($_GET ?? null) ? $_GET : [];
        }

        return $_GET[$key] ?? $default;
    }

    public static function getPost(?string $key = null, mixed $default = null): mixed
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            return $key === null ? $context->post() : $context->post($key, $default);
        }

        if ($key === null) {
            return \is_array($_POST ?? null) ? $_POST : [];
        }

        return $_POST[$key] ?? $default;
    }

    public static function getCookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key !== null && $key !== '') {
            $key = \Weline\Framework\Http\CookieScope::qualifyName($key);
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            return $key === null ? $context->cookie() : $context->cookie($key, $default);
        }

        if ($key === null) {
            return \is_array($_COOKIE ?? null) ? $_COOKIE : [];
        }

        return $_COOKIE[$key] ?? $default;
    }

    /**
     * 入站 cookie 里是否存在这个**原始线名**（不做 CookieScope 限定）。
     *
     * 供热路径文件使用：它们禁止直接读 `$_COOKIE`，超全局只允许留在这一个门面里。
     */
    public static function hasInboundCookie(string $name): bool
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $bag = $context->cookie();
            if (\is_array($bag) && \array_key_exists($name, $bag)) {
                return true;
            }
        }

        return \is_array($_COOKIE ?? null) && \array_key_exists($name, $_COOKIE);
    }

    /**
     * 原始线名的入站 cookie 值（不做 CookieScope 限定）。
     *
     * 会话 id 这类"协议名固定、不走作用域后缀"的键用它；超全局只留在这个门面里。
     */
    public static function inboundCookie(string $name, string $default = ''): string
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $bag = $context->cookie();
            if (\is_array($bag) && \array_key_exists($name, $bag)) {
                return (string)$bag[$name];
            }
        }

        return \is_array($_COOKIE ?? null) && \array_key_exists($name, $_COOKIE)
            ? (string)$_COOKIE[$name]
            : $default;
    }

    /**
     * 入站 Cookie 头原文：Context 的 server 快照优先，缺失时才回落超全局。
     */
    public static function inboundCookieHeader(): string
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $header = (string)$context->server('HTTP_COOKIE', '');
            if ($header !== '') {
                return $header;
            }
        }

        return (string)($_SERVER['HTTP_COOKIE'] ?? '');
    }

    public static function getFiles(?string $key = null): mixed
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            return $key === null ? $context->file() : $context->file($key);
        }

        if ($key === null) {
            return \is_array($_FILES ?? null) ? $_FILES : [];
        }

        return $_FILES[$key] ?? null;
    }

    public function initFromGlobals(): void
    {
        $current = Context::getCurrent();
        $meta = $current?->get('meta', []) ?? [];
        $runtime = $current?->get('runtime', []) ?? [];

        $context = Context::fromGlobals($meta);
        $context->merge([
            'runtime' => $runtime,
        ]);
        Context::enter($context);
    }

    public function initFromRequest(object $request): void
    {
        $current = Context::getCurrent();
        $meta = $current?->get('meta', []) ?? [];
        $runtime = $current?->get('runtime', []) ?? [];
        $route = $current?->get('route', []) ?? [];
        $session = $current?->get('session', []) ?? [];
        $response = $current?->get('response', []) ?? [];

        $context = Context::fromRequest($request, $meta);
        $context->merge([
            'runtime' => $runtime,
            'route' => $route,
            'session' => $session,
            'response' => $response,
        ]);
        Context::enter($context);
    }

    public function initFromSnapshot(
        array $get,
        array $post,
        array $cookie,
        array $files,
        array $server
    ): void {
        $current = Context::getCurrent();
        $meta = $current?->get('meta', []) ?? [];
        $runtime = $current?->get('runtime', []) ?? [];
        $session = $current?->get('session', []) ?? [];
        $response = $current?->get('response', []) ?? [];
        $body = (string)($current?->get('input.body', '') ?? '');

        $context = new Context([
            'meta' => $meta,
            'input' => [
                'query' => $get,
                'post' => $post,
                'cookie' => $cookie,
                'files' => $files,
                'headers' => self::extractHeadersFromServer($server),
                'server' => $server,
                'body' => $body,
                'method' => (string)($server['REQUEST_METHOD'] ?? 'GET'),
                'uri' => (string)($server['REQUEST_URI'] ?? '/'),
                'origin_request_uri' => (string)($server['WELINE_ORIGIN_REQUEST_URI'] ?? $server['REQUEST_URI'] ?? '/'),
                'full_request_uri' => (string)($server['WELINE_FULL_REQUEST_URI'] ?? ''),
                'scheme' => (string)($server['REQUEST_SCHEME'] ?? 'http'),
                'host' => (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''),
                'ip' => (string)($server['REMOTE_ADDR'] ?? ''),
            ],
            'route' => [
                'area' => (string)($server['WELINE_AREA'] ?? $current?->get('route.area', 'frontend') ?? 'frontend'),
                'area_route' => (string)($server['WELINE_AREA_ROUTE'] ?? ''),
                'website_id' => (int)($server['WELINE_WEBSITE_ID'] ?? 0),
                'website_code' => (string)($server['WELINE_WEBSITE_CODE'] ?? ''),
                'website_url' => (string)($server['WELINE_WEBSITE_URL'] ?? ''),
                'language' => (string)($server['WELINE_USER_LANG'] ?? 'zh_Hans_CN'),
                'currency' => (string)($server['WELINE_USER_CURRENCY'] ?? 'CNY'),
                'is_backend' => (bool)($server['WELINE_IS_BACKEND'] ?? false),
                'is_static' => (bool)($server['WELINE_IS_STATIC_FILE'] ?? false),
                'url_parsed' => (bool)($server['WELINE_URL_PARSED'] ?? false),
            ],
            'session' => \array_merge([
                'id' => (string)($server['WELINE_USER_SESSION_ID'] ?? $cookie['WELINE_SESSID'] ?? ''),
                'user_id' => (int)($server['WELINE_USER_ID'] ?? 0),
            ], $session),
            'response' => $response,
            'runtime' => $runtime,
        ]);

        Context::enter($context);

        if (\class_exists(RequestContext::class, false)) {
            foreach (self::SERVER_MAPPINGS as $alias => $serverKey) {
                if (\array_key_exists($serverKey, $server)) {
                    RequestContext::set('env.' . $alias, $server[$serverKey]);
                } else {
                    RequestContext::remove('env.' . $alias);
                }
            }
        }
    }

    public function reset(bool $leaveContext = true): void
    {
        $this->clearRequestContextEnvShadow();
        $this->overrides = [];
        if ($leaveContext) {
            Context::leave();
        }
    }

    public function capture(): array
    {
        $context = Context::getCurrent();

        return [
            'context' => $context?->toArray(),
            'initialized' => $context !== null,
            'overrides' => $this->overrides,
            'get' => $context?->query() ?? [],
            'post' => $context?->post() ?? [],
            'cookie' => $context?->cookie() ?? [],
            'files' => $context?->file() ?? [],
            'server' => $context !== null ? (array)$context->get('input.server', []) : [],
        ];
    }

    public function restore(array $snapshot): void
    {
        $this->overrides = \is_array($snapshot['overrides'] ?? null) ? $snapshot['overrides'] : [];

        if (\is_array($snapshot['context'] ?? null)) {
            Context::enter(new Context($snapshot['context']));
            return;
        }

        $this->initFromSnapshot(
            (array)($snapshot['get'] ?? []),
            (array)($snapshot['post'] ?? []),
            (array)($snapshot['cookie'] ?? []),
            (array)($snapshot['files'] ?? []),
            \is_array($snapshot['server'] ?? null) ? $snapshot['server'] : []
        );
    }

    public static function getArea(): string
    {
        return (string)self::get('area', 'frontend');
    }

    public static function setArea(string $area): void
    {
        self::set('area', $area, 'WelineEnv::setArea');
    }

    /**
     * Current request language (Env read authority over RequestContext).
     * Priority: request override → route.language / env.user.lang → area default.
     * Does not read preference cookies.
     */
    public static function getLang(): string
    {
        // 请求初始化时已把语言写进上下文（App::primeRequestRouteHints →
        // RequestContext::setWelineUserLang），同一请求内直接取，不再重走
        // 覆盖 → 路由语言 → 区域默认 的三档解析。
        $context = Context::getCurrent();
        if ($context !== null) {
            $cached = $context->get('state.lang_cache', null);
            if (\is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $forced = \Weline\Framework\App\State::getRequestLanguageOverride();
        if ($forced !== '' && \Weline\Framework\App\State::isLanguageCodeShape($forced)) {
            $lang = \Weline\Framework\App\State::normalizeLanguageCode($forced);
        } else {
            $routeLang = $context !== null ? (string)$context->get('route.language', '') : '';
            $lang = $routeLang !== '' ? $routeLang : self::readRouteLanguage();
            if ($lang === '') {
                $lang = \Weline\Framework\App\State::resolveAreaDefaultLanguage();
            }
        }

        if ($context !== null) {
            $context->set('state.lang_cache', $lang);
        }

        return $lang;
    }

    public static function setLang(string $lang): void
    {
        self::set('user.lang', $lang, 'WelineEnv::setLang');
        if (\class_exists(RequestContext::class, false)) {
            RequestContext::setWelineUserLang($lang);
        }
    }

    /**
     * Current request currency (Env read authority over RequestContext).
     * Priority: route.currency / env.user.currency → website default.
     * Does not read preference cookies.
     */
    public static function getCurrency(): string
    {
        // 与 getLang() 同口径：请求初始化已写好币种，请求内直接取上下文。
        $context = Context::getCurrent();
        if ($context !== null) {
            $cached = $context->get('state.currency_cache', null);
            if (\is_string($cached) && $cached !== '') {
                return $cached;
            }
            $routeCurrency = (string)$context->get('route.currency', '');
            $currency = $routeCurrency !== ''
                ? \strtoupper(\trim($routeCurrency))
                : self::readRouteCurrency();
            if ($currency === '' || !\Weline\Framework\App\State::isAllowedCurrencyCode($currency)) {
                $currency = \Weline\Framework\App\State::resolveWebsiteDefaultCurrency();
            }
            $context->set('state.currency_cache', $currency);

            return $currency;
        }

        $routeCurrency = self::readRouteCurrency();
        if ($routeCurrency !== '') {
            return $routeCurrency;
        }

        return \Weline\Framework\App\State::resolveWebsiteDefaultCurrency();
    }

    public static function setCurrency(string $currency): void
    {
        self::set('user.currency', $currency, 'WelineEnv::setCurrency');
        if (\class_exists(RequestContext::class, false)) {
            RequestContext::setWelineUserCurrency($currency);
        }
    }

    /**
     * Localization display locale (may be adjusted by Weline_Framework_Cookie::lang_local).
     */
    public static function getLangLocal(): string
    {
        // 请求上下文在初始化时已写好语言（App::primeRequestRouteHints →
        // RequestContext::setWelineUserLang），显示语言在同一请求内恒定为
        // `route.language`。请求内直接读上下文；只有语言被覆盖/切换
        // （State::resetLangLocalCache 清空缓存）时才重算。
        $context = Context::getCurrent();
        if ($context !== null) {
            $cached = $context->get('state.lang_local_cache', null);
            if (\is_string($cached) && $cached !== '') {
                return $cached;
            }
            $routeLang = (string)$context->get('route.language', '');
            if ($routeLang !== '') {
                $context->set('state.lang_local_cache', $routeLang);

                return $routeLang;
            }
        }

        // 无请求上下文（CLI / 引导期）：走一次完整解析。
        $langLocal = self::resolveLangLocal();
        if ($context !== null) {
            $context->set('state.lang_local_cache', $langLocal);
        }

        return $langLocal;
    }

    /**
     * Full resolution: lang-mapped display locale, overridable by the
     * `lang_local` event. Only used when the request context cannot answer.
     */
    private static function resolveLangLocal(): string
    {
        $lang = self::getLang();
        $data = new \Weline\Framework\DataObject\DataObject();
        $data->setData('lang', $lang);
        $data->setData('currency', self::getCurrency());
        $data->setData('lang_local', $lang);

        try {
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
                ->dispatch('Weline_Framework_Cookie::lang_local', $data);
        } catch (\Exception) {
        }

        return (string)$data->getData('lang_local');
    }

    private static function readRouteLanguage(): string
    {
        $context = Context::getCurrent();
        if ($context !== null && $context->has('route.language')) {
            $lang = \Weline\Framework\App\State::normalizeLanguageCode((string)$context->get('route.language', ''));
            if ($lang !== '' && \Weline\Framework\App\State::isLanguageCodeShape($lang)
                && self::languageAllowedForCurrentArea($lang)
            ) {
                return $lang;
            }
        }

        try {
            $lang = \Weline\Framework\App\State::normalizeLanguageCode((string)self::get('user.lang', ''));
            if ($lang !== '' && \Weline\Framework\App\State::isLanguageCodeShape($lang)
                && self::languageAllowedForCurrentArea($lang)
            ) {
                return $lang;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function readRouteCurrency(): string
    {
        $context = Context::getCurrent();
        if ($context !== null && $context->has('route.currency')) {
            $currency = \strtoupper(\trim((string)$context->get('route.currency', '')));
            if ($currency !== '' && \Weline\Framework\App\State::isAllowedCurrencyCode($currency)) {
                return $currency;
            }
        }

        try {
            $currency = \strtoupper(\trim((string)self::get('user.currency', '')));
            if ($currency !== '' && \Weline\Framework\App\State::isAllowedCurrencyCode($currency)) {
                return $currency;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function languageAllowedForCurrentArea(string $code): bool
    {
        $area = '';
        try {
            $area = (string)self::get('area', '');
        } catch (\Throwable) {
        }

        if ($area === 'backend' || $area === 'rest_backend' || \Weline\Framework\App\State::isBackend()) {
            return \Weline\Framework\App\State::isLanguageCodeShape($code);
        }

        return \Weline\Framework\App\State::isAllowedLanguageCode($code);
    }

    public static function getWebsiteId(): ?int
    {
        $value = self::get('website_id', null);
        return $value === null ? null : (int)$value;
    }

    public static function getWebsiteCode(): string
    {
        return (string)self::get('website_code', '');
    }

    public static function getWebsiteUrl(): string
    {
        return (string)self::get('website_url', '');
    }

    public static function getRequestScheme(): string
    {
        return (string)self::get('request.scheme', 'http');
    }

    public static function isHttps(): bool
    {
        return self::getRequestScheme() === 'https';
    }

    public static function getHttpHost(): string
    {
        return (string)self::get('server.http_host', 'localhost');
    }

    public static function getClientIp(): string
    {
        return (string)self::get('server.remote_addr', '0.0.0.0');
    }

    public static function getRequestMethod(): string
    {
        return \strtoupper((string)self::get('request.method', 'GET'));
    }

    public static function getRequestUri(): string
    {
        return (string)self::get('request.uri', '/');
    }

    public static function getUserId(): ?int
    {
        $value = self::get('user.id', null);
        return $value === null ? null : (int)$value;
    }

    public static function getSessionId(): ?string
    {
        $value = self::get('user.session_id', null);
        return $value === null ? null : (string)$value;
    }

    public static function getRedirectCount(): int
    {
        return (int)self::get('wls.redirect_count', 0);
    }

    public static function incRedirectCount(): void
    {
        self::set('wls.redirect_count', self::getRedirectCount() + 1, 'WelineEnv::incRedirectCount');
    }

    public static function isBackend(): bool
    {
        return self::getArea() === 'backend';
    }

    public static function isFrontend(): bool
    {
        return self::getArea() === 'frontend';
    }

    public static function isUrlParsed(): bool
    {
        return (bool)self::get('url_parsed', false);
    }

    public static function getWlsInstanceName(): string
    {
        return (string)self::get('wls.instance_name', '');
    }

    public static function getWlsProcessTag(): string
    {
        return (string)self::get('wls.process_tag', '');
    }

    public function isInitialized(): bool
    {
        return Context::hasCurrent();
    }

    public function getOverrides(): array
    {
        return $this->overrides;
    }

    private static function currentRequestContext(): ?Context
    {
        if (\class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent()
            && \class_exists(\Fiber::class)
            && \Fiber::getCurrent() === null
        ) {
            return null;
        }

        return Context::getCurrent();
    }

    private static function canFallbackToServerGlobal(): bool
    {
        return !(\class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent()
            && \class_exists(\Fiber::class)
            && \Fiber::getCurrent() === null);
    }

    private static function serverAliasForKey(string $serverKey): ?string
    {
        $alias = \array_search($serverKey, self::SERVER_MAPPINGS, true);
        return \is_string($alias) ? $alias : null;
    }

    private static function syncContextFromServerValue(Context $context, string $key, mixed $value): void
    {
        match ($key) {
            'REQUEST_METHOD' => $context->set('input.method', (string)$value),
            'REQUEST_URI' => $context->set('input.uri', (string)$value),
            'WELINE_ORIGIN_REQUEST_URI' => $context->set('input.origin_request_uri', (string)$value),
            'WELINE_FULL_REQUEST_URI' => $context->set('input.full_request_uri', (string)$value),
            'REQUEST_SCHEME' => $context->set('input.scheme', (string)$value),
            'HTTP_HOST', 'SERVER_NAME' => $context->set('input.host', (string)$value),
            'REMOTE_ADDR' => $context->set('input.ip', (string)$value),
            default => null,
        };

        if (\str_starts_with($key, 'HTTP_') || \in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $headers = (array)$context->header();
            $headerKey = \str_starts_with($key, 'HTTP_') ? \substr($key, 5) : $key;
            $headers[\str_replace('_', '-', $headerKey)] = $value;
            $context->set('input.headers', $headers);
        }
    }

    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (\str_starts_with($key, 'HTTP_')) {
                $headers[\str_replace('_', '-', \substr($key, 5))] = $value;
                continue;
            }

            if (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[\str_replace('_', '-', $key)] = $value;
            }
        }

        return $headers;
    }

    private function recordOverride(string $key, mixed $value, string $reason): void
    {
        $this->overrides[$key] = [
            'value' => $value,
            'reason' => $reason,
            'fiber_id' => self::getFiberId(),
            'trace' => $this->shouldCaptureOverrideTrace() ? $this->getCallerTrace() : [],
        ];
    }

    private function shouldCaptureOverrideTrace(): bool
    {
        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            return false;
        }

        return (bool)\Weline\Framework\App\Env::get('wls.debug.env_override_trace', false);
    }

    private function getCallerTrace(): array
    {
        $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $result = [];
        foreach ($trace as $entry) {
            if (($entry['file'] ?? '') === __FILE__) {
                continue;
            }
            if (!isset($entry['file'], $entry['line'])) {
                continue;
            }
            $result[] = $entry['file'] . ':' . $entry['line'];
            if (\count($result) >= 5) {
                break;
            }
        }

        return $result;
    }

    private function clearRequestContextEnvShadow(): void
    {
        if (!\class_exists(RequestContext::class, false)) {
            return;
        }

        foreach (RequestContext::all() as $key => $_value) {
            if (!\is_string($key) || !\str_starts_with($key, 'env.')) {
                continue;
            }
            RequestContext::remove($key);
        }
    }
}
