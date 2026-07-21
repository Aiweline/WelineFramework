<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;

use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;

/**
 * Partials Block
 * 用于在模板中加载配置的 partials
 * 
 * 支持通过 block 标签调用：
 * <w:block class="Weline\Theme\Block\Partials" area="frontend" type="head" default-option="default"/>
 */
class Partials extends Block
{
    /**
     * @var array<string, array>
     */
    private static array $partialsMetaCache = [];
    private static ?\WeakMap $fiberRenderYieldAt = null;
    private const PARTIAL_OUTPUT_CACHE_TTL = 300.0;
    private const WLS_RENDER_YIELD_MIN_INTERVAL_US = 10000;
    private const PARTIAL_OUTPUT_CACHE_MAX = 256;
    private const PARTIAL_OUTPUT_STALE_TTL = 600;
    private const PARTIAL_OUTPUT_REFRESH_LOCK_TTL = 10;
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $partialOutputCache = [];
    /** @var array<string, array{mode: string, auth: string, ttl: int}> */
    private static array $chromePolicyCache = [];
    private static ?SharedCacheStateInterface $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public static function clearMetaCache(): void
    {
        self::$partialsMetaCache = [];
        self::$chromePolicyCache = [];
    }

    /**
     * Drop process-local partial HTML output. Prefer clearMetaCache() under soft memory pressure.
     */
    public static function clearOutputCache(): void
    {
        self::$partialOutputCache = [];
        self::$runtimeCache = null;
        self::$runtimeCacheResolved = false;
    }

    public static function clearAllCaches(): void
    {
        self::clearMetaCache();
        self::clearOutputCache();
    }

    /**
     * 初始化 Block
     */
    public function __init()
    {
        parent::__init();
    }
    
    /**
     * 渲染 Block
     * 支持通过属性传递参数：area, type, default-option
     * 支持通过 vars 属性传递模板变量：vars="logo|logoText|navItems"
     * 
     * @return string
     */
    public function render(): string
    {
        // 从属性中获取参数
        $area = $this->getData('area') ?? 'frontend';
        $type = $this->getData('type') ?? '';
        $defaultOption = $this->getData('default-option') ?? $this->getData('defaultOption') ?? 'default';
        
        // 如果指定了 type，则渲染对应的 partials
        if ($type) {
            // 获取传递给模板的数据
            $data = [];
            
            // 处理 vars 属性：从模板变量中提取值
            $vars = $this->getData('vars') ?? [];
            if (is_array($vars) && !empty($vars)) {
                foreach ($vars as $varName => $varValue) {
                    // vars 数组的键是变量名，值是变量的引用
                    // 我们需要从当前模板上下文中获取这些变量的值
                    // 由于 Block 继承自 Template，可以通过 $this->getData() 获取
                    // 但 vars 中的变量名可能不在 Block 的 data 中，需要从父模板获取
                    // 这里我们直接使用 $varValue，因为 framework_view_process_block 已经处理了变量引用
                    if (is_string($varName)) {
                        $data[$varName] = $varValue;
                    }
                }
            }
            
            // 获取其他直接传递的数据（除了 Block 内部使用的参数）
            foreach ($this->getData() as $key => $value) {
                // 排除 Block 内部参数
                if (!in_array($key, ['area', 'type', 'default-option', 'defaultOption', 'class', 'template', 'cache', 'vars'])) {
                    $data[$key] = $value;
                }
            }
            
            return $this->renderPartials($area, $type, $data, $defaultOption);
        }
        
        // 如果没有指定 type，返回空（保持向后兼容）
        return '';
    }

    private function fetchCachedPartialHtml(
        string $fileName,
        array $dictionary,
        string $area,
        string $type,
        string $defaultOption
    ): string {
        $policy = $this->resolveChromeCachePolicy($fileName, \is_array($dictionary['meta'] ?? null) ? (array)$dictionary['meta'] : [], $type);
        if ($policy === null) {
            return $this->renderCompiledPartial($fileName, $dictionary);
        }

        $cacheContext = $this->resolvePartialOutputCacheContext($area, $type, $defaultOption, $dictionary, $policy);
        if ($cacheContext === null) {
            return $this->fetchHtml($fileName, $dictionary);
        }

        // Key from stable source only. Never call getFetchFile before a process hit:
        // compiled paths are request-partitioned and compile/stat work must not sit
        // on the chrome hot path. Shared theme_runtime IPC is also skipped here —
        // each wls.memory get/set/incr currently burns ~200ms under pool pressure,
        // which made chrome caching slower than plain render and produced 0 hits.
        $sourceFile = $this->resolveModulePath($fileName);
        if ((!is_string($sourceFile) || $sourceFile === '' || !is_file($sourceFile)) && is_file($fileName)) {
            $sourceFile = $fileName;
        }
        $sourceStat = is_string($sourceFile) ? @stat($sourceFile) : false;
        $sourceFingerprint = is_array($sourceStat)
            ? (int)$sourceStat['mtime'] . '|' . (int)$sourceStat['size']
            : '0|0';
        $cacheKey = \sha1($fileName . '|' . $sourceFingerprint . '|' . $cacheContext);

        $cached = $this->readPartialOutputCache($cacheKey);
        if ($cached['status'] !== 'miss') {
            if ($this->isEmptyPartialHtml((string)$cached['html'])) {
                unset(self::$partialOutputCache[$cacheKey]);
                $this->logPartialCacheDiagnostic('skip_empty_partial_output_cache', [
                    'file' => $fileName,
                    'type' => $type,
                    'cache_key' => $cacheKey,
                    'status' => (string)$cached['status'],
                ]);
            } else {
                if ($cached['status'] === 'stale') {
                    $this->queuePartialOutputRefresh($cacheKey, $fileName, $dictionary, $policy['ttl']);
                }
                return (string)$cached['html'];
            }
        }

        $html = $this->renderCompiledPartial($fileName, $dictionary);
        if ($this->isEmptyPartialHtml($html)) {
            $this->logPartialCacheDiagnostic('skip_empty_partial_output_store', [
                'file' => $fileName,
                'type' => $type,
                'cache_key' => $cacheKey,
            ]);
            return $html;
        }
        $this->rememberPartialOutput($cacheKey, $html, 'fresh', $policy['ttl']);

        return $html;
    }

