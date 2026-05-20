<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Block;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Block;
use Weline\Framework\View\Template;
use Weline\Server\Service\MemoryStateFacade;
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
    private const PARTIAL_OUTPUT_CACHE_TTL = 300.0;
    /** @var array<string, true> */
    private const CACHEABLE_PARTIAL_TYPES = [
        'head' => true,
        'header' => true,
        'footer' => true,
        'breadcrumb' => true,
    ];
    private const PARTIAL_OUTPUT_STALE_TTL = 600;
    private const PARTIAL_OUTPUT_REFRESH_LOCK_TTL = 10;
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $partialOutputCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public static function clearMetaCache(): void
    {
        self::$partialsMetaCache = [];
        self::$partialOutputCache = [];
        self::$runtimeCache = null;
        self::$runtimeCacheResolved = false;
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
        if (!isset(self::CACHEABLE_PARTIAL_TYPES[$type])) {
            return $this->fetchHtml($fileName, $dictionary);
        }

        $cacheContext = $this->resolvePartialOutputCacheContext($area, $type, $defaultOption, $dictionary);
        if ($cacheContext === null) {
            return $this->fetchHtml($fileName, $dictionary);
        }

        $comFileName = $this->getFetchFile($fileName);
        $stat = @stat($comFileName);
        if (!\is_array($stat)) {
            return $this->ob_file($comFileName, $dictionary);
        }

        $cacheKey = \sha1($fileName . '|' . $comFileName . '|' . (int)$stat['mtime'] . '|' . (int)$stat['size'] . '|' . $cacheContext);
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
                $this->queuePartialOutputRefresh($cacheKey, $comFileName, $dictionary);
            }
            return (string)$cached['html'];
            }
        }
        $runtimeCached = $this->readRuntimePartialOutputCache('partial.output.' . $cacheKey);
        if ($runtimeCached['status'] !== 'miss') {
            if ($this->isEmptyPartialHtml((string)$runtimeCached['html'])) {
                $this->runtimeCacheDelete('partial.output.' . $cacheKey);
                $this->logPartialCacheDiagnostic('skip_empty_runtime_partial_output_cache', [
                    'file' => $fileName,
                    'type' => $type,
                    'cache_key' => $cacheKey,
                    'status' => (string)$runtimeCached['status'],
                ]);
            } else {
            $this->rememberPartialOutput($cacheKey, (string)$runtimeCached['html'], (string)$runtimeCached['status']);
            if ($runtimeCached['status'] === 'stale') {
                $this->queuePartialOutputRefresh($cacheKey, $comFileName, $dictionary);
            }
            return (string)$runtimeCached['html'];
            }
        }

        $html = $this->ob_file($comFileName, $dictionary);
        if ($this->isEmptyPartialHtml($html)) {
            $this->logPartialCacheDiagnostic('skip_empty_partial_output_store', [
                'file' => $fileName,
                'type' => $type,
                'cache_key' => $cacheKey,
            ]);
            return $html;
        }
        $this->rememberPartialOutput($cacheKey, $html);
        $this->runtimeCacheSet('partial.output.' . $cacheKey, $html, $this->partialOutputCacheTtl());

        return $html;
    }

    private function resolvePartialOutputCacheContext(string $area, string $type, string $defaultOption, array $data): ?string
    {
        if ($area !== 'frontend' || $this->shouldBypassPartialOutputCache()) {
            return null;
        }

        try {
            $requestUri = (string)\w_env_request_uri();
            $pathContext = $type === 'header' || $type === 'footer' ? '' : $requestUri;
            $themeData = \is_array($data['theme'] ?? null) ? (array)$data['theme'] : [];
            $theme = $themeData['theme'] ?? null;
            $themeId = \is_object($theme) && \method_exists($theme, 'getId') ? (string)$theme->getId() : '';

            return \sha1(\json_encode([
                'area' => $area,
                'type' => $type,
                'option' => $defaultOption,
                'base_url' => (string)$this->request->getBaseUrl(),
                'uri' => $pathContext,
                'lang' => (string)State::getLang(),
                'lang_local' => (string)State::getLangLocal(),
                'currency' => (string)State::getCurrency(),
                'year' => $type === 'footer' ? \date('Y') : '',
                'theme_id' => $themeId,
                'theme_area' => (string)($themeData['area'] ?? ''),
                'theme_color_mode' => (string)($themeData['colorMode'] ?? ''),
                'layout_type' => (string)($themeData['layoutType'] ?? ''),
                'layout_option' => (string)($themeData['layoutOption'] ?? ''),
                'auth' => $type === 'header' ? $this->frontendHeaderAuthCacheContext() : '',
                'data' => $this->resolvePartialCacheDataContext($type, $data),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $type);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePartialCacheDataContext(string $type, array $data): mixed
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

        if ($type === 'head') {
            return $this->normalizeHeadPartialCacheData($data);
        }

        return $this->normalizePartialCacheData($data);
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

    private function rememberPartialOutput(string $cacheKey, string $html, string $status = 'fresh'): void
    {
        if (\count(self::$partialOutputCache) > 96) {
            self::$partialOutputCache = [];
        }

        self::$partialOutputCache[$cacheKey] = $this->makePartialSwrEntry($html, $status);
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function queuePartialOutputRefresh(string $cacheKey, string $comFileName, array $dictionary): void
    {
        if (!$this->acquirePartialRefreshLock($cacheKey)) {
            return;
        }

        PostResponseTaskQueue::enqueue('theme-partial-output:' . $cacheKey, function () use ($cacheKey, $comFileName, $dictionary): void {
            $html = $this->ob_file($comFileName, $dictionary);
            if (!\is_string($html)) {
                return;
            }
            if ($this->isEmptyPartialHtml($html)) {
                $this->logPartialCacheDiagnostic('skip_empty_partial_output_refresh', [
                    'cache_key' => $cacheKey,
                    'compiled_file' => $comFileName,
                ]);
                return;
            }
            $this->rememberPartialOutput($cacheKey, $html);
            $this->runtimeCacheSet('partial.output.' . $cacheKey, $html, $this->partialOutputCacheTtl());
        });
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
    private function makePartialSwrEntry(string $html, string $status = 'fresh'): array
    {
        $ttl = $this->partialOutputCacheTtl();
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

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent() || !\class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            self::$runtimeCache = new MemoryStateFacade(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'theme_runtime_partial',
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
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
            // 绝对路径判断：Windows (E:\) 或 Unix (/ 开头的绝对路径)
            $isAbsolutePath = strpos($resolvedPath, '://') === false
                && (preg_match('/^[A-Z]:/i', $resolvedPath) || strpos($resolvedPath, '/') === 0);
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
     * 重写 fetchHtml 方法，直接使用 Template 的 getFetchFile，避免使用 blocks 类型
     * @param string $fileName 文件名
     * @param array $dictionary 数据字典
     * @return string
     */
    public function fetchHtml(string $fileName, array $dictionary = []): string
    {
        // 直接使用 Template 的 getFetchFile 方法，而不是 Block 的 fetchTagSource('blocks', ...)
        $comFileName = $this->getFetchFile($fileName);
        return $this->ob_file($comFileName, $dictionary);
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
            return $callback();
        }

        $start = microtime(true);
        RequestLifecycleTrace::pushCurrentParent($name);
        try {
            return $callback();
        } finally {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan($name, (microtime(true) - $start) * 1000, $category);
        }
    }
}
