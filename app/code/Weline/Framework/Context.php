<?php
declare(strict_types=1);

namespace Weline\Framework;

/**
 * Single runtime context for both request and system execution paths.
 *
 * Request-scoped state must live here instead of superglobals or static caches.
 */
class Context
{
    private array $data;

    private static ?self $mainContext = null;

    /** @var \WeakMap<\Fiber, self>|null */
    private static ?\WeakMap $fiberContexts = null;

    public function __construct(array $data = [])
    {
        $this->data = self::mergeRecursive(self::defaults(), $data);
    }

    public static function fromGlobals(array $meta = []): self
    {
        $server = \is_array($_SERVER ?? null) ? $_SERVER : [];
        $cookies = \is_array($_COOKIE ?? null) ? $_COOKIE : [];

        return new self([
            'meta' => \array_merge(self::defaultMeta(), $meta),
            'input' => [
                'query' => \is_array($_GET ?? null) ? $_GET : [],
                'post' => \is_array($_POST ?? null) ? $_POST : [],
                'cookie' => $cookies,
                'files' => \is_array($_FILES ?? null) ? $_FILES : [],
                'headers' => self::extractHeadersFromServer($server),
                'server' => $server,
                'body' => self::readPhpInput(),
                'method' => (string)($server['REQUEST_METHOD'] ?? 'GET'),
                'uri' => (string)($server['REQUEST_URI'] ?? '/'),
                'origin_request_uri' => (string)($server['WELINE_ORIGIN_REQUEST_URI'] ?? $server['REQUEST_URI'] ?? '/'),
                'full_request_uri' => (string)($server['WELINE_FULL_REQUEST_URI'] ?? ''),
                'scheme' => self::detectScheme($server),
                'host' => (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''),
                'ip' => (string)($server['REMOTE_ADDR'] ?? ''),
            ],
            'route' => self::routeFromServer($server),
            'session' => [
                'id' => (string)($server['WELINE_USER_SESSION_ID'] ?? $cookies['WELINE_SESSID'] ?? ''),
                'user_id' => (int)($server['WELINE_USER_ID'] ?? 0),
                'authenticated' => false,
                'csrf' => '',
            ],
            'runtime' => [
                'debug' => \defined('DEBUG') && DEBUG,
                'sandbox' => \defined('SANDBOX') && SANDBOX,
                'redirect_count' => (int)($server['WLS_REDIRECT_COUNT'] ?? $server['REDIRECT_COUNT'] ?? 0),
                'request_count' => (int)($server['WLS_REQUEST_COUNT'] ?? 0),
                'attrs' => [],
                'attrs_raw' => [],
            ],
        ]);
    }

    public static function fromRequest(object $request, array $meta = []): self
    {
        $query = \method_exists($request, 'getQueryParams') ? (array)($request->getQueryParams() ?? []) : [];
        $post = \method_exists($request, 'getPostParams') ? (array)($request->getPostParams() ?? []) : [];
        $files = \method_exists($request, 'getFiles') ? (array)($request->getFiles() ?? []) : [];
        $headers = \method_exists($request, 'getHeaders') ? (array)($request->getHeaders() ?? []) : [];

        $server = [];
        if (\method_exists($request, 'getServer')) {
            $serverValue = $request->getServer();
            $server = \is_array($serverValue) ? $serverValue : [];
        }

        $method = \method_exists($request, 'getMethod')
            ? (string)$request->getMethod()
            : (string)($server['REQUEST_METHOD'] ?? 'GET');
        $uri = \method_exists($request, 'getUri')
            ? (string)$request->getUri()
            : (string)($server['REQUEST_URI'] ?? '/');
        $scheme = \method_exists($request, 'isSecure')
            ? ($request->isSecure() ? 'https' : 'http')
            : self::detectScheme($server);
        $host = '';
        if (\method_exists($request, 'getHeader')) {
            $host = (string)($request->getHeader('Host') ?? '');
        }
        if ($host === '') {
            $host = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        }

        $body = '';
        if (\method_exists($request, 'getRawData')) {
            $body = (string)$request->getRawData();
        } elseif (\method_exists($request, 'getBodyParams')) {
            $bodyValue = $request->getBodyParams();
            if (\is_string($bodyValue)) {
                $body = $bodyValue;
            }
        }

        $path = $uri === '' ? '/' : $uri;
        if ($path !== '' && !\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $fullRequestUri = (string)($server['WELINE_FULL_REQUEST_URI'] ?? '');
        if ($fullRequestUri === '' || !\str_contains($fullRequestUri, '://')) {
            if ($host !== '') {
                $fullRequestUri = $scheme . '://' . $host . $path;
            }
        }

        return new self([
            'meta' => \array_merge(self::defaultMeta(), $meta),
            'input' => [
                'query' => $query,
                'post' => $post,
                'cookie' => self::extractCookies($request, $server),
                'files' => $files,
                'headers' => $headers,
                'server' => $server,
                'body' => $body,
                'method' => $method,
                'uri' => $uri === '' ? '/' : $uri,
                'origin_request_uri' => (string)($server['WELINE_ORIGIN_REQUEST_URI'] ?? ($uri === '' ? '/' : $uri)),
                'full_request_uri' => $fullRequestUri,
                'scheme' => $scheme,
                'host' => $host,
                'ip' => (string)($server['REMOTE_ADDR'] ?? ''),
            ],
            'route' => self::routeFromServer($server),
            'session' => [
                'id' => (string)($server['WELINE_USER_SESSION_ID'] ?? self::extractSessionIdFromCookies(self::extractCookies($request, $server))),
                'user_id' => (int)($server['WELINE_USER_ID'] ?? 0),
                'authenticated' => false,
                'csrf' => '',
            ],
            'runtime' => [
                'debug' => \defined('DEBUG') && DEBUG,
                'sandbox' => \defined('SANDBOX') && SANDBOX,
                'redirect_count' => (int)($server['WLS_REDIRECT_COUNT'] ?? $server['REDIRECT_COUNT'] ?? 0),
                'request_count' => (int)($server['WLS_REQUEST_COUNT'] ?? 0),
                'attrs' => [],
                'attrs_raw' => [],
            ],
        ]);
    }

    public static function enter(self $context): void
    {
        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainContext = $context;
            return;
        }

        self::$fiberContexts ??= new \WeakMap();
        self::$fiberContexts[$fiber] = $context;
    }