    /**
     * Resolve declarative chrome cache policy from @meta.cache.* on the partial.
     *
     * @param array<string, mixed> $partialsMeta
     * @return array{mode: string, auth: string, ttl: int}|null
     */
    private function resolveChromeCachePolicy(string $modulePath, array $partialsMeta, string $type = ''): ?array
    {
        $cacheKey = $modulePath;
        if (isset(self::$chromePolicyCache[$cacheKey])) {
            $cached = self::$chromePolicyCache[$cacheKey];
            return $cached['mode'] === 'chrome' ? $cached : null;
        }

        $mode = $this->readCacheMetaDefault($partialsMeta, 'mode');
        $auth = $this->readCacheMetaDefault($partialsMeta, 'auth');
        $ttlRaw = $this->readCacheMetaDefault($partialsMeta, 'ttl');

        if ($mode === null || $mode === '') {
            try {
                $filePath = $this->resolveModulePath($modulePath);
                if ((!(\is_string($filePath) && $filePath !== '' && \is_file($filePath)))
                    && \is_string($modulePath)
                    && $modulePath !== ''
                    && \is_file($modulePath)
                ) {
                    $filePath = $modulePath;
                }
                if (\is_string($filePath) && $filePath !== '' && \is_file($filePath)) {
                    $parsed = ComponentMetaParser::parse($filePath);
                    $cacheNode = \is_array($parsed['meta']['cache'] ?? null) ? (array)$parsed['meta']['cache'] : [];
                    $mode = $this->readCacheMetaDefault($cacheNode, 'mode')
                        ?? $this->readCacheMetaDefault(['cache' => $cacheNode], 'mode');
                    if ($auth === null || $auth === '') {
                        $auth = $this->readCacheMetaDefault($cacheNode, 'auth');
                    }
                    if ($ttlRaw === null || $ttlRaw === '') {
                        $ttlRaw = $this->readCacheMetaDefault($cacheNode, 'ttl');
                    }
                    // Nested shape: meta.cache.mode.default
                    if (($mode === null || $mode === '') && isset($cacheNode['mode'])) {
                        $modeNode = $cacheNode['mode'];
                        if (\is_array($modeNode)) {
                            $mode = (string)($modeNode['default'] ?? '');
                        } elseif (\is_scalar($modeNode)) {
                            $mode = (string)$modeNode;
                        }
                    }
                    if (($auth === null || $auth === '') && isset($cacheNode['auth'])) {
                        $authNode = $cacheNode['auth'];
                        if (\is_array($authNode)) {
                            $auth = (string)($authNode['default'] ?? '');
                        } elseif (\is_scalar($authNode)) {
                            $auth = (string)$authNode;
                        }
                    }
                    if (($ttlRaw === null || $ttlRaw === '') && isset($cacheNode['ttl'])) {
                        $ttlNode = $cacheNode['ttl'];
                        if (\is_array($ttlNode)) {
                            $ttlRaw = (string)($ttlNode['default'] ?? '');
                        } elseif (\is_scalar($ttlNode)) {
                            $ttlRaw = (string)$ttlNode;
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[PartialChromeCache] policy parse failed: ' . $e->getMessage(), [
                        'file' => $modulePath,
                    ]);
                }
                // Do not poison as permanent off — fall through to type fallback.
                $mode = null;
            }
        }

        $mode = \strtolower(\trim((string)$mode));
        $type = \strtolower(\trim($type));
        if ($mode !== 'chrome') {
            // Compatibility fallback for default backend shells while declarative marks propagate.
            $fallbackAuth = match ($type) {
                'topbar' => 'user',
                'head', 'loading', 'scripts', 'topnav', 'sidebar', 'right-sidebar', 'header', 'footer', 'breadcrumb' => 'role',
                default => null,
            };
            if ($fallbackAuth === null) {
                self::$chromePolicyCache[$cacheKey] = ['mode' => 'off', 'auth' => 'role', 'ttl' => (int)self::PARTIAL_OUTPUT_CACHE_TTL];
                return null;
            }
            $mode = 'chrome';
            if ($auth === null || $auth === '') {
                $auth = $fallbackAuth;
            }
        }

        $auth = \strtolower(\trim((string)$auth));
        if (!\in_array($auth, ['guest', 'role', 'user'], true)) {
            $auth = $type === 'topbar' ? 'user' : 'role';
        }
        $ttl = (int)$ttlRaw;
        if ($ttl <= 0) {
            $ttl = (int)$this->partialOutputCacheTtl();
        }
        $ttl = \max(1, \min($ttl, 86400));

        $policy = ['mode' => 'chrome', 'auth' => $auth, 'ttl' => $ttl];
        self::$chromePolicyCache[$cacheKey] = $policy;
        return $policy;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function readCacheMetaDefault(array $source, string $field): ?string
    {
        if (\array_key_exists('cache.' . $field, $source)) {
            $value = $source['cache.' . $field];
            return \is_scalar($value) ? (string)$value : null;
        }
        if (\array_key_exists('cache_' . $field, $source)) {
            $value = $source['cache_' . $field];
            return \is_scalar($value) ? (string)$value : null;
        }
        $cache = $source['cache'] ?? null;
        if (!\is_array($cache)) {
            return null;
        }
        $node = $cache[$field] ?? null;
        if (\is_array($node)) {
            $default = $node['default'] ?? null;
            return \is_scalar($default) ? (string)$default : null;
        }
        if (\is_scalar($node)) {
            return (string)$node;
        }

        return null;
    }

    /**
     * @param array{mode: string, auth: string, ttl: int} $policy
     */
    private function resolvePartialOutputCacheContext(
        string $area,
        string $type,
        string $defaultOption,
        array $data,
        array $policy
    ): ?string {
        $area = \strtolower($area);
        $type = \strtolower($type);
        if (($area !== 'frontend' && $area !== 'backend') || $this->shouldBypassPartialOutputCache()) {
            return null;
        }

        try {
            $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
            $theme = $themeData['theme'] ?? null;
            $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';
            $authContext = $area === 'backend'
                ? $this->backendAuthCacheContext($policy['auth'])
                : ($type === 'header' ? $this->frontendHeaderAuthCacheContext() : 'frontend-auth:0');

            return KeyBuilder::environmentHash([
                'schema' => 'chrome-partial-v2',
                'area' => $area,
                'type' => $type,
                'option' => $defaultOption,
                'backend_base_url' => $area === 'backend' ? (string)($this->request->getUrlBuilder()->getBackendUrl('/') ?? '') : '',
                'year' => $type === 'footer' ? \date('Y') : '',
                'theme_id' => $themeId,
                'theme_area' => (string)($themeData['area'] ?? ''),
                'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
                'website_id' => $area === 'backend'
                    ? (string)(($this->request->getData('website_id') ?? $this->request->getParam('website_id', '0')) ?: '0')
                    : '',
                'auth' => $authContext,
                'auth_mode' => $policy['auth'],
                // Invalidate chrome when backend installed/active locale catalog changes
                // (language switcher is embedded in topbar/sidebar chrome HTML).
                'locale_catalog' => $area === 'backend'
                    ? (string)(\class_exists(\Weline\I18n\Taglib\LanguageSwitcher::class)
                        ? \Weline\I18n\Taglib\LanguageSwitcher::backendLocaleCatalogFingerprint()
                        : '')
                    : '',
                'data' => $area === 'backend'
                    ? $this->normalizeChromePartialCacheData($data)
                    : $this->resolvePartialCacheDataContext($type, $data, true),
            ], [
                // Chrome identity is intentionally narrower than a request:
                // website + language + currency + explicit theme/auth data.
                // Route, host aliases and request-derived base URLs are page
                // transport concerns and must not split reusable shell output.
                'area' => false,
                'area_route' => false,
                'website_url' => false,
                'host' => false,
                'base_url' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePartialCacheDataContext(string $type, array $data, bool $isChrome = false): mixed
    {
        if ($type === 'header') {
            return $this->normalizePartialCacheData([
                'q' => (string)$this->request->getParam('q', ''),
                'category' => (string)$this->request->getParam('category', ''),
                'cart_count' => $data['cart_count'] ?? 0,
                'cart_total' => $data['cart_total'] ?? 0,
                'logo' => $data['logo'] ?? null,
                'logoText' => $data['logoText'] ?? null,
                'navItems' => $data['navItems'] ?? null,
                'showSearch' => $data['showSearch'] ?? null,
                'showUserMenu' => $data['showUserMenu'] ?? null,
            ]);
        }

        if ($type === 'footer') {
            return $this->normalizePartialCacheData([
                'footerLinks' => $data['footerLinks'] ?? null,
                'socialLinks' => $data['socialLinks'] ?? null,
                'newsletter' => $data['newsletter'] ?? null,
            ]);
        }

        if ($type === 'head' || $isChrome) {
            if ($type === 'head') {
                return $this->normalizeHeadPartialCacheData($data);
            }

            return $this->normalizeChromePartialCacheData($data);
        }

        return $this->normalizePartialCacheData($data);
    }

    private function normalizeChromePartialCacheData(array $data): mixed
    {
        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        unset(
            $meta['content'],
            $meta['contentRenderKey'],
            $meta['child_html'],
            $meta['controller'],
            $meta['request'],
            $meta['req'],
            $meta['session']
        );

        $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
        $theme = $themeData['theme'] ?? null;
        $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';

        // Keep chrome keys stable: ignore request-scoped template bags / object payloads.
        return $this->normalizePartialCacheData([
            'meta_class' => (string)($meta['class'] ?? ''),
            'theme_id' => $themeId,
            'theme_area' => (string)($themeData['area'] ?? ''),
            'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
            'colors_fp' => \is_array($data['colors'] ?? null)
                ? \sha1((string)\json_encode($this->normalizePartialCacheData($data['colors']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : '',
        ]);
    }

    private function frontendHeaderAuthCacheContext(): string
    {
        try {
            $session = SessionFactory::getInstance()->createFrontendSession();
            if (!$session->isLoggedIn()) {
                return 'frontend-auth:0';
            }

            $userId = \method_exists($session, 'getUserId') ? (string)($session->getUserId() ?? '') : '';
            $username = \method_exists($session, 'getUsername') ? (string)($session->getUsername() ?? '') : '';

            return 'frontend-auth:1:' . \sha1($userId . '|' . $username);
        } catch (\Throwable) {
            return 'frontend-auth:unknown';
        }
    }

    private function backendAuthCacheContext(string $authMode = 'user'): string
    {
        $authMode = \strtolower($authMode);
        if ($authMode === 'guest') {
            return 'backend-auth:guest';
        }

        $requestKey = 'theme.backend_partial_auth_context.' . $authMode;
        $cached = RequestContext::get($requestKey, null);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $session = SessionFactory::getInstance()->createBackendSession();
            if (!$session->isLoggedIn()) {
                $context = 'backend-auth:0';
            } elseif ($authMode === 'role') {
                $roleId = 0;
                if (\method_exists($session, 'getLoginUser')) {
                    $user = $session->getLoginUser();
                    if (\is_object($user) && \method_exists($user, 'getRoleId')) {
                        $roleId = (int)$user->getRoleId();
                    }
                }
                if ($roleId <= 0 && \method_exists($session, 'getLoginUserID')) {
                    // Super-admin / missing role: still stabilize by role bucket 0 under logged-in.
                    $roleId = 0;
                }
                $context = 'backend-auth:1:role:' . $roleId;
            } else {
                $userId = \method_exists($session, 'getLoginUserID') ? (string)($session->getLoginUserID() ?? '') : '';
                $username = \method_exists($session, 'getLoginUsername') ? (string)($session->getLoginUsername() ?? '') : '';
                $context = 'backend-auth:1:user:' . \sha1($userId . '|' . $username);
            }
        } catch (\Throwable) {
            $context = 'backend-auth:unknown';
        }

        RequestContext::set($requestKey, $context);
        return $context;
    }

    private function normalizeHeadPartialCacheData(array $data): mixed
    {
        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        unset(
            $meta['content'],
            $meta['contentRenderKey'],
            $meta['child_html'],
            $meta['controller'],
            $meta['request'],
            $meta['req'],
            $meta['session']
        );

        $layout = \is_array($data['layout'] ?? null) ? $data['layout'] : [];
        unset(
            $layout['content'],
            $layout['contentRenderKey'],
            $layout['child_html'],
            $layout['controller'],
            $layout['request'],
            $layout['req'],
            $layout['session']
        );

        return $this->normalizePartialCacheData([
            'meta' => $meta,
            'layout' => $layout,
            'theme' => $data['theme'] ?? null,
            'colors' => $data['colors'] ?? null,
            'site_name' => $data['site_name'] ?? null,
        ]);
    }

    private function shouldBypassPartialOutputCache(): bool
    {
        try {
            $requestPath = \strtolower((string)($this->request->getPathInfo() ?: \w_env_request_uri()));
            return (string)$this->request->getGet('visual_editor', '') === '1'
                || (string)$this->request->getGet('preview', '') === '1'
                || (string)$this->request->getGet('debug_hooks', '') === '1'
                || \str_contains($requestPath, 'workspace-preview');
        } catch (\Throwable) {
            return true;
        }
    }

    private function normalizePartialCacheData(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '*depth*';
        }
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string)$key] = $this->normalizePartialCacheData($item, $depth + 1);
            }
            \ksort($normalized);
            return $normalized;
        }
        if (\is_object($value)) {
            $id = \method_exists($value, 'getId') ? (string)$value->getId() : '';
            return ['class' => $value::class, 'id' => $id];
        }

        return (string)\gettype($value);
    }

    /**
     * 获取 partials 模板路径
     *
     * 支持主题继承链查找：
     * 1. 当前主题的 partials
     * 2. 父主题的 partials
     * 3. Weline_Theme 默认 partials
     *
     * 路径结构优先级：
     * - {themePath}/{area}/partials/{type}/{option}.phtml (现代结构)
     * - {themePath}/theme/{area}/partials/{type}/{option}.phtml (兼容结构)
     * - {themePath}/view/partials/{area}/{type}/{option}.phtml (旧结构)
     *
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param string $defaultOption 默认选项（如果配置中没有指定）
     * @return string|null 模板路径（模块格式或绝对路径）
     */
    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('theme_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readPartialOutputCache(string $cacheKey): array
    {
        $entry = $this->normalizePartialSwrEntry(self::$partialOutputCache[$cacheKey] ?? null);
        if ($entry === null) {
            unset(self::$partialOutputCache[$cacheKey]);
            return ['status' => 'miss', 'html' => null];
        }
        // LRU: move to end on access
        unset(self::$partialOutputCache[$cacheKey]);
        self::$partialOutputCache[$cacheKey] = $entry;

        return $this->partialSwrEntryStatus($entry);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readRuntimePartialOutputCache(string $key): array
    {
        $entry = $this->normalizePartialSwrEntry($this->runtimeCacheGet($key));
        if ($entry === null) {
            return ['status' => 'miss', 'html' => null];
        }

        return $this->partialSwrEntryStatus($entry);
    }

    private function rememberPartialOutput(string $cacheKey, string $html, string $status = 'fresh', ?int $ttl = null): void
    {
        if (isset(self::$partialOutputCache[$cacheKey])) {
            unset(self::$partialOutputCache[$cacheKey]);
        } elseif (\count(self::$partialOutputCache) >= self::PARTIAL_OUTPUT_CACHE_MAX) {
            $oldestKey = \array_key_first(self::$partialOutputCache);
            if (\is_string($oldestKey)) {
                unset(self::$partialOutputCache[$oldestKey]);
            }
        }

        self::$partialOutputCache[$cacheKey] = $this->makePartialSwrEntry($html, $status, $ttl);
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function queuePartialOutputRefresh(string $cacheKey, string $fileName, array $dictionary, ?int $ttl = null): void
    {
        if (!$this->acquireLocalPartialRefreshLock($cacheKey)) {
            return;
        }

        $ttl ??= $this->partialOutputCacheTtl();
        PostResponseTaskQueue::enqueue('theme-partial-output:' . $cacheKey, function () use ($cacheKey, $fileName, $dictionary, $ttl): void {
            $html = $this->renderCompiledPartial($fileName, $dictionary);
            if (!\is_string($html) || $this->isEmptyPartialHtml($html)) {
                $this->logPartialCacheDiagnostic('skip_empty_partial_output_refresh', [
                    'cache_key' => $cacheKey,
                    'file' => $fileName,
                ]);
                return;
            }
            $this->rememberPartialOutput($cacheKey, $html, 'fresh', $ttl);
        });
    }

    private function acquireLocalPartialRefreshLock(string $cacheKey): bool
    {
        static $localLocks = [];
        $lockKey = 'partial.output.' . $cacheKey . '.refresh_lock';
        $now = \microtime(true);
        $expiresAt = (float)($localLocks[$lockKey] ?? 0);
        if ($expiresAt >= $now) {
            return false;
        }
        $localLocks[$lockKey] = $now + $this->partialRefreshLockTtl();

        return true;
    }

    /**
     * @return array{fresh_until: float, stale_until: float, html: string}|null
     */
    private function normalizePartialSwrEntry(mixed $entry): ?array
    {
        $ttl = $this->partialOutputCacheTtl();
        $now = \microtime(true);
        if (\is_string($entry)) {
            return [
                'fresh_until' => $now + $ttl,
                'stale_until' => $now + $ttl + $this->partialOutputStaleTtl(),
                'html' => $entry,
            ];
        }
        if (!\is_array($entry)) {
            return null;
        }
        $html = $entry['html'] ?? $entry['value'] ?? null;
        if (!\is_string($html)) {
            return null;
        }
        $freshUntil = (float)($entry['fresh_until'] ?? $entry['expires_at'] ?? 0);
        if ($freshUntil <= 0) {
            $freshUntil = $now + $ttl;
        }
        $staleUntil = (float)($entry['stale_until'] ?? ($freshUntil + $this->partialOutputStaleTtl()));
        if ($staleUntil < $freshUntil) {
            $staleUntil = $freshUntil;
        }

        return [
            'fresh_until' => $freshUntil,
            'stale_until' => $staleUntil,
            'html' => $html,
        ];
    }

    /**
     * @param array{fresh_until: float, stale_until: float, html: string} $entry
     * @return array{status: string, html: ?string}
     */
    private function partialSwrEntryStatus(array $entry): array
    {
        $html = (string)($entry['html'] ?? '');
        $now = \microtime(true);
        if ((float)$entry['fresh_until'] >= $now) {
            return ['status' => 'fresh', 'html' => $html];
        }
        if ((float)$entry['stale_until'] >= $now) {
            return ['status' => 'stale', 'html' => $html];
        }

        return ['status' => 'miss', 'html' => null];
    }

    /**
     * @return array{fresh_until: float, stale_until: float, html: string}
     */
    private function makePartialSwrEntry(string $html, string $status = 'fresh', ?int $ttl = null): array
    {
        $ttl = $ttl !== null && $ttl > 0 ? $ttl : $this->partialOutputCacheTtl();
        $now = \microtime(true);
        $freshUntil = $status === 'stale' ? $now - 0.001 : $now + $ttl;

        return [
            'fresh_until' => $freshUntil,
            'stale_until' => $freshUntil + $this->partialOutputStaleTtl(),
            'html' => $html,
        ];
    }

    private function isEmptyPartialHtml(string $html): bool
    {
        return \trim($html) === '';
    }

    private function logPartialCacheDiagnostic(string $event, array $context = []): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }

        try {
            $context += [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'uri' => \function_exists('w_env_request_uri') ? (string)\w_env_request_uri() : '',
                'lang' => (string)State::getLang(),
                'lang_local' => (string)State::getLangLocal(),
                'currency' => (string)State::getCurrency(),
            ];
        } catch (\Throwable) {
        }

        $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        \w_log_warning('[PartialCacheDiagnostics] ' . $event . ' ' . ($payload ?: '{}'));
    }

    private function partialOutputStaleTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_stale_ttl', self::PARTIAL_OUTPUT_STALE_TTL, 1, 86400);
    }

    private function partialRefreshLockTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_refresh_lock_ttl', self::PARTIAL_OUTPUT_REFRESH_LOCK_TTL, 1, 300);
    }

    private function acquirePartialRefreshLock(string $cacheKey): bool
    {
        $cache = self::runtimeCache();
        $lockKey = 'partial.output.' . $cacheKey . '.refresh_lock';
        if ($cache !== null) {
            try {
                return $cache->incr('theme_runtime', $lockKey, 1, $this->partialRefreshLockTtl()) === 1;
            } catch (\Throwable) {
                self::$runtimeCache = null;
                self::$runtimeCacheResolved = true;
            }
        }

        static $localLocks = [];
        $now = \microtime(true);
        $expiresAt = (float)($localLocks[$lockKey] ?? 0);
        if ($expiresAt >= $now) {
            return false;
        }
        $localLocks[$lockKey] = $now + $this->partialRefreshLockTtl();

        return true;
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $ttl = \max(1, $ttl);
            $now = \microtime(true);
            $staleTtl = $this->partialOutputStaleTtl();
            $cache->set('theme_runtime', $key, [
                'fresh_until' => $now + $ttl,
                'stale_until' => $now + $ttl + $staleTtl,
                'value' => $value,
            ], $ttl + $staleTtl);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private function runtimeCacheDelete(string $key): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->delete('theme_runtime', $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?SharedCacheStateInterface
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $cache = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(SharedCacheStateInterface::class);
            self::$runtimeCache = $cache instanceof SharedCacheStateInterface ? $cache : null;
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function partialOutputCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.partial_output_ttl', (int)self::PARTIAL_OUTPUT_CACHE_TTL);
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    public function getPartialsPath(string $area, string $type, string $defaultOption = 'default'): ?string
    {
        /** @var ThemeContextService $ctx */
        $ctx = ObjectManager::getInstance(ThemeContextService::class);
        $normalizedArea = $ctx->normalizeArea($area);
        $theme = $ctx->resolveTheme($normalizedArea);

        // 如果没有活动主题，直接跳到默认主题回退逻辑
        if (!$theme || !$theme->getId()) {
            $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $defaultOption . '.phtml';
            $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
            if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
                return $defaultPartialsPath;
            }
            return null;
        }

        $scope = $ctx->resolveCurrentScope($normalizedArea);

        // 获取配置的选项（优先 ThemeData 元配置，回退主题 config）
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($normalizedArea);
        $config = ThemeData::getPartialsConfig($normalizedArea, $scope);
        if (empty($config)) {
            $themeConfig = (array)$theme->getConfig();
            $partialsByArea = (array)($themeConfig['partials'] ?? []);
            $config = (array)($partialsByArea[$normalizedArea] ?? []);
        }
        $option = $config[$type] ?? $defaultOption;

        // 使用 ThemeDirectoryResolver 解析主题 partials 路径（支持继承链）
        /** @var ThemeDirectoryResolver $dirResolver */
        $dirResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        $partialPath = 'theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $resolvedPath = $dirResolver->resolveThemeTemplatePath($partialPath, $theme);

        // resolveThemeTemplatePath 返回：
        // - 找到文件时返回绝对路径
        // - 未找到时返回原始路径
        if ($resolvedPath !== $partialPath) {
            // 如果找到了文件（返回绝对路径），转换为 Weline_Theme 模块路径
            // 绝对路径判断：Windows drive/UNC 或 Unix (/ 开头的绝对路径)
            $isAbsolutePath = strpos($resolvedPath, '://') === false
                && (preg_match('/^[A-Z]:/i', $resolvedPath)
                    || strpos($resolvedPath, '/') === 0
                    || strpos($resolvedPath, '\\') === 0);
            if ($isAbsolutePath) {
                return 'Weline_Theme::' . $partialPath;
            }
            // 否则直接返回
            return $resolvedPath;
        }

        // 未找到文件，尝试回退

        // 最终回退：尝试 Weline_Theme 默认 partials
        $defaultPartialsPath = 'Weline_Theme::theme/' . $normalizedArea . '/partials/' . $type . '/' . $option . '.phtml';
        $defaultAbsolutePath = $this->resolveModulePath($defaultPartialsPath);
        if ($defaultAbsolutePath && is_file($defaultAbsolutePath)) {
            return $defaultPartialsPath;
        }

        return null;
    }
    
    /**
     * 解析模块路径为绝对路径（用于检查文件是否存在）
     * @param string $modulePath 模块路径格式（如 Weline_Theme::theme/frontend/partials/header/default.phtml）
     * @return string|null 绝对路径，如果无法解析则返回null
     */
    private function resolveModulePath(string $modulePath): ?string
    {
        if (strpos($modulePath, '::') === false) {
            return null;
        }
        
        list($moduleName, $relativePath) = explode('::', $modulePath, 2);
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName])) {
            return null;
        }
        