    public static function getCurrent(): ?self
    {
        $fiber = self::currentFiber();
        if ($fiber !== null && self::$fiberContexts !== null && isset(self::$fiberContexts[$fiber])) {
            return self::$fiberContexts[$fiber];
        }

        return self::$mainContext;
    }

    public static function current(): self
    {
        $current = self::getCurrent();
        if ($current !== null) {
            return $current;
        }

        $context = new self();
        self::enter($context);
        return $context;
    }

    public static function hasCurrent(): bool
    {
        return self::getCurrent() !== null;
    }

    public static function leave(): void
    {
        $fiber = self::currentFiber();
        if ($fiber === null) {
            self::$mainContext = null;
            return;
        }

        if (self::$fiberContexts !== null && isset(self::$fiberContexts[$fiber])) {
            unset(self::$fiberContexts[$fiber]);
        }
    }

    public static function detachRequestGlobals(array $extraServerKeepKeys = []): void
    {
        unset($_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST);

        if (!\is_array($_SERVER ?? null)) {
            return;
        }

        $keepKeys = \array_fill_keys(\array_merge([
            'argc',
            'argv',
            'DOCUMENT_ROOT',
            'FCGI_ROLE',
            'GATEWAY_INTERFACE',
            'PATH_TRANSLATED',
            'PHP_SELF',
            'REDIRECT_STATUS',
            'SCRIPT_FILENAME',
            'SCRIPT_NAME',
            'SERVER_ADDR',
            'SERVER_ADMIN',
            'SERVER_PORT',
            'SERVER_PROTOCOL',
            'SERVER_SIGNATURE',
            'SERVER_SOFTWARE',
        ], $extraServerKeepKeys), true);

        $_SERVER = \array_intersect_key($_SERVER, $keepKeys);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $this->data;
        }

        $segments = \explode('.', $path);
        $value = $this->data;
        foreach ($segments as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $path, mixed $value): void
    {
        $segments = \explode('.', $path);
        $target =& $this->data;
        foreach ($segments as $index => $segment) {
            if ($index === \count($segments) - 1) {
                $target[$segment] = $value;
                return;
            }

            if (!isset($target[$segment]) || !\is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target =& $target[$segment];
        }
    }

    public function has(string $path): bool
    {
        $marker = new \stdClass();
        return $this->get($path, $marker) !== $marker;
    }

    public function remove(string $path): void
    {
        $segments = \explode('.', $path);
        $target =& $this->data;
        foreach ($segments as $index => $segment) {
            if (!\is_array($target) || !\array_key_exists($segment, $target)) {
                return;
            }

            if ($index === \count($segments) - 1) {
                unset($target[$segment]);
                return;
            }

            $target =& $target[$segment];
        }
    }

    public function merge(array $data): static
    {
        $this->data = self::mergeRecursive($this->data, $data);
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->bag('input.query', $key, $default);
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $this->bag('input.post', $key, $default);
    }

    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        return $this->bag('input.cookie', $key, $default);
    }

    public function file(?string $key = null): mixed
    {
        return $this->bag('input.files', $key, null);
    }

    public function header(?string $key = null, mixed $default = null): mixed
    {
        $headers = (array)$this->get('input.headers', []);
        if ($key === null) {
            return $headers;
        }

        if (\array_key_exists($key, $headers)) {
            return $headers[$key];
        }

        foreach ($headers as $name => $value) {
            if (\strcasecmp((string)$name, $key) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $this->bag('input.server', $key, $default);
    }

    public function method(): string
    {
        return (string)$this->get('input.method', 'GET');
    }

    public function uri(): string
    {
        return (string)$this->get('input.uri', '/');
    }

    public function host(): string
    {
        return (string)$this->get('input.host', '');
    }

    public function ip(): string
    {
        return (string)$this->get('input.ip', '');
    }

    public function area(?string $value = null): mixed
    {
        if ($value !== null) {
            $this->set('route.area', $value);
        }

        return $this->get('route.area');
    }

    public function language(?string $value = null): mixed
    {
        if ($value !== null) {
            $this->set('route.language', $value);
        }

        return $this->get('route.language');
    }

    public function currency(?string $value = null): mixed
    {
        if ($value !== null) {
            $this->set('route.currency', $value);
        }

        return $this->get('route.currency');
    }

    public function websiteId(?int $value = null): mixed
    {
        if ($value !== null) {
            $this->set('route.website_id', $value);
        }

        return $this->get('route.website_id');
    }

    public function sessionId(?string $value = null): mixed
    {
        if ($value !== null) {
            $this->set('session.id', $value);
        }

        return $this->get('session.id');
    }

    public function userId(?int $value = null): mixed
    {
        if ($value !== null) {
            $this->set('session.user_id', $value);
        }

        return $this->get('session.user_id');
    }

    public function status(?int $value = null): mixed
    {
        if ($value !== null) {
            $this->set('response.status', $value);
        }

        return $this->get('response.status', 200);
    }

    public function body(?string $value = null): mixed
    {
        if ($value !== null) {
            $this->set('response.body', $value);
        }

        return $this->get('response.body', '');
    }

    public function responseHeader(string $key, ?string $value = null): mixed
    {
        $headers = (array)$this->get('response.headers', []);
        if ($value === null) {
            return $headers[$key] ?? null;
        }

        $headers[$key] = $value;
        $this->set('response.headers', $headers);

        return $value;
    }

    public function responseCookie(string $name, mixed $value = null, array $meta = []): mixed
    {
        $cookies = (array)$this->get('response.cookies', []);
        if ($value === null && $meta === []) {
            return $cookies[$name] ?? null;
        }

        $cookies[$name] = \array_merge([
            'name' => $name,
            'value' => $value,
            'expire' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ], $meta);
        $this->set('response.cookies', $cookies);

        return $cookies[$name];
    }

    public function getRuntimeAttr(string $key, mixed $default = null): mixed
    {
        $attrs = (array)$this->get('runtime.attrs_raw', []);
        return $attrs[$key] ?? $default;
    }

    public function setRuntimeAttr(string $key, mixed $value): void
    {
        $attrs = (array)$this->get('runtime.attrs_raw', []);
        $attrs[$key] = $value;
        $this->set('runtime.attrs_raw', $attrs);
    }

    private function bag(string $path, ?string $key, mixed $default): mixed
    {
        $bag = (array)$this->get($path, []);
        if ($key === null) {
            return $bag;
        }

        return $bag[$key] ?? $default;
    }

    private static function defaults(): array
    {
        return [
            'meta' => self::defaultMeta(),
            'input' => [
                'query' => [],
                'post' => [],
                'cookie' => [],
                'files' => [],
                'headers' => [],
                'server' => [],
                'body' => '',
                'method' => 'GET',
                'uri' => '/',
                'origin_request_uri' => '/',
                'full_request_uri' => '',
                'scheme' => 'http',
                'host' => '',
                'ip' => '',
            ],
            'route' => [
                'area' => 'frontend',
                'area_route' => '',
                'path' => '',
                'module' => '',
                'controller' => '',
                'action' => '',
                'website_id' => 0,
                'website_code' => '',
                'website_url' => '',
                'language' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'is_backend' => false,
                'is_static' => false,
                'is_media' => false,
                'url_parsed' => false,
            ],
            'session' => [
                'id' => '',
                'user_id' => 0,
                'authenticated' => false,
                'csrf' => '',
            ],
            'response' => [
                'status' => 200,
                'headers' => [],
                'cookies' => [],
                'body' => '',
                'content_type' => 'text/html; charset=utf-8',
                'is_sse' => false,
                'is_download' => false,
                'is_redirect' => false,
                'terminated' => false,
            ],
            'runtime' => [
                'debug' => false,
                'sandbox' => false,
                'redirect_count' => 0,
                'request_count' => 0,
                'attrs' => [],
                'attrs_raw' => [],
            ],
        ];
    }

    private static function defaultMeta(): array
    {
        return [
            'id' => self::generateId(),
            'trace_id' => self::generateId(),
            'type' => \PHP_SAPI === 'cli' ? 'system' : 'request',
            'mode' => \defined('WLS_MODE') && WLS_MODE ? 'wls' : (\PHP_SAPI === 'cli' ? 'cli' : 'fpm'),
            'instance' => '',
            'process_tag' => '',
            'fiber_id' => self::fiberId(),
            'started_at' => \microtime(true),
        ];
    }

    private static function routeFromServer(array $server): array
    {
        $area = (string)($server['WELINE_AREA'] ?? 'frontend');

        return [
            'area' => $area === '' ? 'frontend' : $area,
            'area_route' => (string)($server['WELINE_AREA_ROUTE'] ?? ''),
            'path' => (string)(\parse_url((string)($server['REQUEST_URI'] ?? '/'), \PHP_URL_PATH) ?: '/'),
            'module' => '',
            'controller' => '',
            'action' => '',
            'website_id' => (int)($server['WELINE_WEBSITE_ID'] ?? 0),
            'website_code' => (string)($server['WELINE_WEBSITE_CODE'] ?? ''),
            'website_url' => (string)($server['WELINE_WEBSITE_URL'] ?? ''),
            'language' => (string)($server['WELINE_USER_LANG'] ?? 'zh_Hans_CN'),
            'currency' => (string)($server['WELINE_USER_CURRENCY'] ?? 'CNY'),
            'is_backend' => (bool)($server['WELINE_IS_BACKEND'] ?? ($area === 'backend' || $area === 'rest_backend')),
            'is_static' => (bool)($server['WELINE_IS_STATIC_FILE'] ?? false),
            'is_media' => (bool)($server['WELINE_IS_MEDIA'] ?? false),
            'url_parsed' => (bool)($server['WELINE_URL_PARSED'] ?? false),
        ];
    }

    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (\str_starts_with($key, 'HTTP_')) {
                $headerName = \str_replace('_', '-', \substr($key, 5));
                $headers[$headerName] = $value;
                continue;
            }

            if (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[\str_replace('_', '-', $key)] = $value;
            }
        }

        return $headers;
    }