        $module = $modules[$moduleName];
        $basePath = rtrim($module['base_path'], DS);
        $relativePath = str_replace('/', DS, $relativePath);
        
        return $basePath . DS . 'view' . DS . $relativePath;
    }
    
    /**
     * 渲染 partials
     * @param string $area 区域（frontend 或 backend）
     * @param string $type partials 类型（header, footer, sidebar 等）
     * @param array $data 传递给模板的数据
     * @param string $defaultOption 默认选项
     * @return string
     */
    public function renderPartials(string $area, string $type, array $data = [], string $defaultOption = 'default'): string
    {
        $tracePrefix = 'theme::partials::' . $type;

        return $this->traceCall($tracePrefix . '::render', function () use ($area, $type, $data, $defaultOption, $tracePrefix) {
            $path = $this->traceCall(
                $tracePrefix . '::resolve_path',
                fn() => $this->getPartialsPath($area, $type, $defaultOption)
            );

            if (!$path) {
                return '';
            }

            $template = Template::getInstance();
            $layoutMeta = $template->getData('meta') ?? [];
            $themeData = $template->getData('theme') ?? [];
            $colorsData = $template->getData('colors') ?? [];
            $contentTemplate = $template->getData('contentTemplate') ?? null;

            $scope = $this->resolveScope($area);
            $metaIdentify = "partials.{$type}";
            if ($defaultOption && $defaultOption !== 'default') {
                $metaIdentify .= ".{$defaultOption}";
            } else {
                $metaIdentify = "partials.{$type}.default";
            }

            $cacheKey = $area . '|' . $type . '|' . $defaultOption . '|' . $scope . '|' . $path;
            if (array_key_exists($cacheKey, self::$partialsMetaCache)) {
                $partialsMeta = self::$partialsMetaCache[$cacheKey];
            } else {
                $partialsMeta = $this->traceCall(
                    $tracePrefix . '::load_meta',
                    fn() => ThemeData::getFileParams($metaIdentify, $scope)
                );

                if (empty($partialsMeta)) {
                    $partialsMeta = $this->traceCall(
                        $tracePrefix . '::parse_meta_file',
                        fn() => $this->parsePartialsMetaFromFile($path, $area, $type, $defaultOption)
                    );
                }
                self::$partialsMetaCache[$cacheKey] = is_array($partialsMeta) ? $partialsMeta : [];
            }

            if (empty($partialsMeta)) {
                $partialsMeta = [];
            }

            $data['meta'] = $partialsMeta;
            $data['layout'] = $layoutMeta;
            $data['theme'] = $themeData;
            $data['colors'] = $colorsData;
            if ($contentTemplate) {
                $data['contentTemplate'] = $contentTemplate;
            }

            foreach ($data as $key => $value) {
                $this->assign($key, $value);
            }

            return $this->traceCall(
                $tracePrefix . '::fetch_html',
                fn() => $this->fetchCachedPartialHtml($path, $data, $area, $type, $defaultOption)
            );
        });
    }

    /**
     * Warm backend chrome partials marked @meta.cache.mode=chrome into process-local LRU.
     * Guest/unauthenticated auth buckets only; logged-in role/user entries fill on first request.
     * Time-boxed so worker bootstrap cannot stall READY/deferred loops.
     * Intentionally does not write theme_runtime — shared-memory IPC is too expensive on the chrome path.
     */
    public function warmChromePartialOutputs(float $budgetSeconds = 2.5): int
    {
        $targets = [
            ['head', 'default'],
            ['loading', 'default'],
            ['scripts', 'default'],
            ['topnav', 'default'],
            ['sidebar', 'left'],
            ['sidebar', 'default'],
            ['right-sidebar', 'default'],
            ['topbar', 'default'],
        ];

        $budgetSeconds = \max(0.2, $budgetSeconds);
        $deadline = \microtime(true) + $budgetSeconds;
        $warmed = 0;
        foreach ($targets as [$type, $option]) {
            if (\microtime(true) >= $deadline) {
                break;
            }
            try {
                $modulePath = 'Weline_Theme::theme/backend/partials/' . $type . '/' . $option . '.phtml';
                $absolute = $this->resolveModulePath($modulePath);
                if (!\is_string($absolute) || $absolute === '' || !\is_file($absolute)) {
                    continue;
                }
                $policy = $this->resolveChromeCachePolicy($modulePath, [], $type);
                if ($policy === null) {
                    continue;
                }
                $html = $this->renderPartials('backend', $type, [], $option);
                if (\is_string($html) && !$this->isEmptyPartialHtml($html)) {
                    $warmed++;
                }
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning('[PartialChromeCache] warmup failed: ' . $e->getMessage(), [
                        'type' => $type,
                        'option' => $option,
                    ]);
                }
            }
            SchedulerSystem::yield();
        }

        return $warmed;
    }
    /**
     * 重写 fetchHtml 方法，直接使用 Template 的 getFetchFile，避免使用 blocks 类型
     * @param string $fileName 文件名
     * @param array $dictionary 数据字典
     * @return string
     */
    public function fetchHtml(string $fileName, array $dictionary = []): string
    {
        return $this->renderCompiledPartial($fileName, $dictionary);
    }

    /**
     * Compile the partial immediately before rendering it.
     *
     * Partial output caches may outlive view/tpl files (for example after a
     * deploy or a manual cache cleanup). Never pass a cached compiled path to
     * ob_file() unless it still exists. Re-resolving here also closes the
     * small race where another worker removes the compiled file between the
     * first lookup and include().
     *
     * @param array<string, mixed> $dictionary
     */
    private function renderCompiledPartial(
        string $fileName,
        array $dictionary = [],
        ?string $compiledFile = null
    ): string {
        $compiledFile ??= $this->getFetchFile($fileName);
        if (!\is_file($compiledFile)) {
            $compiledFile = $this->getFetchFile($fileName);
        }
        if (!\is_file($compiledFile)) {
            throw new \RuntimeException(
                (string)__('模板编译文件生成失败：%{1}', $fileName)
            );
        }

        return $this->ob_file($compiledFile, $dictionary);
    }
    
    /**
     * 解析 scope（优先从预览模式获取，其次从请求参数获取，最后使用 default）
     * 
     * @param string $area 区域（frontend 或 backend）
     * @return string scope 值
     */
    private function resolveScope(string $area): string
    {
        $ctx = ObjectManager::getInstance(ThemeContextService::class);

        return $ctx->resolveCurrentScope($ctx->normalizeArea($area));
    }
    
    /**
     * 从文件解析 partials 的 meta 数据
     * 
     * @param string $modulePath 模块路径（如 Weline_Theme::theme/frontend/partials/head/default.phtml）
     * @param string $area 区域
     * @param string $type partials 类型
     * @param string $option partials 选项
     * @return array 解析后的参数值数组
     */
    private function parsePartialsMetaFromFile(string $modulePath, string $area, string $type, string $option): array
    {
        // 解析模块路径为文件系统路径
        $filePath = $this->resolveModulePath($modulePath);
        if (!$filePath || !is_file($filePath)) {
            return [];
        }
        
        // 使用 ComponentMetaParser 从文件解析参数定义
        $parsedMeta = ComponentMetaParser::parse($filePath);
        if (empty($parsedMeta['params']) || !is_array($parsedMeta['params'])) {
            return [];
        }
        
        // 格式化参数定义
        $formattedParams = LayoutPathResolver::formatParsedParams($parsedMeta['params']);
        
        // 提取默认值作为参数值
        $params = [];
        foreach ($formattedParams as $paramName => $paramDef) {
            $defaultValue = $paramDef['default'] ?? null;
            // 处理布尔值默认值
            if ($defaultValue === 'true' || $defaultValue === true) {
                $defaultValue = true;
            } elseif ($defaultValue === 'false' || $defaultValue === false) {
                $defaultValue = false;
            }
            // 处理空字符串默认值
            if ($defaultValue === '') {
                $defaultValue = '';
            }
            $params[$paramName] = $defaultValue;
        }
        
        return $params;
    }
    private function traceCall(string $name, callable $callback, string $category = 'theme'): mixed
    {
        if (!RequestLifecycleTrace::isEnabled()) {
            self::cooperativeRenderYield();
            try {
                return $callback();
            } finally {
                self::cooperativeRenderYield();
            }
        }

        self::cooperativeRenderYield();
        $start = microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (microtime(true) - $start) * 1000, $category);
            self::cooperativeRenderYield();
        }
    }

    private static function cooperativeRenderYield(): void
    {
        if (!Runtime::isPersistent() || !SchedulerSystem::isSchedulerActive()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }

        $now = \microtime(true);
        self::$fiberRenderYieldAt ??= new \WeakMap();
        $lastYieldAt = (float)(self::$fiberRenderYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            self::$fiberRenderYieldAt[$fiber] = $now;
            return;
        }
        if ($lastYieldAt > 0.0 && (($now - $lastYieldAt) * 1000000) < self::WLS_RENDER_YIELD_MIN_INTERVAL_US) {
            return;
        }

        self::$fiberRenderYieldAt[$fiber] = $now;
        SchedulerSystem::yield();
    }
}