    private static function extractCookies(object $request, array $server): array
    {
        $cookieHeader = '';
        if (\method_exists($request, 'getHeader')) {
            $cookieValue = $request->getHeader('Cookie');
            if (\is_string($cookieValue)) {
                $cookieHeader = $cookieValue;
            }
        }

        if ($cookieHeader === '') {
            $cookieHeader = (string)($server['HTTP_COOKIE'] ?? '');
        }

        if ($cookieHeader === '') {
            return [];
        }

        $cookies = [];
        foreach (\explode(';', $cookieHeader) as $item) {
            $parts = \explode('=', \trim($item), 2);
            if (\count($parts) !== 2) {
                continue;
            }
            $cookies[\trim($parts[0])] = \urldecode(\trim($parts[1]));
        }

        return $cookies;
    }

    private static function extractSessionIdFromCookies(array $cookies): string
    {
        return (string)($cookies['WELINE_SESSID'] ?? '');
    }

    private static function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private static function readPhpInput(): string
    {
        if (\PHP_SAPI === 'cli') {
            return '';
        }

        $input = @\file_get_contents('php://input');
        return \is_string($input) ? $input : '';
    }

    private static function detectScheme(array $server): string
    {
        $https = \strtolower((string)($server['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        $scheme = \strtolower((string)($server['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return 'https';
        }

        $forwarded = \strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https') {
            return 'https';
        }

        return 'http';
    }

    private static function generateId(): string
    {
        return \bin2hex(\random_bytes(8)) . '-' . (string)\hrtime(true);
    }

    private static function currentFiber(): ?\Fiber
    {
        return \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
    }

    private static function fiberId(): string
    {
        $fiber = self::currentFiber();
        return $fiber === null ? 'main' : (string)\spl_object_id($fiber);
    }
}
