<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\Framework\Context;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Exception\Core;
use Weline\Framework\Hook\Hooker;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\TraceGate;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Runtime\WlsRuntimeAdapterResolver;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Ui\FormKey;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Cache\TemplateCachePolicyRegistry;
use Weline\Framework\View\Engine\EngineFlags;
use Weline\Framework\View\Engine\HookRuntime;
use Weline\Framework\View\Engine\ViewEngine;
use Weline\Framework\View\Helper\EmbeddedPageTitle;
use Weline\Framework\View\Helper\HtmlCacheAdmission;
use Weline\Framework\Runtime\RequestLocalInterface;

class Template extends DataObject implements RequestLocalInterface
{
    use TraitTemplate;

    private string $file_ext = '.phtml';
    private array $pinnedSources = [];
    private array $pinnedSourceDigests = [];
    private int $pinnedSourceGeneration = 0;
    private ?string $pinnedSourceSetToken = null;
    private int $pinnedSourceSetGeneration = -1;
    private array $compiledSourceOrigins = [];
    private string $activeSourceOrigin = '';

    protected Request $request;
    private ?Taglib $taglib = null;

    /**
     * @var PcController
     */
    private PcController $controller;

    /**
     * @var string 指定模板目录
     */
    private string $template_dir = '';

    /**
     * @var string 编译后的目录
     */
    private string $compile_dir = '';

    /**
     * @var string 静态文件目录
     */
    private string $statics_dir = '';

    /**
     * @var string 静态文件目录
     */
    private string $view_dir = '';

    private array $theme;

    private EventsManager $eventsManager;

    /**
     * @var CacheInterface 缓存
     */
    private CachePoolInterface $viewCache;

    private static ?Template $instance = null;
    private static ?\WeakMap $fiberInstances = null;
    private static ?\WeakMap $fiberRenderYieldAt = null;

    private const WLS_TEMPLATE_YIELD_MIN_INTERVAL_US = 10000;
    private const WLS_JSON_YIELD_BYTES = 262144;
    private const JSON_SCRIPT_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR;
    /** @var array<string, Template> */
    private static array $scopedInstances = [];
    /** @var array<string, array{compiled_mtime: int, compiled_size: int, template_mtime: int, template_size: int, force: string, result: bool}> */
    private static array $compiledTemplateFreshnessCache = [];
    /**
     * Per-compiled-path proof that `compileSourceBytes()` already accepted this exact
     * (source, hooks registry, before_compile pipeline) combination.
     *
     * Lets the cold fetch path skip the source `open()` plus the content digest —
     * ~58 µs → ~9 µs for a 10 KB template (measured, `dev/tmp/bench-pa2e.php`).
     * Validated on every hit by source stat, compiled-file existence, hooks digest
     * and pipeline digest, so any real change falls through to the full content-hash
     * identity check.
     *
     * The compiled file itself needs no mtime/size check: `compile_id` is a
     * deterministic function of exactly the inputs validated here, so any artifact
     * at this path holds the same bytes. Only deletion (cache:clear) must be noticed.
     *
     * `ctime`/`ino` are folded in on top of `mtime`/`size`: tools that restore
     * mtime (rsync --times, tar, git checkout) still bump ctime, and replace-in-place
     * writers change inode. All four come from the same stat cache entry.
     *
     * @var array<string, array{sm:int, ss:int, sc:int, si:int, hd:string, pd:string}>
     */
    private static array $fetchFileIdentityMemo = [];
    private const FETCH_FILE_IDENTITY_MEMO_MAX = 4096;
    private const PROCESS_VIEW_FILE_CACHE_TTL = 3600.0;
    private const PROCESS_VIEW_FILE_CACHE_MAX = 2048;
    /** @var array<string, array{compiled: string, tpl: string, expires_at: float}> */
    private static array $processViewFileCache = [];
    private const STATIC_HOOK_OUTPUT_CACHE_TTL = 60.0;
    private const STATIC_HOOK_AGGREGATE_CACHE_TTL = 30.0;
    private const STATIC_HOOK_STALE_TTL = 300;
    private const STATIC_HOOK_REFRESH_LOCK_TTL = 10;
    private const STATIC_HOOK_SHARED_WRITE_IDLE_GRACE_SECONDS = 2.0;
    private const REUSABLE_TEMPLATE_OUTPUT_CACHE_TTL = 120;
    private const REUSABLE_TEMPLATE_OUTPUT_CACHE_MAX_ITEMS = 32;
    public const DATA_FORCE_MODULE_THEME_SOURCE = '__weline_force_module_theme_source';
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $staticHookOutputCache = [];
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $staticHookAggregateOutputCache = [];
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private static array $reusableTemplateOutputCache = [];
    private static ?SharedCacheStateInterface $staticHookRuntimeCache = null;
    private static bool $staticHookRuntimeCacheResolved = false;
    private static ?TemplateCachePolicyRegistry $templateCachePolicyRegistry = null;

    private function __clone()
    {
    }

    public static function getInstance(): Template
    {
        $scopeKey = self::currentScopeKey();
        if ($scopeKey !== null) {
            if (!isset(self::$scopedInstances[$scopeKey])) {
                $instance = new self();
                $instance->init();
                self::$scopedInstances[$scopeKey] = $instance;
                if (\str_starts_with($scopeKey, 'req:')) {
                    // 只清理本请求创建的实例，避免新 Fiber 入场时清掉仍在渲染的同伴。
                    RequestContext::onCleanup(static function () use ($scopeKey): void {
                        unset(self::$scopedInstances[$scopeKey]);
                    }, 'framework.template.' . $scopeKey);
                }
            }

            return self::$scopedInstances[$scopeKey];
        }

        $fiber = self::currentFiber();
        if ($fiber !== null) {
            self::$fiberInstances ??= new \WeakMap();
            if (!isset(self::$fiberInstances[$fiber])) {
                $instance = new self();
                $instance->init();
                self::$fiberInstances[$fiber] = $instance;
            }

            return self::$fiberInstances[$fiber];
        }

        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    /**
     * WLS 状态重置：销毁单例实例，强制下次 getInstance() 重新创建
     * 
     * WLS 常驻内存模式下，Template 单例的 _data 数组会残留上个请求的
     * title、req、env、local、view_dir 等请求级数据，导致页面标题、
     * 请求参数、模板目录等状态泄漏到下一个请求。
     */
    public static function resetInstance(): void
    {
        $scopeKey = self::currentScopeKey();
        if ($scopeKey !== null) {
            unset(self::$scopedInstances[$scopeKey]);
            return;
        }

        $fiber = self::currentFiber();
        if ($fiber !== null) {
            if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
                unset(self::$fiberInstances[$fiber]);
            }
            return;
        }

        self::$instance = null;
        self::$fiberInstances = null;
        self::$scopedInstances = [];
    }

    /**
     * Drop Fiber-owned Template singleton when the Worker still pins a terminated Fiber.
     * WeakMap alone cannot release while that Fiber reference lives.
     */
    public static function clearScopeForFiber(\Fiber $fiber): void
    {
        if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
            unset(self::$fiberInstances[$fiber]);
        }
        if (self::$fiberRenderYieldAt !== null && isset(self::$fiberRenderYieldAt[$fiber])) {
            unset(self::$fiberRenderYieldAt[$fiber]);
        }
    }

    public static function clearStaticHookCaches(): void
    {
        $runtimeCache = self::runtimeHookCache();
        if ($runtimeCache !== null) {
            try {
                $runtimeCache->clearNamespace('theme_runtime');
            } catch (\Throwable) {
                // Cache invalidation is best-effort; local state must still be reset.
            }

            try {
                $runtimeCache->disconnect();
            } catch (\Throwable) {
                // Do not keep a failed shared-cache connection attached to the worker.
            }
        }

        self::$staticHookOutputCache = [];
        self::$staticHookAggregateOutputCache = [];
        self::$reusableTemplateOutputCache = [];
        self::clearProcessViewFileCache();
        self::$staticHookRuntimeCache = null;
        self::$staticHookRuntimeCacheResolved = false;
        self::$templateCachePolicyRegistry = null;
    }

    public static function clearProcessViewFileCache(): void
    {
        self::$processViewFileCache = [];
    }

    public static function processViewFileCacheItemCount(): int
    {
        return count(self::$processViewFileCache);
    }

    public static function processStaticHookOutputCacheItemCount(): int
    {
        return \count(self::$staticHookOutputCache);
    }

    /**
     * @return array{compiled: string, tpl: string}|null
     */
    private static function readProcessViewFileCache(string $key): ?array
    {
        $entry = self::$processViewFileCache[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        if ((float)($entry['expires_at'] ?? 0.0) <= microtime(true)
            || !is_file((string)($entry['compiled'] ?? ''))
            || !is_file((string)($entry['tpl'] ?? ''))
        ) {
            unset(self::$processViewFileCache[$key]);
            return null;
        }

        // Touch the entry so frequently used templates stay in the bounded map.
        unset(self::$processViewFileCache[$key]);
        self::$processViewFileCache[$key] = $entry;

        return [
            'compiled' => (string)$entry['compiled'],
            'tpl' => (string)$entry['tpl'],
        ];
    }

    private static function rememberProcessViewFileCache(string $key, string $compiled, string $tpl): void
    {
        if ($compiled === '' || $tpl === '' || !is_file($compiled) || !is_file($tpl)) {
            return;
        }

        unset(self::$processViewFileCache[$key]);
        self::$processViewFileCache[$key] = [
            'compiled' => $compiled,
            'tpl' => $tpl,
            'expires_at' => microtime(true) + self::PROCESS_VIEW_FILE_CACHE_TTL,
        ];

        while (count(self::$processViewFileCache) > self::PROCESS_VIEW_FILE_CACHE_MAX) {
            $oldestKey = array_key_first(self::$processViewFileCache);
            if ($oldestKey === null) {
                break;
            }
            unset(self::$processViewFileCache[$oldestKey]);
        }
    }

    private static function currentScopeKey(): ?string
    {
        try {
            $requestId = RequestContext::getId();
            if ($requestId !== null && $requestId !== '') {
                return 'req:' . $requestId;
            }

            $scopeId = RequestContext::getStorageScopeId();
        } catch (\Throwable) {
            return null;
        }

        return $scopeId === null || $scopeId === '' ? null : 'conn:' . $scopeId;
    }

    private static function currentFiber(): ?\Fiber
    {
        if (!class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    private function isRequestRuntime(): bool
    {
        if (!\defined('CLI') || !CLI) {
            return true;
        }

        $context = Context::getCurrent();
        return $context !== null && $context->get('meta.type') === 'request';
    }

    /**
     * @DESC          # 读取模板文件拓展
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/20 20:18
     * 参数区：
     * @return string
     */
    /**
     * 迁移期只读辅助：在指定源文件上下文中执行回调，用于 v3 影子编译解析
     * `fetchTagSource()` 相对路径；不改变渲染行为。
     */
    public function withActiveSourceOrigin(string $origin, callable $callback): mixed
    {
        $previous = $this->activeSourceOrigin;
        $this->activeSourceOrigin = $origin;
        try {
            return $callback();
        } finally {
            $this->activeSourceOrigin = $previous;
        }
    }

    public function getFileExt(): string
    {
        return $this->file_ext;
    }

    /**
     * @DESC          # 设置模板文件拓展
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/20 20:17
     * 参数区：
     *
     * @param string $ext 拓展：例如.phtml则填写phtml
     *
     * @return $this
     */
    public function setFileExt(string $ext): static
    {
        $this->file_ext = '.' . $ext;
        return $this;
    }

    public function getBlock(string $class)
    {
        return ObjectManager::getInstance($class);
    }

    public function init()
    {
        // 语言初始化
        $local = $this->initLanguage();
        $this->theme ??= Env::getInstance()->getTheme();
        $this->eventsManager ??= ObjectManager::getInstance(EventsManager::class);
        $this->viewCache ??= w_cache('view');
        $this->request = ObjectManager::getInstance(Request::class);

        if ($this->isRequestRuntime()) {
            // 请求级数据必须每次请求都重新绑定，避免单例状态泄漏。
            $modulePath = $this->request->getModulePath();
            $this->view_dir = $modulePath === '' ? '' : rtrim($modulePath, DS) . DS . DataInterface::dir . DS;
            // Never seed extract()'d $title with the module code (Weline_Theme etc.):
            // under nested ob_file()/init() that placeholder leaks into H1/SEO.
            // Restore only a real controller_title when present.
            $this->setData('title', '');
            $meta = $this->getData('meta');
            if (is_array($meta)) {
                $controllerTitle = trim((string)($meta['controller_title'] ?? ''));
                if ($controllerTitle !== '' && !EmbeddedPageTitle::isModulePlaceholder($controllerTitle)) {
                    $this->setData('title', $controllerTitle);
                }
            }
            $this->request->setData('url', $this->request->getUrlBuilder()->getCurrentUrl());
            // 两个代理在读取时解析当前请求/环境；仅复用载体，嵌套模板仍每次重新绑定数据。
            $context = Context::getCurrent();
            $carriers = $context?->get('view.template.init_carriers');
            if ($carriers === null) {
                $carriers = ['req' => new TemplateRequestView(), 'env' => new TemplateEnvView()];
                $context?->set('view.template.init_carriers', $carriers);
            }
            $this->setData('req', $carriers['req']);
            $this->setData('env', $carriers['env']);
            $this->setData('local', $local);
        }

        if (empty($this->statics_dir)) {
            $this->statics_dir = $this->getViewDir(DataInterface::view_STATICS_DIR);
        }
        if (empty($this->template_dir)) {
            $this->template_dir = $this->getViewDir(DataInterface::view_TEMPLATE_DIR);
        }
        if (empty($this->compile_dir)) {
            $this->compile_dir = $this->getViewDir(DataInterface::view_TEMPLATE_COMPILE_DIR);
        }
        return $this;
    }

    /** @return array{code: string, lang: string} */
    private function initLanguage(): array
    {
        $lang = WelineEnv::getLang();
        $this->setData('lang', $lang);
        // lang变量用于HTML lang属性，必须符合BCP 47规范（将下划线替换为连字符）
        $htmlLang = str_replace('_', '-', $lang);
        $htmlDir = $this->resolveTextDirection($lang);
        $langLocal = WelineEnv::getLangLocal();
        $this->setData('lang_local', $langLocal);
        // htmlLang变量与lang相同，保持向后兼容
        $this->setData('htmlLang', $htmlLang);
        $this->setData('htmlDir', $htmlDir);
        $this->setData('textDirection', $htmlDir);
        $this->setData('isRtl', $htmlDir === 'rtl');

        // local 与语言属性共享本次读取结果；下次 init 仍交由 State 处理语言覆盖及重置。
        return ['code' => $langLocal, 'lang' => $lang];
    }

    private function resolveTextDirection(string $lang): string
    {
        $parts = preg_split('/[-_]+/', trim($lang)) ?: [];
        $language = strtolower((string)($parts[0] ?? ''));
        $script = '';

        foreach ($parts as $part) {
            if (strlen($part) === 4 && ctype_alpha($part)) {
                $script = strtolower($part);
                break;
            }
        }

        $rtlLanguages = [
            'ar' => true,
            'arc' => true,
            'ckb' => true,
            'dv' => true,
            'fa' => true,
            'he' => true,
            'iw' => true,
            'ks' => true,
            'ps' => true,
            'sd' => true,
            'ug' => true,
            'ur' => true,
            'yi' => true,
        ];
        $rtlScripts = [
            'adlm' => true,
            'arab' => true,
            'hebr' => true,
            'nkoo' => true,
            'rohg' => true,
            'syrc' => true,
            'thaa' => true,
        ];

        return isset($rtlScripts[$script]) || isset($rtlLanguages[$language]) ? 'rtl' : 'ltr';
    }

    public function __init()
    {
        $this->init();
    }

    /**
     * @DESC          # 获取form_key
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 22:24
     * 参数区：
     * @return string
     */
    public function getFormKey($url): string
    {
        return ObjectManager::getInstance(FormKey::class)->getHtml($url);
    }

    /**
     * @DESC          # 获取当前请求的 form_key 值（用于 JS 等）
     * @return string
     */
    public function getFormKeyValue(): string
    {
        $this->init();
        $path = $this->request->getUrlBuilder()->getCurrentUrl();
        return ObjectManager::getInstance(FormKey::class)->getKey($path, '');
    }

    /**
     * @DESC         |获取视图文件
     *
     * 参数区：
     *
     * @param $filepath
     *
     * @return string
     * @throws \Exception
     */
    public function getViewFile($filepath): string
    {
        $path = $this->view_dir . $filepath;
        if (!file_exists($path) && DEV) {
            new Exception(__('文件不存在！位置：') . $path);
        }
        $this->fetch($filepath);

        return $path;
    }

    /**
     * @DESC         |模板中变量分配调用的方法
     *
     * 参数区：
     *
     * @param string|array $key 键值
     * @param null $value
     *
     * @return Template
     */
    public function assign(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            if (isset($key['meta']) && is_array($key['meta'])) {
                $key['meta'] = $this->withAssignedTitleInMeta($key['meta'], $key['title'] ?? null);
            }
            $this->setData($key, $value);
            if (array_key_exists('title', $key)) {
                $this->syncAssignedTitleToMeta($key['title']);
            }
            return $this;
        }

        if ($key === 'meta' && is_array($value)) {
            $value = $this->withAssignedTitleInMeta($value);
        }

        $this->setData($key, $value);
        if ($key === 'title') {
            $this->syncAssignedTitleToMeta($value);
        }
        return $this;
    }

    public function setData(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $key = EmbeddedPageTitle::sanitizePublicSlots($key);
            parent::setData($key, $value);
            $this->publishAssignedSeoPageProfile($key);
        } elseif (\is_string($key) && \in_array($key, ['title', 'meta_title', 'controller_title'], true)) {
            $value = \trim((string)$value);
            if ($value === '' || EmbeddedPageTitle::isInternalIdentifier($value)) {
                $value = '';
            }
            parent::setData($key, $value);
        } elseif ($key === 'meta' && is_array($value)) {
            $value = EmbeddedPageTitle::sanitizePublicSlots($value);
            parent::setData($key, $value);
        } else {
            parent::setData($key, $value);
            if ($key === 'seo' && is_array($value)) {
                // Only explicit controller/page seo payloads may update the request SEO bag.
                // Widget/card setData('product'|'storefront_offer'|...) must never redefine page SEO:
                // ControllerFetchFileAfter renders content (product cards) before layout head.
                $this->publishAssignedSeoPageProfile(['seo' => $value]);
            }
        }

        return $this;
    }

    /**
     * Publish controller page SEO into RequestContext so Partials head still sees it after
     * Theme layout/widget unsetData().
     *
     * Root-cause rule: ONLY the `seo` assign owns this bag. Product cards call
     * setData('storefront_offer') while content is primed before head; promoting that
     * into page_type=product is what polluted /, /products, /search, /en_US.
     *
     * @param array<string, mixed> $assigned
     */
    private function publishAssignedSeoPageProfile(array $assigned): void
    {
        if (!isset($assigned['seo']) || !is_array($assigned['seo'])) {
            return;
        }

        $seo = $assigned['seo'];
        // Controller convenience: assign(['seo' => ..., 'product' => ...]) in one shot.
        if (isset($assigned['product']) && is_array($assigned['product']) && !isset($seo['product'])) {
            $seo['product'] = $assigned['product'];
        }
        if (
            (!isset($seo['page_type']) || trim((string) $seo['page_type']) === '')
            && isset($seo['product'])
            && is_array($seo['product'])
        ) {
            $seo['page_type'] = 'product';
        }

        \Weline\Framework\Http\Seo\SeoPageProfileBag::replace($seo);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function withAssignedTitleInMeta(array $meta, mixed $title = null): array
    {
        $title = trim((string)($title ?? $this->getData('title')));
        // Module codes (e.g. Weline_Customer) stay as internal title data only —
        // never promote into meta.title / controller_title for H1/SEO.
        if ($title === '' || EmbeddedPageTitle::isModulePlaceholder($title)) {
            return $meta;
        }

        $meta['controller_title'] = $meta['controller_title'] ?? $title;
        if (!$this->hasHeadTitle($meta)) {
            $meta['title'] = $title;
        }

        return $meta;
    }

    private function syncAssignedTitleToMeta(mixed $title): void
    {
        $title = trim((string)$title);
        if ($title === '' || EmbeddedPageTitle::isModulePlaceholder($title)) {
            return;
        }

        $meta = $this->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['controller_title'] = $meta['controller_title'] ?? $title;
        if (!$this->hasHeadTitle($meta)) {
            $meta['title'] = $title;
        }
        $this->setData('meta', $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function hasHeadTitle(array $meta): bool
    {
        foreach (['title', 'meta_title'] as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = trim((string)$meta[$key]);
            if ($value !== '' && !EmbeddedPageTitle::isModulePlaceholder($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stable compile dimensions shared by physical compile dirs and path maps.
     *
     * Must read live w_env (not StorefrontCacheKeyContext fence): early fence
     * can freeze the wrong lang before route localization, and processViewFileCache
     * would then hand a Hindi-baked <lang> compile to an en_US request.
     *
     * site_address = 文档公开源（当前打开的站点地址）。同站多绑定 Host 时各自一维，
     * 编译期 @url 烘焙的绝对地址必须落在该维，禁止用单一站柜登记 url 盖全部分区。
     *
     * @return array{
     *   compile_scope_schema:string,area:string,website_id:string,website_code:string,
     *   lang:string,currency:string,website_url:string,site_address:string,theme:string,hooks_registry:string
     * }
     */
    private function templateCompileScopeDimensions(): array
    {
        $dimension = static function (string $key, string $default, string $pattern): string {
            $value = \function_exists('w_env') ? (string)\w_env($key, $default) : $default;
            return \preg_match($pattern, $value) === 1 ? $value : $default;
        };

        return [
            'compile_scope_schema' => self::TEMPLATE_COMPILE_SCOPE_SCHEMA,
            'area' => $dimension('area', 'frontend', '/^[a-z][a-z0-9_-]{0,31}$/i'),
            'website_id' => $dimension('website_id', '0', '/^[0-9]{1,10}$/'),
            'website_code' => $dimension('website_code', 'default', '/^[a-z0-9][a-z0-9_.-]{0,63}$/i'),
            'lang' => $dimension('user.lang', 'default', '/^[a-z]{2,3}(?:_[A-Za-z]{2,12}){0,2}$/'),
            'currency' => $dimension('user.currency', 'CNY', '/^[A-Z]{3}$/'),
            // 站柜登记源（挂载 path / 匹配身份）；绝对 URL 烘焙以 site_address 为准。
            'website_url' => \function_exists('w_env') ? (string)\w_env('website_url', '') : '',
            'site_address' => $this->resolveCompileSiteAddress(),
            'theme' => $this->resolveThemeCacheKeyForFetchFile($this->view_dir),
            // Nested w:widget compile bakes <w:hook> output. Digest stays in compile_id
            // (in-place overwrite), not in a new ctx_/__bytes_ directory.
            'hooks_registry' => self::hooksRegistryCompileDigest(),
            // Mode\Set staging / env prod must not reuse DEV-module-prefix compile dirs.
            'deploy_public_urls' => \Weline\Framework\Deploy\DeployPublicUrlMode::useProduction()
                ? 'prod' : 'dev',
        ];
    }

    /**
     * 编译用「站点地址」= 文档公开源。
     * 优先 Request::getBaseHost()（已与请求 Host 对齐、剥 Worker 口）；
     * 其次直接拼请求 Host；最后回退 w_env(website_url)（CLI / 无请求）。
     */
    private function resolveCompileSiteAddress(): string
    {
        try {
            if (isset($this->request) && \is_object($this->request) && \method_exists($this->request, 'getBaseHost')) {
                $origin = \rtrim(\trim((string)$this->request->getBaseHost()), '/');
                $host = \parse_url($origin, \PHP_URL_HOST);
                if ($origin !== '' && \is_string($host) && $host !== '') {
                    return $origin;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (isset($this->request) && \is_object($this->request)) {
                $scheme = 'http';
                if (\method_exists($this->request, 'isSecure') && $this->request->isSecure()) {
                    $scheme = 'https';
                } elseif (\method_exists($this->request, 'getSsl')) {
                    $scheme = (string)$this->request->getSsl() === 'https' ? 'https' : 'http';
                } elseif (\function_exists('w_env')) {
                    $scheme = \strtolower((string)\w_env('server.request_scheme', 'http')) === 'https' ? 'https' : 'http';
                }
                $httpHost = '';
                if (\method_exists($this->request, 'getServer')) {
                    $httpHost = \trim((string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: ''));
                }
                if ($httpHost === '' && \function_exists('w_env')) {
                    $httpHost = \trim((string)\w_env('server.http_host', \w_env('server.server_name', '')));
                }
                if ($httpHost !== '') {
                    $origin = $scheme . '://' . $httpHost;
                    // 剥私有 Worker 口，与 getBaseHost 公开源契约一致。
                    $parts = \parse_url($origin);
                    if (\is_array($parts) && !empty($parts['host'])) {
                        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
                        $wlsPortRaw = \function_exists('w_env') ? \w_env('server.wls_port', \getenv('WLS_PORT') ?: '') : (\getenv('WLS_PORT') ?: '');
                        $wlsPort = (\is_int($wlsPortRaw) || (\is_string($wlsPortRaw) && $wlsPortRaw !== '' && \ctype_digit((string)$wlsPortRaw)))
                            ? (int)$wlsPortRaw
                            : 0;
                        if ($port >= 9000 && $port <= 9999 || ($wlsPort > 0 && $port === $wlsPort)) {
                            $origin = $scheme . '://' . (string)$parts['host'];
                        }

                        return \rtrim($origin, '/');
                    }
                }
            }
        } catch (\Throwable) {
        }

        $fallback = \function_exists('w_env') ? \trim((string)\w_env('website_url', '')) : '';

        return \rtrim($fallback, '/');
    }

    /** Short identity shared by convertFetchFileName maps and compile directories. */
    private function templateCompileScopeMapKey(): string
    {
        return $this->hashTemplateCompileScope($this->templateCompileScopeDimensions());
    }

    /**
     * @param array<string, string> $scope
     */
    private function hashTemplateCompileScope(array $scope): string
    {
        $scopeJson = \json_encode($scope, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return \substr(\hash('sha256', \is_string($scopeJson) ? $scopeJson : \serialize($scope)), 0, 32);
    }

    /**
     * Keep compiled templates partitioned only by stable render dimensions.
     *
     * Plain-text scope directories (no ctx_ hash). Request cookies, headers,
     * Session IDs and tracking values must never become directory names.
     * hooks_registry / pipeline changes invalidate via compile_id in-file, not new dirs.
     */
    private function stableTemplateCompileDirectory(string $directory): string
    {
        $normalized = \rtrim(\str_replace('\\', '/', $directory), '/');
        if ($normalized === '') {
            return $directory;
        }

        // Strip a previously appended scope leaf (v6 ctx_hash or v7 plain label)
        // so repeated calls stay idempotent.
        $base = \preg_replace('#/[A-Za-z0-9_-]+_ctx_[a-f0-9]{32}$#iD', '', $normalized);
        if (!\is_string($base) || $base === '') {
            $base = $normalized;
        }
        $base = \preg_replace(
            '#/(?:frontend|backend|admin)_[A-Za-z0-9][A-Za-z0-9_.-]{0,180}$#iD',
            '',
            $base
        );
        if (!\is_string($base) || $base === '') {
            $base = $normalized;
        }

        $scope = $this->templateCompileScopeDimensions();
        $labelParts = [
            $scope['area'],
            'w' . $scope['website_id'],
            $scope['website_code'],
            $scope['lang'],
            $scope['currency'],
        ];
        // 站点地址必须排在 theme 之前：长 theme path 截断时不得吃掉 _o_{host}。
        $originLeaf = $this->compileOriginPathLeaf((string)($scope['site_address'] ?: $scope['website_url']));
        if ($originLeaf !== '') {
            $labelParts[] = 'o_' . $originLeaf;
        }
        $theme = $this->sanitizeCompileContextSegment((string)$scope['theme']);
        if ($theme !== '' && $theme !== 'default' && $theme !== 'area_frontend') {
            $labelParts[] = 't_' . $theme;
        }
        $label = \implode('_', \array_map(fn(string $value): string => $this->sanitizeCompileContextSegment($value), $labelParts));
        if (\strlen($label) > 160) {
            $label = \substr($label, 0, 160);
        }

        return \str_replace('/', DS, \rtrim($base, '/')) . DS . $label . DS;
    }

    /**
     * Host[:port] leaf so different origins do not share baked absolute URLs,
     * without a content-addressed ctx_ hash directory.
     */
    private function compileOriginPathLeaf(string $websiteUrl): string
    {
        $websiteUrl = \trim($websiteUrl);
        if ($websiteUrl === '') {
            return '';
        }
        $parts = \parse_url($websiteUrl);
        if (!\is_array($parts)) {
            return $this->sanitizeCompileContextSegment($websiteUrl);
        }
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? (string)(int)$parts['port'] : '';
        if ($host === '') {
            return $this->sanitizeCompileContextSegment($websiteUrl);
        }

        return $this->sanitizeCompileContextSegment($port !== '' ? $host . '_' . $port : $host);
    }

    /**
     * @param string $fileName 文件名转换查找
     *
     * @throws Core
     * @throws Exception
     */
    public function convertFetchFileName(string $fileName): array
    {
        $absoluteSourcePath = $this->isAbsoluteTemplateSourcePath($fileName);
        // Path maps must key by the same w_env compile scope as physical dirs.
        // viewEnvironmentCacheSuffix alone can freeze an early Storefront fence
        // lang and collide hi_IN / en_US onto one processViewFileCache entry.
        $templateContextKey = $this->viewEnvironmentCacheSuffix('template-file-map')
            . '|' . $this->resolveThemeCacheKeyForFetchFile($this->view_dir . $fileName)
            . '|compile_scope:' . $this->templateCompileScopeMapKey();
        $comFileName_cache_key = $this->view_dir . $fileName . '_comFileName|' . $templateContextKey;
        $tplFile_cache_key = $this->view_dir . $fileName . '_tplFile|' . $templateContextKey;
        $processViewFileCacheKey = $comFileName_cache_key . "\0" . $tplFile_cache_key;
        $comFileName = '';
        $tplFile = '';
        $forceModuleThemeSource = (bool)$this->getData(self::DATA_FORCE_MODULE_THEME_SOURCE);
        $skipCache = $forceModuleThemeSource || (isset($this->request) && $this->request && $this->request->getData('skip_view_file_cache'));
        $useProcessViewFileCache = !$skipCache && (PROD || (defined('DEV') && DEV && Runtime::isPersistent()));
        $useSharedViewFileCache = PROD && !$skipCache;
        # 生产模式或常驻开发 Worker 复用映射；普通开发进程仍实时读取文件
        if ($useProcessViewFileCache) {
            $processCached = self::readProcessViewFileCache($processViewFileCacheKey);
            if ($processCached !== null) {
                $comFileName = $processCached['compiled'];
                $tplFile = $processCached['tpl'];
            } elseif ($useSharedViewFileCache) {
                $comFileName = $this->viewCache->get($comFileName_cache_key);
                $tplFile = $this->viewCache->get($tplFile_cache_key);
            }
        }
        # 测试
        //        file_put_contents(__DIR__ . '/test.txt', $comFileName . PHP_EOL, FILE_APPEND);
        // 编译文件不存在的时候 重新对文件进行处理 防止每次都处理
        if (!\is_string($comFileName) || $comFileName === '' || !is_file($comFileName)
            || !\is_string($tplFile) || $tplFile === '' || !is_file($tplFile)
        ) {
            // 解析模板路由
            if ('/' !== DS) {
                $fileName = str_replace('/', DS, $fileName);
            }
            $file_name_dir_arr = explode(DS, $fileName);
            $file_dir = '';
            $file_name = '';

            // 如果给的文件名字有路径
            if (count($file_name_dir_arr) > 1) {
                $file_name = array_pop($file_name_dir_arr);
                $file_dir = implode(DS, $file_name_dir_arr);
                if ($file_dir) {
                    $file_dir .= DS;
                }
            }
            # 检测读取别的模块的模板文件
            list($fileName, $file_dir, $view_dir, $template_dir, $compile_dir) = $this->processFileSource($fileName, $file_dir);
            $compile_dir = $this->stableTemplateCompileDirectory($compile_dir);
            if ($absoluteSourcePath) {
                $file_dir = '';
            }
            // 判断文件后缀
            $file_ext = substr(strrchr($fileName, '.'), 1);
            //
            //            // 检测模板文件：如果文件名有后缀 则直接到view下面读取。没有说明是默认
            if ($file_ext) {
                $tplFile = $absoluteSourcePath ? $fileName : $view_dir . $fileName;
            } else {
                $tplFile = ($absoluteSourcePath ? $fileName : $view_dir . $fileName) . $this->getFileExt();
            }
            //            p($tplFile,1);
            $sourceTplFile = $tplFile;
            $tplFile = $this->fetchFile($tplFile);
            $sourceCompilePathSuffix = $this->buildSourceCompilePathSuffix($sourceTplFile, $tplFile, $absoluteSourcePath);
            //            p($tplFile);

            if (!file_exists($tplFile)) {
                $msg = __('获取操作：%{1}', $fileName) . PHP_EOL;
                $msg .= __('模板文件不存在！：%{1} ', $tplFile) . PHP_EOL;
                $msg .= __('源文件：%{1}', $fileName);
                throw new Exception($msg);
            }

            // 检测目录是否存在,不存在则建立
            $baseComFileDir = $compile_dir . DS . $this->templateCompileContextDir() . DS . ($file_dir ?: '') . $sourceCompilePathSuffix;
            if (!is_dir($baseComFileDir) && !@mkdir($baseComFileDir, 0770, true) && !is_dir($baseComFileDir)) {
                throw new Exception('Failed to create template compile directory: ' . $baseComFileDir);
            }

            //定义编译合成的文件 加了前缀 和路径 和后缀名.phtml
            $file_name = $file_name ?? $fileName;
            if ($file_ext) {
                $comFileName = $baseComFileDir . 'com_' . $file_name;
            } else {
                $comFileName = $baseComFileDir . 'com_' . $file_name . $this->getFileExt();
            }
            $comFileName = $this->fetchFile($comFileName);
            # 生产模式缓存: 根据管道设置缓存
            if ($useSharedViewFileCache) {
                $this->viewCache->set($comFileName_cache_key, $comFileName);
                $this->viewCache->set($tplFile_cache_key, $tplFile);
            };
        }

        if ($useProcessViewFileCache && is_string($comFileName) && is_string($tplFile)) {
            self::rememberProcessViewFileCache($processViewFileCacheKey, $comFileName, $tplFile);
        }

        # 测试
        //        file_put_contents(__DIR__ . '/test.txt', $comFileName . PHP_EOL, FILE_APPEND);
        if (is_int(strpos($comFileName, '\\'))) {
            $comFileName = str_replace('\\', DS, $comFileName);
        }
        if (is_int(strpos($comFileName, '//'))) {
            $comFileName = str_replace('//', DS, $comFileName);
        }
        return [$comFileName, $tplFile];
    }

    private function isAbsoluteTemplateSourcePath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1;
    }

    private function buildSourceCompilePathSuffix(
        string $logicalTplFile,
        string $resolvedTplFile,
        bool $forceIsolation = false
    ): string
    {
        $logicalPath = $this->normalizeTemplateSourcePath($logicalTplFile);
        $resolvedPath = $this->normalizeTemplateSourcePath($resolvedTplFile);
        if ($logicalPath === '' || $resolvedPath === '' || (!$forceIsolation && $logicalPath === $resolvedPath)) {
            return '';
        }

        // Path-identity leaf only (not content digest). Keeps logical≠resolved isolated
        // without __source_{sha}/ accumulation of version hashes.
        $base = $this->sanitizeCompileContextSegment(\pathinfo($resolvedPath, \PATHINFO_FILENAME) ?: 'resolved');

        return 'src_' . $base . '_r' . \substr(\sha1($resolvedPath), 0, 8) . DS;
    }

    /**
     * Stable compile root for the current render scope (plaintext leaf + lang/currency).
     * Theme/layout helpers must use this — never invent parallel ctx_/lang-only paths.
     */
    public function resolveStableCompileRoot(string $baseCompileDir): string
    {
        return $this->stableTemplateCompileDirectory($baseCompileDir)
            . $this->templateCompileContextDir()
            . DS;
    }

    /**
     * Virtual/pinned source leaf under the stable compile root (plaintext, not __source_{hash24}).
     */
    private function virtualSourceCompileLeaf(string $logicalPath, string $originPath, string $contextKey): string
    {
        $name = $this->sanitizeCompileContextSegment(\pathinfo($logicalPath, \PATHINFO_FILENAME) ?: 'virtual');
        $leaf = 'src_' . $name;
        if ($contextKey !== '') {
            $leaf .= '_' . $this->sanitizeCompileContextSegment($contextKey);
        }
        $normalizedOrigin = $this->normalizeTemplateSourcePath($originPath);
        $normalizedLogical = $this->normalizeTemplateSourcePath($logicalPath);
        if ($normalizedOrigin !== '' && $normalizedOrigin !== $normalizedLogical) {
            $leaf .= '_r' . \substr(\sha1($normalizedOrigin), 0, 8);
        }

        return $leaf;
    }

    private function normalizeTemplateSourcePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $realPath = realpath($path);
        if (is_string($realPath) && $realPath !== '') {
            $path = $realPath;
        }

        return str_replace('\\', '/', $path);
    }

    private function templateCompileContextDir(): string
    {
        $lang = (string)WelineEnv::getLang();
        if (\preg_match('/^[a-z]{2,3}(?:_[A-Za-z]{2,12}){0,2}$/', $lang) !== 1) {
            $lang = \function_exists('w_env_get') ? (string)\w_env_get('user.lang', 'default') : 'default';
        }
        if (\preg_match('/^[a-z]{2,3}(?:_[A-Za-z]{2,12}){0,2}$/', $lang) !== 1) {
            $lang = 'default';
        }

        $currency = (string)WelineEnv::getCurrency();
        if (\preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = \function_exists('w_env_get') ? (string)\w_env_get('user.currency', 'CNY') : 'CNY';
        }
        if (\preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'CNY';
        }

        $lang = $this->sanitizeCompileContextSegment($lang);
        $currency = $this->sanitizeCompileContextSegment($currency);

        return $lang . DS . $currency;
    }

    private function sanitizeCompileContextSegment(string $segment): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($segment));

        return $segment === '' ? 'default' : $segment;
    }


    /** Fixed bytes are scoped to this request's Template instance. */
    public function pinSource(string $logicalPath, string $sourceBytes, string $originPath = '', string $contextKey = ''): void
    {
        $this->writePinnedSource($logicalPath, $sourceBytes, $originPath, $contextKey);
    }

    /**
     * Install an immutable source set. The caller supplies a token unique to its snapshot instance.
     * Other bindings survive; a later standalone pin invalidates this installation's generation.
     *
     * @param array<string, array{string, string, string}> $sources
     */
    public function pinSourceSet(string $token, array $sources): void
    {
        if ($this->pinnedSourceSetToken === $token
            && $this->pinnedSourceSetGeneration === $this->pinnedSourceGeneration
        ) {
            return;
        }
        foreach ($sources as $logicalPath => $source) {
            $this->writePinnedSource($logicalPath, ...$source);
        }
        $this->pinnedSourceSetToken = $token;
        $this->pinnedSourceSetGeneration = $this->pinnedSourceGeneration;
    }

    /**
     * Drop pinned layout bytes after a compile page finishes (pipeline worker).
     * Bumps generation so a later pinSourceSet cannot reuse a stale install.
     */
    public function clearPinnedSources(): void
    {
        $this->pinnedSources = [];
        $this->pinnedSourceDigests = [];
        $this->pinnedSourceSetToken = null;
        $this->pinnedSourceSetGeneration = -1;
        ++$this->pinnedSourceGeneration;
    }

    private function writePinnedSource(string $logicalPath, string $sourceBytes, string $originPath = '', string $contextKey = ''): void
    {
        $source = [$sourceBytes, $originPath !== '' ? $originPath : $logicalPath, $contextKey];
        if (($this->pinnedSources[$logicalPath] ?? null) !== $source) {
            unset($this->pinnedSourceDigests[$logicalPath]);
        }
        ++$this->pinnedSourceGeneration;
        if (TraceGate::isArmed()) {
            TraceGate::measurePhase('template.source_pin', function () use ($logicalPath, $source): void {
                $this->pinnedSources[$logicalPath] = $source;
            });
            return;
        }
        $this->pinnedSources[$logicalPath] = $source;
    }

    public function getFetchFile(string $fileName, string|null $module_name = ''): string
    {
        $memoKey = null;
        if ($module_name === '' || $module_name === null) {
            $origin = $this->activeSourceOrigin;
            $memoKey = 'view.fetch_file:' . $fileName . '|' . $origin;
            $memoized = RequestContext::get($memoKey);
            if (\is_string($memoized) && $memoized !== '' && \is_file($memoized)) {
                return $memoized;
            }
        }

        if (isset($this->pinnedSources[$fileName])) {
            return $this->getFetchFileFromSource($fileName, ...$this->pinnedSources[$fileName]);
        }
        if ($this->activeSourceOrigin !== '' && !$this->isAbsoluteTemplateSourcePath($fileName) && !str_contains($fileName, '::')) {
            $relative = dirname($this->activeSourceOrigin) . DS . $fileName;
            if (is_file($relative)) {
                $fileName = $relative;
            }
        }
        [$comFileName, $tplFile] = $this->convertFetchFileName($fileName);
        if (isset($this->pinnedSources[$tplFile])) {
            return $this->getFetchFileFromSource($tplFile, ...$this->pinnedSources[$tplFile]);
        }
        // Render-time consumers ({@see obFileInner}, {@see streamCompiledFile}) read the
        // origin back out of this map to resolve relative sibling fetches. Record it here
        // so the stat-proof fast path below cannot skip it; compileSourceBytes() repeats
        // the same assignment on the slow path.
        $this->compiledSourceOrigins[$comFileName] = $tplFile;
        $sourceMtime = (int)@\filemtime($tplFile);
        $sourceSize = (int)@\filesize($tplFile);
        $sourceCtime = (int)@\filectime($tplFile);
        $sourceIno = (int)@\fileinode($tplFile);
        if ($sourceMtime > 0
            && $this->fetchFileIdentityIsProven($comFileName, $sourceMtime, $sourceSize, $sourceCtime, $sourceIno)
        ) {
            if ($memoKey !== null) {
                RequestContext::set($memoKey, $comFileName);
            }

            return $comFileName;
        }

        $content = file_get_contents($tplFile);
        if (!is_string($content)) {
            throw new Exception('Unable to read template source: ' . $tplFile);
        }

        $compiled = $this->compileSourceBytes($comFileName, $content, $tplFile, '');
        if ($sourceMtime > 0) {
            $this->rememberFetchFileIdentity($compiled, $sourceMtime, $sourceSize, $sourceCtime, $sourceIno);
        }
        if ($memoKey !== null) {
            RequestContext::set($memoKey, $compiled);
        }

        return $compiled;
    }

    /**
     * `before_compile` observers bake into compiled output, so their registration
     * order/identity is part of the compile identity.
     */
    private function beforeCompilePipeline(): string
    {
        return (string)json_encode(
            $this->eventsManager->getEventObservers('Weline_Framework_Template::before_compile'),
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * True when a previous call already proved this compiled artifact matches the
     * current source, hooks registry and pipeline.
     *
     * Cost of a hit (~9 µs measured): four cached stat probes + `is_file` + hooks
     * digest + pipeline digest. The full path it replaces costs ~58 µs: source
     * `open()` + double md5 + compile identity + 512 B header `open()`.
     *
     * Relies on PHP's stat cache, which is a **single-entry** cache (the last stat'ed
     * path only), not a per-path map — verified on this build, see
     * `dev/tmp/bench-statcache.php`. So a source edited by another process cannot stay
     * hidden behind a stale entry across requests: the very next `is_file($compiledPath)`
     * below evicts it. Forcing a fresh `stat()` with `clearstatcache()` costs
     * 0.87 µs per call (0.05 → 0.92 µs) and buys nothing here.
     */
    private function fetchFileIdentityIsProven(
        string $compiledPath,
        int $sourceMtime,
        int $sourceSize,
        int $sourceCtime,
        int $sourceIno
    ): bool {
        $memo = self::$fetchFileIdentityMemo[$compiledPath] ?? null;
        if ($memo === null
            || $memo['sm'] !== $sourceMtime
            || $memo['ss'] !== $sourceSize
            || $memo['sc'] !== $sourceCtime
            || $memo['si'] !== $sourceIno
        ) {
            return false;
        }
        // A source written in the current second can still change without moving
        // mtime; only trust the stat proof once the write is at least a second old.
        if (\time() - $sourceMtime < 1) {
            return false;
        }
        // Content is pinned by the validated inputs; only deletion needs noticing.
        if (!\is_file($compiledPath)) {
            return false;
        }
        // An operator forcing recompiles in DEV must not be short-circuited.
        if (DEV && \in_array(Env::getInstance()->getConfig('template.force_recompile_in_dev', false), [true, 1, '1'], true)) {
            return false;
        }

        return $memo['hd'] === self::hooksRegistryCompileDigest()
            && $memo['pd'] === $this->beforeCompilePipeline();
    }

    private function rememberFetchFileIdentity(
        string $compiledPath,
        int $sourceMtime,
        int $sourceSize,
        int $sourceCtime,
        int $sourceIno
    ): void {
        if ($sourceMtime <= 0 || !\is_file($compiledPath)) {
            return;
        }
        if (\count(self::$fetchFileIdentityMemo) >= self::FETCH_FILE_IDENTITY_MEMO_MAX) {
            self::$fetchFileIdentityMemo = [];
        }
        self::$fetchFileIdentityMemo[$compiledPath] = [
            'sm' => $sourceMtime,
            'ss' => $sourceSize,
            'sc' => $sourceCtime,
            'si' => $sourceIno,
            'hd' => self::hooksRegistryCompileDigest(),
            'pd' => $this->beforeCompilePipeline(),
        ];
    }

    /** Compile a captured or virtual source with the ordinary Taglib/i18n pipeline. */
    public function getFetchFileFromSource(string $logicalPath, string $sourceBytes, string $originPath = '', string $contextKey = ''): string
    {
        $originPath = $originPath !== '' ? $originPath : $logicalPath;
        [, , , , $compileDir] = $this->processFileSource($originPath, '');
        $compileDir = $this->resolveStableCompileRoot($compileDir)
            . $this->virtualSourceCompileLeaf($logicalPath, $originPath, $contextKey)
            . DS;
        $filename = 'com_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($logicalPath));
        if (!str_ends_with($filename, '.phtml')) {
            $filename .= '.phtml';
        }

        $sourceHash = null;
        // Direct virtual-source calls may reuse a logical name while supplying different bytes.
        if (($this->pinnedSources[$logicalPath] ?? null) === [$sourceBytes, $originPath, $contextKey]) {
            $sourceHash = $this->pinnedSourceDigests[$logicalPath] ??= $this->sourceBytesDigest($sourceBytes);
        }

        return $this->compileSourceBytes($compileDir . $filename, $sourceBytes, $originPath, $contextKey, $sourceHash);
    }

    public function fetchSourceHtml(string $logicalPath, string $sourceBytes, string $originPath = '', string $contextKey = '', array $dictionary = []): string
    {
        return $this->ob_file($this->getFetchFileFromSource($logicalPath, $sourceBytes, $originPath, $contextKey), $dictionary);
    }

    private function sourceBytesDigest(string $sourceBytes): string
    {
        return TraceGate::isArmed()
            ? TraceGate::measurePhase('template.source_bytes_digest', static fn(): string => md5(md5($sourceBytes) . '|' . strlen($sourceBytes) . '|' . Taglib::COMPILER_GENERATION) . '-' . strlen($sourceBytes))
            : md5(md5($sourceBytes) . '|' . strlen($sourceBytes) . '|' . Taglib::COMPILER_GENERATION) . '-' . strlen($sourceBytes);
    }

    private function compileSourceBytes(string $baseCompiledPath, string $sourceBytes, string $originPath, string $contextKey, ?string $sourceHash = null): string
    {
        if (\defined('DEV') && DEV) {
            // Theme spec gate: a non-compliant theme template must fail here, while
            // editing, not later in PageSpeed. Throws ThemeSpecViolationException.
            \Weline\Theme\Service\Spec\ThemeSpecValidator::assertFileForDev($originPath);
        }
        $sourceHash ??= $this->sourceBytesDigest($sourceBytes);
        // S2-A：模板级白名单（默认关闭）。只有显式配置 engine.templates 命中时才走 v3；
        // 未配置时 useV3=false，旧编译入口行为完全不变。
        $useV3 = false;
        try {
            if (\class_exists(\Weline\Framework\View\Compiler\Shadow\ShadowConfig::class)) {
                $useV3 = \Weline\Framework\View\Compiler\Shadow\ShadowConfig::fromEnv()
                    ->shouldCompileTemplate($originPath);
            }
        } catch (\Throwable) {
            $useV3 = false;
        }
        // Event registration + hooks registry alter baked output; fold into compile_id.
        $pipeline = $this->beforeCompilePipeline();
        $compileId = $this->buildCompileIdentity(
            $sourceHash,
            $originPath,
            $contextKey,
            $pipeline,
            $useV3 ? 'v3-template' : '',
        );
        // Fixed path: overwrite in place (no __bytes_{digest}/ accumulation).
        $compiledPath = $baseCompiledPath;
        $this->compiledSourceOrigins[$compiledPath] = $originPath;
        $force = Env::getInstance()->getConfig('template.force_recompile_in_dev', false);
        if (is_file($compiledPath) && !(DEV && in_array($force, [true, 1, '1'], true))) {
            if ($this->compiledFileMatchesIdentity($compiledPath, $compileId)) {
                return $compiledPath;
            }
        }

        $content = '';
        foreach (token_get_all($sourceBytes) as $token) {
            if (is_array($token)) {
                $content .= match ($token[0]) {
                    T_DIR => var_export(dirname($originPath), true),
                    T_FILE => var_export($originPath, true),
                    default => $token[1],
                };
            } else {
                $content .= $token;
            }
        }
        // 影子编译（默认关）：必须在 tmp_replace 之前留副本，因为旧 compile() 的
        // $content 是按引用传递的，会在内联标签展开时就地改写。
        $shadowSource = null;
        if (!$useV3) {
            try {
                if (\class_exists(\Weline\Framework\View\Compiler\Shadow\ShadowHook::class)
                    && \Weline\Framework\View\Compiler\Shadow\ShadowHook::isEnabled()) {
                    $shadowSource = $content;
                }
            } catch (\Throwable) {
                $shadowSource = null;
            }
        }

        $previousOrigin = $this->activeSourceOrigin;
        $this->activeSourceOrigin = $originPath;
        try {
            if ($useV3) {
                try {
                    $compiled = \Weline\Framework\View\Compiler\V3Compiler::compile($content, $originPath, $this);
                } catch (\Throwable $exception) {
                    // 灰度安全：v3 失败回退旧链，不影响请求。
                    \w_log_error('[template-v3] fallback ' . $originPath . ': ' . $exception->getMessage());
                    $compiled = (string)$this->tmp_replace($content, $originPath);
                }
            } else {
                $compiled = (string)$this->tmp_replace($content, $originPath);
            }
        } finally {
            $this->activeSourceOrigin = $previousOrigin;
        }
        if ($shadowSource !== null) {
            $this->shadowCompare($shadowSource, $originPath, $compiled);
        }
        $showComments = Env::getInstance()->getConfig('template.show_comments', false);
        if (!in_array($showComments, [true, 1, '1'], true)) {
            $compiled = (string)preg_replace('/\<!--(?!@\/?weline-slot:)([\s\S]*?)-->/', '', $compiled);
        }
        $eventData = new DataObject(['content' => $compiled, 'comFileName' => $compiledPath, 'tplFile' => $originPath, 'template' => $this]);
        $this->eventsManager->dispatch('Weline_Framework_Template::after_compile', $eventData);
        $compiled = (string)$eventData->getData('content');
        $marker = '/* compile_id:' . $compileId . ' */ /* hash:' . $sourceHash . ' */';
        $compiled = str_starts_with($compiled, '<?php')
            ? '<?php ' . $marker . substr($compiled, 5)
            : '<?php ' . $marker . ' ?>' . $compiled;
        // Production: collapse HTML whitespace / strip safe PHP comments in com_*.
        $compiled = CompiledTemplateMinifier::minify($compiled);
        $this->assertCompiledPhp($compiled, $originPath);
        $directory = dirname($compiledPath);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new Exception('Failed to create template compile directory: ' . $directory);
        }
        $temporary = tempnam($directory, '.compile-');
        if ($temporary === false) {
            throw new Exception('Failed to stage compiled template: ' . $compiledPath);
        }
        try {
            if (file_put_contents($temporary, $compiled) !== strlen($compiled) || !rename($temporary, $compiledPath)) {
                throw new Exception('Failed to publish compiled template: ' . $compiledPath);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        // Fixed-path compiles overwrite in place, so a worker that already included the
        // previous generation would keep executing its cached bytecode. opcache only
        // re-reads when validate_timestamps is on; production usually turns it off.
        // Mirrors AtomicCompiledFilePublisher::publish().
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($compiledPath, true);
        }
        clearstatcache(true, $compiledPath);

        return $compiledPath;
    }

    /**
     * 坏产物一旦落盘，compile_id 命中后不会被重编，整页会永久 500。
     * 发布前用 PHP 自己的解析器验一遍，编不出来就当场报出模板名，而不是留个坏文件。
     */
    private function assertCompiledPhp(string $compiled, string $originPath): void
    {
        try {
            \token_get_all($compiled, \TOKEN_PARSE);
        } catch (\ParseError $parseError) {
            if (\function_exists('w_log_error')) {
                \w_log_error('[template-compile] invalid php ' . $originPath . ': ' . $parseError->getMessage());
            }
            throw new Exception(
                __('模板编译结果不是合法 PHP：%{1}（%{2}）', [$originPath, $parseError->getMessage()])
            );
        }
    }

    /**
     * 影子比对：只读旧产物、只写 var/compile/shadow 差异；任何失败都不影响编译。
     */
    private function shadowCompare(string $source, string $originPath, string $legacyCompiled): void
    {
        try {
            \Weline\Framework\View\Compiler\Shadow\ShadowHook::compare(
                $source,
                $originPath,
                $legacyCompiled,
                $this,
            );
        } catch (\Throwable $exception) {
            if (\function_exists('w_log_error')) {
                \w_log_error('[template-shadow] ' . $originPath . ': ' . $exception::class . ': ' . $exception->getMessage());
            }
        }
    }

    private function buildCompileIdentity(string $sourceHash, string $originPath, string $contextKey, string $pipeline, string $engineMode = ''): string
    {
        $hooks = self::hooksRegistryCompileDigest();
        $pipeDigest = \substr(\hash('sha256', $pipeline), 0, 16);
        $ctxDigest = \substr(\hash('sha256', $originPath . "\0" . $contextKey), 0, 12);
        $engine = $engineMode !== '' ? "\0" . $engineMode : '';

        // Compact, filesystem-safe token embedded in the compiled file header.
        return \substr(\hash('sha256', $sourceHash . "\0" . $hooks . "\0" . $pipeDigest . "\0" . $ctxDigest . "\0v7-inplace" . $engine), 0, 40);
    }

    private function compiledFileMatchesIdentity(string $compiledPath, string $compileId): bool
    {
        $head = @\file_get_contents($compiledPath, false, null, 0, 512);
        if (!\is_string($head) || $head === '') {
            return false;
        }
        if (\preg_match('/\/\*\s*compile_id:([a-f0-9]{16,64})\s*\*\//i', $head, $match) !== 1) {
            return false;
        }

        return \hash_equals($compileId, (string)$match[1]);
    }

    private function templateSourceModule(): array
    {
        $normalized = str_replace('\\', '/', $this->activeSourceOrigin);
        if ($normalized !== '') {
            foreach (Env::getInstance()->getModuleList() as $name => $module) {
                $base = rtrim(str_replace('\\', '/', (string)($module['base_path'] ?? '')), '/');
                if ($base !== '' && str_starts_with($normalized, $base . '/view/')) {
                    return [(string)$name, $base . '/'];
                }
            }
        }
        return [$this->getRequest()->getModuleName(), $this->getRequest()->getModulePath()];
    }

    /**
     * Determine if compiled template needs recompilation
     *
     * Uses multi-factor validation for maximum accuracy:
     * 1. Content hash (MD5 + size) - primary check, cross-platform reliable
     * 2. Mtime fallback - for rapid dev iteration
     * 3. Force flag - for explicit recompile requests
     *
     * @param string $compiledFile Path to compiled template
     * @param string $templateFile Path to source template
     * @param bool $isDev Whether in development mode
     * @param mixed $forceRecompileInDev Force recompile in dev (true/1/'1'/[hash])
     * @return bool True if needs recompilation
     */
    public static function shouldRecompileCompiledTemplate(
        string $compiledFile,
        string $templateFile,
        bool $isDev = false,
        mixed $forceRecompileInDev = false
    ): bool {
        // Factor 1: Compiled file doesn't exist
        if (!file_exists($compiledFile)) {
            return true;
        }

        if ($isDev && ($forceRecompileInDev === true || $forceRecompileInDev === 1 || $forceRecompileInDev === '1')) {
            return true;
        }

        $templateMtime = (int)@filemtime($templateFile);
        $templateSize = (int)@filesize($templateFile);
        $compiledMtime = (int)@filemtime($compiledFile);
        $compiledSize = (int)@filesize($compiledFile);
        if ($templateMtime <= 0 || $compiledMtime <= 0 || $templateSize < 0 || $compiledSize < 0) {
            return true;
        }

        $forceCacheKey = is_scalar($forceRecompileInDev) ? (string)$forceRecompileInDev : gettype($forceRecompileInDev);
        $freshnessCacheKey = $compiledFile . '|' . $templateFile . '|' . ($isDev ? '1' : '0') . '|' . $forceCacheKey;
        $cachedFreshness = self::$compiledTemplateFreshnessCache[$freshnessCacheKey] ?? null;
        if (is_array($cachedFreshness)
            && $cachedFreshness['compiled_mtime'] === $compiledMtime
            && $cachedFreshness['compiled_size'] === $compiledSize
            && $cachedFreshness['template_mtime'] === $templateMtime
            && $cachedFreshness['template_size'] === $templateSize
            && $cachedFreshness['force'] === $forceCacheKey) {
            return $cachedFreshness['result'];
        }

        $rememberFreshness = static function (bool $result) use (
            $freshnessCacheKey,
            $compiledMtime,
            $compiledSize,
            $templateMtime,
            $templateSize,
            $forceCacheKey
        ): bool {
            if (\count(self::$compiledTemplateFreshnessCache) > 4096) {
                self::$compiledTemplateFreshnessCache = [];
            }

            self::$compiledTemplateFreshnessCache[$freshnessCacheKey] = [
                'compiled_mtime' => $compiledMtime,
                'compiled_size' => $compiledSize,
                'template_mtime' => $templateMtime,
                'template_size' => $templateSize,
                'force' => $forceCacheKey,
                'result' => $result,
            ];

            return $result;
        };

        // Factor 2: Content hash validation (precise, cross-platform).
        // The enhanced TemplateCacheManager stores a second compiled copy under
        // var/cache/template, but this method returns whether the per-locale
        // com_*.phtml file itself is fresh. Do not let an enhanced-cache hit
        // mask a stale compiled file in the language directory.
        $currentHash = \md5(
            (string)\md5_file($templateFile)
            . '|'
            . $templateSize
            . '|'
            . \Weline\Framework\View\Taglib::COMPILER_GENERATION
        ) . '-' . $templateSize;

        // Check for embedded hash in compiled file (our new format)
        $compiledContent = @file_get_contents($compiledFile);
        if ($compiledContent !== false && str_starts_with($compiledContent, "<?php /* hash:")) {
            // Extract hash from compiled file header
            if (preg_match('/^\<\?php \/\* hash:([a-f0-9]+-\d+) \*\//', $compiledContent, $matches)) {
                $embeddedHash = $matches[1];
                if ($embeddedHash === $currentHash) {
                    // Content hash matches - cache is valid
                    return $rememberFreshness(false);
                }
                // Content changed - needs recompile
                return $rememberFreshness(true);
            }
        }

        // Factor 3: Mtime fallback (for backward compatibility and dev rapid iteration)
        // Check the active per-locale compiled file before consulting the auxiliary cache.
        if ($compiledMtime < $templateMtime) {
            return $rememberFreshness(true);
        }

        // Factor 4: Try TemplateCacheManager as an auxiliary positive signal
        // only after the active compiled file has proven it has no embedded
        // hash and is not older than the source template. This keeps legacy
        // compiled files fast without masking stale view/tpl output.
        try {
            $cacheManager = TemplateCacheManager::getInstance();
            $cachedFile = $cacheManager->getCachedFile($templateFile, $isDev);
            if ($cachedFile !== null && is_file($cachedFile)) {
                return $rememberFreshness(false);
            }
        } catch (\Throwable) {
            // CacheManager unavailable, fall through to mtime check
        }

        // Factor 5: Production mode - content hash is authoritative
        if (!$isDev) {
            // Production: mtime passed but content might differ
            // Trust mtime only if we don't have content hash mismatch above
            return $rememberFreshness(false);
        }

        // Factor 6: Explicit force recompile in dev mode
        if ($forceRecompileInDev !== false) {
            // If it's a hash string, only recompile if hash differs
            if (is_string($forceRecompileInDev) && $forceRecompileInDev !== $currentHash) {
                return $rememberFreshness(true);
            }
        }

        return $rememberFreshness(false);
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    /**
     * Preferred engine entry (resolve logical → compiled path).
     */
    public function resolve(string $logical): string
    {
        return $this->getFetchFile($logical);
    }

    /**
     * Include into the current capture frame (list hot path).
     *
     * @param array<string, mixed> $data
     */
    public function include(string $logicalOrCompiled, array $data = []): void
    {
        ViewEngine::for($this)->include($logicalOrCompiled, $data);
    }

    /**
     * Capture a template to string (always nested-safe via fetchHtml).
     *
     * @param array<string, mixed> $data
     */
    public function capture(string $logical, array $data = []): string
    {
        return ViewEngine::for($this)->capture($logical, $data);
    }

    /**
     * ViewEngine host helpers (public for Engine package; not general API).
     */
    public function shortEngineRouteIdentity(string $filename): string
    {
        return $this->shortRenderRouteIdentity($filename);
    }

    public function addSourceModuleForEngine(string $fileName): void
    {
        $this->addSourceModuleToRequest(DataInterface::dir_type_TEMPLATE, $fileName);
    }

    /**
     * Render a template.
     *
     * Nested (already inside FiberOutputBuffer capture): stream into the current
     * frame with no nested capture and return '' — for `<?= $this->fetch() ?>`.
     * Top-level / explicit string needs: use {@see fetchHtml} (always captures).
     * When {@see EngineFlags::isV2Enabled()} (default), delegates to {@see ViewEngine}.
     *
     * @param array<string, mixed> $data
     */
    public function fetch(string $fileName, array $data = [])
    {
        if (EngineFlags::isV2Enabled()) {
            return ViewEngine::for($this)->fetch($fileName, $data);
        }

        return $this->fetchLegacy($fileName, $data);
    }

    /**
     * Legacy fetch path (rollback when view.engine.v2=false).
     *
     * @param array<string, mixed> $data
     */
    private function fetchLegacy(string $fileName, array $data = [])
    {
        if (FiberOutputBuffer::hasActiveCapture()) {
            // Identity costs ~1.1 µs (three regexes); only build it when the route
            // trace is actually on, and reuse it for the matching end() call.
            $routeIdentity = TemplateRenderRouteTrace::isRequested()
                ? $this->shortRenderRouteIdentity($fileName)
                : '';
            TemplateRenderRouteTrace::armIfRequested();
            TemplateRenderRouteTrace::begin('fetch_stream', $routeIdentity);
            try {
                $this->addSourceModuleToRequest(DataInterface::dir_type_TEMPLATE, $fileName);
                $this->streamCompiledFile($this->getFetchFile($fileName), $data);

                return '';
            } finally {
                TemplateRenderRouteTrace::end('fetch_stream', $routeIdentity);
            }
        }

        return $this->fetchHtml($fileName, $data);
    }

    public function fetchModuleThemeHtml(string $fileName, array $data = []): string
    {
        $previous = $this->getData(self::DATA_FORCE_MODULE_THEME_SOURCE);
        $this->setData(self::DATA_FORCE_MODULE_THEME_SOURCE, true);
        try {
            $comFileName = $this->getFetchFile($fileName);
        } finally {
            if ($previous === null) {
                $this->unsetData(self::DATA_FORCE_MODULE_THEME_SOURCE);
            } else {
                $this->setData(self::DATA_FORCE_MODULE_THEME_SOURCE, $previous);
            }
        }

        return $this->ob_file($comFileName, $data);
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    public function fetchHtml(string $fileName, array $dictionary = []) 
    {
        $routeIdentity = TemplateRenderRouteTrace::isRequested()
            ? $this->shortRenderRouteIdentity($fileName)
            : '';
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('fetch_html', $routeIdentity);
        try {
            $this->addSourceModuleToRequest(DataInterface::dir_type_TEMPLATE, $fileName);

            $comFileName = $this->getFetchFile($fileName);
            try {
                $result = $this->ob_file($comFileName, $dictionary);
            } catch (\ParseError $parseError) {
                // 历史/并发写坏的编译产物：删掉重编一次，别让整页永久 500。
                if (\function_exists('w_log_error')) {
                    \w_log_error('[template-compiled-corrupt] ' . $comFileName . ': ' . $parseError->getMessage());
                }
                self::forgetCompiledArtifact($comFileName);
                $comFileName = $this->getFetchFile($fileName);
                $result = $this->ob_file($comFileName, $dictionary);
            }
            self::assertDocumentIntegrityInDev($result, $fileName);

            return $result;
        } finally {
            TemplateRenderRouteTrace::end('fetch_html', $routeIdentity);
        }
    }

    /**
     * Dev-only hard stop for truncated documents (theme-spec-gates S10).
     *
     * Production refuses to cache such bodies in FpcHtmlDiskStore; in dev the point is to
     * fail loudly at the render site instead of shipping a 200 with no <body>. Fragments
     * are untouched: the check only runs when the output actually starts a document.
     */
    private static function assertDocumentIntegrityInDev($result, string $fileName): void
    {
        if (!\defined('DEV') || !DEV || !\is_string($result) || $result === '') {
            return;
        }
        if (\stripos($result, '<html') === false && \stripos($result, '<!DOCTYPE') === false) {
            return;
        }
        $issue = \Weline\Framework\View\Helper\HtmlCacheAdmission::documentIntegrityIssue($result);
        if ($issue === '') {
            return;
        }
        throw new \RuntimeException(
            'Incomplete document from template "' . $fileName . '" (' . $issue . ', bytes=' . \strlen($result)
            . '). The page was cut off before </body></html>. Fix the render/first exit()/die() in the '
            . 'layout chain; FPC would otherwise cache a head-only leaf.'
        );
    }

    private function addSourceModuleToRequest(string $type, string $source): void
    {
        try {
            [, $moduleName] = $this->processModuleSourceFilePath($type, $source);
            if ($moduleName !== '') {
                ObjectManager::getInstance(Request::class)->addModule($moduleName);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * 丢弃一份不可用的编译产物：删文件并清掉所有「它还新鲜」的进程缓存，
     * 让下一次 getFetchFile() 真正重新编译。
     */
    private static function forgetCompiledArtifact(string $compiledPath): void
    {
        @\unlink($compiledPath);
        unset(self::$compiledTemplateFreshnessCache[$compiledPath], self::$fetchFileIdentityMemo[$compiledPath]);
        foreach (self::$processViewFileCache as $key => $entry) {
            if (($entry['compiled'] ?? '') === $compiledPath) {
                unset(self::$processViewFileCache[$key]);
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($compiledPath, true);
        }
        \clearstatcache(true, $compiledPath);
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    public function fetchTagHtml(string $tag, string $fileName, array $dictionary = [])
    {
        $this->addSourceModuleToRequest($tag, $fileName);

        $comFileName = $this->fetchTagSource($tag, $fileName);
        return $this->ob_file($comFileName, $dictionary);
    }

    private function fetchHookHtml(string $hookFile): string
    {
        TemplateRenderRouteTrace::armIfRequested();
        $this->addSourceModuleToRequest('hooks', $hookFile);

        $templateCachePolicies = self::templateCachePolicies();
        $cachePolicy = $templateCachePolicies->output($hookFile);
        if ($cachePolicy === null) {
            TemplateRenderRouteTrace::mark('hook', $this->shortRenderRouteIdentity($hookFile), ['policy' => 'none']);
            return $this->fetchTagHtml('hooks', $hookFile);
        }
        $renderOnceGroup = \trim((string)($cachePolicy['render_once_group'] ?? ''));
        if ($renderOnceGroup !== '' && $this->isTemplateCacheRenderOnceComplete($renderOnceGroup)) {
            TemplateRenderRouteTrace::mark(
                'hook_render_once_skip',
                $this->shortRenderRouteIdentity($hookFile),
                ['group' => $renderOnceGroup]
            );
            return '';
        }

        $cacheContext = $this->resolveTemplateCachePolicyContext($cachePolicy, $hookFile);
        if ($cacheContext === null) {
            return $this->fetchTagHtml('hooks', $hookFile);
        }

        $compiledFile = $this->fetchTagSource('hooks', $hookFile);
        $stat = @stat($compiledFile);
        if (!\is_array($stat)) {
            return $this->ob_file($compiledFile);
        }

        $ttl = \Weline\Framework\View\Cache\TemplateFragmentOutputCache::resolveTtl(
            $cachePolicy,
            $this->staticHookOutputCacheTtl(),
        );
        // Hook HTML: system KeyBuilder env scope (lang/currency/website/origin).
        // area_route=false so PDP URLs do not shard one chrome blob per product.
        // Logical payload only — do not hand-roll sha1(prefix|environmentHash).
        $cacheKey = KeyBuilder::buildEnvironmentScoped(
            'hook.output',
            $hookFile . '|' . (int)$stat['mtime'] . '|' . (int)$stat['size'],
            [
                'cache_version' => '20261013-hook-env-scoped-v2',
                'hook_context' => $cacheContext,
                'policy_digest' => $templateCachePolicies->digest(),
                'ttl' => $ttl,
            ],
            [
                'area_route' => false,
            ],
        );
        $cached = $this->readStaticHookOutputCache($cacheKey, $ttl);
        if ($cached['status'] !== 'miss') {
            if ($this->isEmptyCacheHtml((string)$cached['html'])) {
                unset(self::$staticHookOutputCache[$cacheKey]);
                $this->logHookCacheDiagnostic('skip_empty_static_hook_output_cache', [
                    'hook_file' => $hookFile,
                    'cache_key' => $cacheKey,
                    'status' => (string)$cached['status'],
                ]);
            } else {
            if ($cached['status'] === 'stale' && ($cachePolicy['allow_async_refresh'] ?? false)) {
                $this->queueStaticHookOutputRefresh($cacheKey, $compiledFile, $ttl);
            }
            $this->markTemplateCacheRenderOnceComplete($renderOnceGroup);
            return (string)$cached['html'];
            }
        }

        $runtimeCached = $this->readRuntimeHookCache($cacheKey, $ttl);
        if ($runtimeCached['status'] !== 'miss') {
            if ($this->isEmptyCacheHtml((string)$runtimeCached['html'])) {
                self::runtimeHookCacheDelete($cacheKey);
                $this->logHookCacheDiagnostic('skip_empty_runtime_hook_output_cache', [
                    'hook_file' => $hookFile,
                    'cache_key' => $cacheKey,
                    'status' => (string)$runtimeCached['status'],
                ]);
            } else {
            $this->rememberStaticHookOutput($cacheKey, (string)$runtimeCached['html'], $ttl, (string)$runtimeCached['status']);
            if ($runtimeCached['status'] === 'stale' && ($cachePolicy['allow_async_refresh'] ?? false)) {
                $this->queueStaticHookOutputRefresh($cacheKey, $compiledFile, $ttl);
            }
            $this->markTemplateCacheRenderOnceComplete($renderOnceGroup);
            return (string)$runtimeCached['html'];
            }
        }

        $html = $this->ob_file($compiledFile);
        if ($this->isEmptyCacheHtml($html)) {
            $this->logHookCacheDiagnostic('skip_empty_static_hook_output_store', [
                'hook_file' => $hookFile,
                'cache_key' => $cacheKey,
            ]);
            return $html;
        }
        if (HtmlCacheAdmission::admit($html)) {
            $this->rememberStaticHookOutput($cacheKey, $html, $ttl);
            self::runtimeHookCacheSet($cacheKey, $html, $ttl);
        }
        $this->markTemplateCacheRenderOnceComplete($renderOnceGroup);

        return $html;
    }

    private function rememberStaticHookOutput(string $cacheKey, string $html, int $ttl, string $status = 'fresh'): void
    {
        // Soft LRU: drop oldest half when over cap instead of wiping the whole map
        // (full wipe caused thrash + re-allocation spikes under same-locale PDP crawls).
        $cap = 64;
        if (\count(self::$staticHookOutputCache) >= $cap && !isset(self::$staticHookOutputCache[$cacheKey])) {
            $drop = (int)\max(1, (int)\floor($cap / 2));
            self::$staticHookOutputCache = \array_slice(self::$staticHookOutputCache, $drop, null, true);
        }

        self::$staticHookOutputCache[$cacheKey] = $this->makeStaticSwrEntry($html, $ttl, $status);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readStaticHookOutputCache(string $cacheKey, int $ttl): array
    {
        $entry = $this->normalizeStaticSwrEntry(self::$staticHookOutputCache[$cacheKey] ?? null, $ttl);
        if ($entry === null) {
            unset(self::$staticHookOutputCache[$cacheKey]);
            return ['status' => 'miss', 'html' => null];
        }
        // Touch for soft LRU (newest at end; remember drops from the front).
        unset(self::$staticHookOutputCache[$cacheKey]);
        self::$staticHookOutputCache[$cacheKey] = $entry;

        return $this->staticSwrEntryStatus($entry);
    }

    private function queueStaticHookOutputRefresh(string $cacheKey, string $compiledFile, int $ttl): void
    {
        // Drain already restored Scope; nested enqueue would re-capture and cascade.
        if (PostResponseTaskQueue::isDraining()) {
            return;
        }
        if (!$this->acquireRuntimeHookRefreshLock($cacheKey)) {
            return;
        }

        // SCOPE: cache key precomputed; re-render under restored website/lang/origin pins.
        PostResponseTaskQueue::enqueue(
            'theme-hook-output:' . $cacheKey,
            function () use ($cacheKey, $compiledFile, $ttl): void {
                $html = $this->ob_file($compiledFile);
                if (!\is_string($html)) {
                    return;
                }
                if ($this->isEmptyCacheHtml($html)) {
                    $this->logHookCacheDiagnostic('skip_empty_static_hook_output_refresh', [
                        'cache_key' => $cacheKey,
                        'compiled_file' => $compiledFile,
                    ]);
                    return;
                }
                if (!HtmlCacheAdmission::admit($html)) {
                    return;
                }
                $this->rememberStaticHookOutput($cacheKey, $html, $ttl);
                self::runtimeHookCacheSet($cacheKey, $html, $ttl);
            },
            0.0,
            PostResponseCaptureMode::Scope,
        );
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function resolveTemplateCachePolicyContext(array $policy, string $subject): ?string
    {
        return match ((string)($policy['context'] ?? '')) {
            'static' => 'static',
            'frontend_auth' => $this->frontendAuthStaticHookCacheContext(),
            'body_end' => $this->bodyEndStaticHookCacheContext(),
            'account_sidebar' => $this->accountSidebarStaticHookCacheContext(),
            'header_action' => $this->headerActionStaticHookCacheContext($subject),
            'i18n' => $this->resolveI18nTemplateCachePolicyContext($policy),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function resolveI18nTemplateCachePolicyContext(array $policy): ?string
    {
        $eventName = \trim((string)($policy['event'] ?? ''));
        $scope = \trim((string)($policy['scope'] ?? ''));
        if ($eventName === '' || $scope === '') {
            return null;
        }

        return $this->i18nStaticHookCacheContext($eventName, $scope);
    }

    private function isTemplateCacheRenderOnceComplete(string $group): bool
    {
        return RequestContext::get('view.hook.render_once.' . $group) === true;
    }

    private function markTemplateCacheRenderOnceComplete(string $group): void
    {
        if ($group !== '') {
            RequestContext::set('view.hook.render_once.' . $group, true);
        }
    }

    /**
     * Storefront public HTML is always guest-safe. Login chrome is client-side
     * (Account.js); never shard static-hook / reusable-header caches by Session.
     */
    private function frontendAuthStaticHookCacheContext(): ?string
    {
        $requestCacheKey = 'view.static_hook.frontend_auth_context';
        $cached = RequestContext::get($requestCacheKey);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        $context = 'frontend-auth:0';
        RequestContext::set($requestCacheKey, $context);
        return $context;
    }

    private function bodyEndStaticHookCacheContext(): ?string
    {
        try {
            $requestPath = $this->requestPathForCacheGate();
            if ($this->isAccountAuthRequestPath($requestPath)) {
                return null;
            }

            $isWorkspacePreview = (string)$this->request->getGet('visual_editor', '') === '1'
                || (string)$this->request->getGet('preview', '') === '1'
                || \str_contains($requestPath, 'workspace-preview');
            if ($isWorkspacePreview) {
                return null;
            }

            $authContext = $this->frontendAuthStaticHookCacheContext();
            if ($authContext === null) {
                return null;
            }

            // Shard by account-area / i18n-inline / theme so Visitor pixel skip,
            // I18n inline shell, and theme-bound boot never poison one bag.
            // Logical discriminator only — outer hook.output buildEnvironmentScoped
            // applies lang/currency/website. Do not nest environmentHash here.
            return 'body-end|auth:' . $authContext
                . '|acct:' . ($this->isCustomerAccountAreaPath($requestPath) ? '1' : '0')
                . '|i18n:' . ($this->isI18nInlineEditActiveForBodyEndCache() ? '1' : '0')
                . '|theme:' . $this->frontendThemeIdForBodyEndCache();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAccountAuthRequestPath(string $requestPath): bool
    {
        return \preg_match('#(^|/)customer/account/(login|register|forgot-password|challenge|logout)(/|$)#', $requestPath) === 1;
    }

    /** Account chrome area (not only login/register) — aligns Visitor body-end skip. */
    private function isCustomerAccountAreaPath(string $requestPath): bool
    {
        $path = \strtolower(\trim($requestPath, '/'));
        return $path === 'customer/account'
            || \str_starts_with($path, 'customer/account/');
    }

    private function isI18nInlineEditActiveForBodyEndCache(): bool
    {
        try {
            if (!\class_exists(\Weline\I18n\Service\InlineEdit\InlineEditSession::class)) {
                return false;
            }
            /** @var \Weline\I18n\Service\InlineEdit\InlineEditSession $session */
            $session = ObjectManager::getInstance(\Weline\I18n\Service\InlineEdit\InlineEditSession::class);

            return $session->isActive($this->request);
        } catch (\Throwable) {
            return false;
        }
    }

    private function frontendThemeIdForBodyEndCache(): string
    {
        try {
            $app = \Weline\Framework\Runtime\ThemeApplicationContext::current('frontend', 'runtime')
                ?? \Weline\Framework\Runtime\ThemeApplicationContext::current('frontend');
            if ($app !== null && isset($app->themeId) && (int)$app->themeId >= 0) {
                return (string)(int)$app->themeId;
            }
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $theme = \Weline\Theme\Helper\ThemeData::getCurrentTheme();
                if ($theme !== null && \method_exists($theme, 'getId')) {
                    return (string)(int)$theme->getId();
                }
            }
        } catch (\Throwable) {
        }

        return '0';
    }

    private function i18nStaticHookCacheContext(string $eventName, string $type): ?string
    {
        if ($this->hasEventObservers($eventName)) {
            return null;
        }

        try {
            // Language switcher HTML embeds path-local hrefs. Process-caching it
            // either (a) shards one blob per URL or (b) reuses wrong links.
            // Frontend LanguageSwitcher already skips process htmlCache; skip here too.
            if ($type === 'language') {
                return null;
            }

            // Logical only; outer hook.output / aggregate keys apply env scope.
            // Currency switcher still varies via default currency dim on the outer key.
            return $type . '|scope:' . $eventName;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasEventObservers(string $eventName): bool
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            return $eventsManager->getEventObservers($eventName) !== [];
        } catch (\Throwable) {
            return true;
        }
    }

    public function ob_file(string $filename, array $dictionary = []): string
    {
        $routeIdentity = TemplateRenderRouteTrace::isRequested()
            ? $this->shortRenderRouteIdentity($filename)
            : '';
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('ob_file', $routeIdentity);
        try {
            return $this->obFileInner($filename, $dictionary);
        } finally {
            TemplateRenderRouteTrace::end('ob_file', $routeIdentity);
        }
    }

    /**
     * Include compiled PHP into the current Fiber capture frame (no nested beginCapture).
     *
     * @param array<string, mixed> $dictionary
     */
    /**
     * Stream a compiled template into the open capture (or echo via ob_file).
     * Public for {@see ViewEngine}; prefer {@see include()} / {@see fetch()} from templates.
     *
     * @param array<string, mixed> $dictionary
     */
    public function streamCompiledFile(string $filename, array $dictionary = []): void
    {
        if (!FiberOutputBuffer::hasActiveCapture()) {
            echo $this->ob_file($filename, $dictionary);

            return;
        }

        $routeIdentity = TemplateRenderRouteTrace::isRequested()
            ? $this->shortRenderRouteIdentity($filename)
            : '';
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('stream_file', $routeIdentity);
        $temporaryTemplateData = $this->pushTemporaryTemplateData($dictionary);
        $previousSourceOrigin = $this->activeSourceOrigin;
        $this->activeSourceOrigin = $this->compiledSourceOrigins[$filename] ?? '';
        $watchdogStartNs = Runtime::isPersistent() ? \hrtime(true) : 0;
        try {
            $this->softBindRequestForNestedRender();
            $block = $this;
            $this->setData('block', $this);
            $extractData = $this->getData();
            if (\is_array($extractData) && $extractData !== []) {
                $extractData = EmbeddedPageTitle::sanitizePublicSlots($extractData);
                if (\array_key_exists('title', $extractData)) {
                    parent::setData('title', $extractData['title']);
                }
                if (isset($extractData['meta']) && \is_array($extractData['meta'])) {
                    parent::setData('meta', $extractData['meta']);
                }
                extract($extractData, EXTR_SKIP);
            }
            self::cooperativeTemplateYield();
            include $filename;
            self::cooperativeTemplateYield();
        } finally {
            $this->recordTemplateRenderWatchdog($filename, $watchdogStartNs);
            $this->popTemporaryTemplateData($temporaryTemplateData);
            $this->activeSourceOrigin = $previousSourceOrigin;
            TemplateRenderRouteTrace::end('stream_file', $routeIdentity);
        }
    }

    /**
     * Observable-only guard: a CPU-bound template include that never yields can
     * starve the Fiber scheduler, so other Fibers' I/O deadlines are not checked
     * until it returns. Never changes rendering behavior.
     */
    private function recordTemplateRenderWatchdog(string $filename, int $startNs): void
    {
        if ($startNs <= 0) {
            return;
        }
        $elapsedMs = (\hrtime(true) - $startNs) / 1_000_000;
        if ($elapsedMs < 1000.0) {
            return;
        }
        try {
            $requestId = RequestContext::getId() ?? '';
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            \w_log_warning(
                '[WLS] template_fiber_hog'
                . ' elapsed_ms=' . \round($elapsedMs, 2)
                . ' request_id=' . \substr((string)$requestId, 0, 128)
                . ' uri=' . \substr($uri, 0, 200)
                . ' file=' . \substr($filename, 0, 300),
                [],
                'wls_fiber_hog',
            );
        } catch (\Throwable) {
            // Observation must never alter render output.
        }
    }

    /**
     * Nested render bind: refresh request carriers without full init() (title wipe).
     */
    private function softBindRequestForNestedRender(): void
    {
        if (!$this->isRequestRuntime()) {
            return;
        }

        $this->request = ObjectManager::getInstance(Request::class);
        $context = Context::getCurrent();
        $carriers = $context?->get('view.template.init_carriers');
        if ($carriers === null) {
            $carriers = ['req' => new TemplateRequestView(), 'env' => new TemplateEnvView()];
            $context?->set('view.template.init_carriers', $carriers);
        }
        $this->setData('req', $carriers['req']);
        $this->setData('env', $carriers['env']);
        if (\trim((string)$this->getData('lang')) === '') {
            $this->initLanguage();
        }
    }

    private function obFileInner(string $filename, array $dictionary = []): string
    {
        $profileStart = \microtime(true);
        // Nested string capture (fetchHtml inside an open page frame): soft-bind only.
        // Top-level page/fragment capture still runs full init for request isolation.
        if (FiberOutputBuffer::hasActiveCapture()) {
            $this->softBindRequestForNestedRender();
        } else {
            // 每次顶层渲染都重新执行 init，确保请求级状态始终绑定当前请求。
            $this->init();
            // WLS swaps the request instance per incoming request. Refresh the legacy
            // `$this->request` reference so older templates using that property stay correct.
            $this->request = ObjectManager::getInstance(Request::class);
        }
        TraceGate::armTemplatePerfOverlayIfRequested();
        $ioBefore = TraceGate::isArmed()
            ? TraceGate::snapshotIoCounters()
            : ['db_duration_ms' => 0.0, 'db_span_count' => 0, 'wls_duration_ms' => 0.0, 'wls_span_count' => 0];
        $profileInitEnd = \microtime(true);
        $reusableTemplateCacheKey = $this->buildReusableTemplateOutputCacheKey($filename, $dictionary);
        if ($reusableTemplateCacheKey !== null) {
            if ($this->shouldAnnotateTemplateRenderOverlay()) {
                $reusableTemplateCacheKey = null;
            }
        }
        if ($reusableTemplateCacheKey !== null) {
            $cachedTemplate = $this->readReusableTemplateOutputCache(
                $reusableTemplateCacheKey,
                self::REUSABLE_TEMPLATE_OUTPUT_CACHE_TTL
            );
            if ($cachedTemplate['status'] !== 'miss' && \is_string($cachedTemplate['html'])) {
                $profileEnd = \microtime(true);
                $totalMs = ($profileEnd - $profileStart) * 1000;
                $html = $cachedTemplate['html'];
                $io = $this->deltaTemplateIoCounters($ioBefore);
                $this->recordTemplateRenderProfile(
                    $filename,
                    ($profileInitEnd - $profileStart) * 1000,
                    0.0,
                    0.0,
                    $totalMs,
                    \strlen($html),
                    $io
                );
                TemplateRenderRouteTrace::mark('ob_file_cache_hit', $this->shortRenderRouteIdentity($filename));

                return $this->annotateTemplateRenderOverlay($html, $filename, $totalMs, \strlen($html), $io);
            }
        }
        $traceAccountSidebar = $this->shouldTraceAccountSidebarTemplate($filename);
        if ($traceAccountSidebar) {
            $this->logAccountSidebarTemplateTrace('template_ob_begin', [
                'filename' => $filename,
                'exists' => \is_file($filename),
                'size' => \is_file($filename) ? (int)@filesize($filename) : 0,
                'template_id' => \spl_object_id($this),
                'buffer' => FiberOutputBuffer::debugState(),
            ]);
        }
        FiberOutputBuffer::beginCapture();
        $temporaryTemplateData = $this->pushTemporaryTemplateData($dictionary);
        // Child dictionaries may carry the framework's internal locale form
        // (for example ar_SA). Keep that value for data-lang, but never let it
        // override the BCP 47 value exposed as the document html lang.
        $htmlLang = \trim((string)$this->getData('htmlLang'));
        if ($htmlLang === '') {
            $htmlLang = (string)WelineEnv::getLang();
        }
        $htmlLang = \str_replace('_', '-', $htmlLang);
        $this->setData('htmlLang', $htmlLang);
        $captureEnding = false;
        $previousSourceOrigin = $this->activeSourceOrigin;
        $this->activeSourceOrigin = $this->compiledSourceOrigins[$filename] ?? '';
        try {
            // 框架级保障：模板内 $block 永远指向当前 Template 实例。
            // 兼容历史模板（含 view/tpl 编译产物）中的 $block->setTitle()/getBackendUrl() 调用。
            $block = $this;
            $this->setData('block', $this);
            # 将数组存储的变量散列到当前页内存中，使得变量可在页面中暴露出来（可直接使用）
            $extractData = $this->getData();
            if (\is_array($extractData) && $extractData !== []) {
                $extractData = EmbeddedPageTitle::sanitizePublicSlots($extractData);
                // Keep Template bag aligned with what layouts extract (public slots only).
                if (\array_key_exists('title', $extractData)) {
                    parent::setData('title', $extractData['title']);
                }
                if (isset($extractData['meta']) && \is_array($extractData['meta'])) {
                    parent::setData('meta', $extractData['meta']);
                }
                extract($extractData, EXTR_SKIP);
            }
            self::cooperativeTemplateYield();
            if ($traceAccountSidebar) {
                $this->logAccountSidebarTemplateTrace('template_ob_before_include', [
                    'filename' => $filename,
                    'template_id' => \spl_object_id($this),
                    'buffer' => FiberOutputBuffer::debugState(),
                ]);
            }
            $profileIncludeStart = \microtime(true);
            include $filename;
            $profileIncludeEnd = \microtime(true);
            if ($traceAccountSidebar) {
                $this->logAccountSidebarTemplateTrace('template_ob_after_include', [
                    'filename' => $filename,
                    'template_id' => \spl_object_id($this),
                    'buffer' => FiberOutputBuffer::debugState(),
                ]);
            }
            self::cooperativeTemplateYield();
            $captureEnding = true;
            /** Get output buffer. */
            $result = FiberOutputBuffer::endCapture();
        } catch (\Throwable $exception) {
            if (!$captureEnding) {
                FiberOutputBuffer::discardCapture();
            }
            throw $exception;
        } finally {
            $this->popTemporaryTemplateData($temporaryTemplateData);
            $this->activeSourceOrigin = $previousSourceOrigin;
        }
        if ($reusableTemplateCacheKey !== null
            && !$this->isEmptyCacheHtml($result)
            && HtmlCacheAdmission::admit($result)
        ) {
            $this->rememberReusableTemplateOutput(
                $reusableTemplateCacheKey,
                $result,
                self::REUSABLE_TEMPLATE_OUTPUT_CACHE_TTL
            );
        }
        $profileEnd = \microtime(true);
        $totalMs = ($profileEnd - $profileStart) * 1000;
        $io = $this->deltaTemplateIoCounters($ioBefore);
        $this->recordTemplateRenderProfile(
            $filename,
            ($profileInitEnd - $profileStart) * 1000,
            (($profileIncludeEnd ?? $profileEnd) - ($profileIncludeStart ?? $profileInitEnd)) * 1000,
            ($profileEnd - ($profileIncludeEnd ?? $profileInitEnd)) * 1000,
            $totalMs,
            \strlen($result),
            $io
        );
        if ($traceAccountSidebar) {
            $this->logAccountSidebarTemplateTrace('template_ob_end', [
                'filename' => $filename,
                'template_id' => \spl_object_id($this),
                'result_len' => \strlen($result),
                'has_sidebar_wrapper' => \str_contains($result, 'account-sidebar-wrapper'),
                'has_sidebar_hook_link' => \str_contains($result, 'account-hook-nav-link'),
                'buffer' => FiberOutputBuffer::debugState(),
            ]);
        }
        return $this->annotateTemplateRenderOverlay($result, $filename, $totalMs, \strlen($result), $io);
    }

    /**
     * @param array{db_duration_ms: float, db_span_count: int, wls_duration_ms: float, wls_span_count: int} $before
     * @return array{db_duration_ms: float, db_span_count: int, wls_duration_ms: float, wls_span_count: int, php_ms: float}
     */
    private function deltaTemplateIoCounters(array $before, ?float $totalMs = null): array
    {
        $after = TraceGate::isArmed()
            ? TraceGate::snapshotIoCounters()
            : ['db_duration_ms' => 0.0, 'db_span_count' => 0, 'wls_duration_ms' => 0.0, 'wls_span_count' => 0];
        $dbMs = \max(0.0, (float)$after['db_duration_ms'] - (float)$before['db_duration_ms']);
        $wlsMs = \max(0.0, (float)$after['wls_duration_ms'] - (float)$before['wls_duration_ms']);
        $dbCount = \max(0, (int)$after['db_span_count'] - (int)$before['db_span_count']);
        $wlsCount = \max(0, (int)$after['wls_span_count'] - (int)$before['wls_span_count']);

        return [
            'db_duration_ms' => \round($dbMs, 2),
            'db_span_count' => $dbCount,
            'wls_duration_ms' => \round($wlsMs, 2),
            'wls_span_count' => $wlsCount,
            'php_ms' => 0.0,
        ];
    }

    /**
     * @param array{db_duration_ms?: float, db_span_count?: int, wls_duration_ms?: float, wls_span_count?: int, php_ms?: float}|null $io
     * @return array{db_duration_ms: float, db_span_count: int, wls_duration_ms: float, wls_span_count: int, php_ms: float}
     */
    private function finalizeTemplateIoCounters(?array $io, float $totalMs): array
    {
        $dbMs = \round((float)($io['db_duration_ms'] ?? 0.0), 2);
        $wlsMs = \round((float)($io['wls_duration_ms'] ?? 0.0), 2);
        $phpMs = \round(\max(0.0, $totalMs - $dbMs - $wlsMs), 2);

        return [
            'db_duration_ms' => $dbMs,
            'db_span_count' => (int)($io['db_span_count'] ?? 0),
            'wls_duration_ms' => $wlsMs,
            'wls_span_count' => (int)($io['wls_span_count'] ?? 0),
            'php_ms' => $phpMs,
        ];
    }

    /**
     * DEV 诊断：在模板/部件 HTML 旁标注本段渲染耗时（query `wls_tpl_perf=1` 或 env 闸门）。
     * 嵌套模板各自打点，便于肉眼定位 PDP 冷路径最慢块；不改变业务 HTML 语义。
     */
    private function shouldAnnotateTemplateRenderOverlay(): bool
    {
        return TraceGate::isTemplatePerfOverlayRequested();
    }

    private function shortRenderRouteIdentity(string $filename): string
    {
        $path = \str_replace('\\', '/', $filename);
        $basePath = \defined('BP') ? \str_replace('\\', '/', (string)BP) : '';
        if ($basePath !== '' && \str_starts_with($path, $basePath)) {
            $path = \ltrim(\substr($path, \strlen($basePath)), '/');
        }
        // Keep owning module so multi-module hooks that share a Theme-relative
        // suffix (e.g. hooks/Weline_Theme/.../body-end) do not collapse to one
        // false duplicate identity.
        $owner = '';
        if (\preg_match('#(?:^|/)app/code/([^/]+)/([^/]+)/#', $path, $ownerMatch) === 1
            || \preg_match('#(?:^|/)code/([^/]+)/([^/]+)/#', $path, $ownerMatch) === 1
        ) {
            $owner = $ownerMatch[1] . '_' . $ownerMatch[2] . '/';
        }
        if (\preg_match('#/(widgets|partials|layouts|templates|hooks)/(.+)$#', $path, $m) === 1) {
            return $owner . $m[1] . '/' . $m[2];
        }
        if (\str_contains($path, '/')) {
            $parts = \explode('/', $path);

            return $owner . \implode('/', \array_slice($parts, -3));
        }

        return $path !== '' ? $path : '(empty)';
    }

    /**
     * @param array{db_duration_ms?: float, db_span_count?: int, wls_duration_ms?: float, wls_span_count?: int, php_ms?: float}|null $io
     */
    private function annotateTemplateRenderOverlay(
        string $html,
        string $filename,
        float $totalMs,
        int $bytes,
        ?array $io = null
    ): string {
        if ($html === '' || !$this->shouldAnnotateTemplateRenderOverlay()) {
            return $html;
        }
        // Overlay 开启时放宽阈值，尽量把部件级耗时露出来。
        if ($totalMs < 5.0) {
            return $html;
        }

        $io = $this->finalizeTemplateIoCounters($io, $totalMs);

        $path = \str_replace('\\', '/', $filename);
        $basePath = \defined('BP') ? \str_replace('\\', '/', (string)BP) : '';
        if ($basePath !== '' && \str_starts_with($path, $basePath)) {
            $path = \ltrim(\substr($path, \strlen($basePath)), '/');
        }

        $short = $path;
        if (\preg_match('#/(widgets|partials|layouts|templates|hooks)/(.+)$#', $path, $m) === 1) {
            $short = $m[1] . '/' . $m[2];
        } elseif (\str_contains($path, '/')) {
            $parts = \explode('/', $path);
            $short = \implode('/', \array_slice($parts, -3));
        }

        $tone = $totalMs >= 500.0 ? '#b91c1c' : ($totalMs >= 100.0 ? '#c2410c' : '#a16207');
        $totalLabel = \number_format($totalMs, 1, '.', '');
        $dbPart = \sprintf('db %sms(%dq)', \number_format($io['db_duration_ms'], 1, '.', ''), $io['db_span_count']);
        $wlsPart = \sprintf('wls %sms(%d)', \number_format($io['wls_duration_ms'], 1, '.', ''), $io['wls_span_count']);
        $phpPart = \sprintf('php %sms', \number_format($io['php_ms'], 1, '.', ''));
        $bytesLabel = $this->formatTemplatePerfBytes($bytes);
        // 剪贴板用完整路径 + 全套指标（展示仍用 short）。
        $copyText = \sprintf(
            '⏱ %s · total %sms · %s · %s · %s · %s',
            $path,
            $totalLabel,
            $dbPart,
            $wlsPart,
            $phpPart,
            $bytesLabel
        );
        $badge = \sprintf(
            '<div class="wls-tpl-perf" role="button" tabindex="0" title="点击复制整条耗时信息" data-wls-tpl-copy="%s" data-wls-tpl-file="%s" data-wls-tpl-ms="%s" data-wls-tpl-db-ms="%s" data-wls-tpl-php-ms="%s" data-wls-tpl-wls-ms="%s" data-wls-tpl-db-q="%d" data-wls-tpl-bytes="%d" style="position:relative;z-index:2147483000;display:inline-block;margin:2px 0;padding:2px 8px;border-radius:4px;background:%s;color:#fff;font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;box-shadow:0 1px 4px rgba(0,0,0,.25);max-width:100%%;word-break:break-all;cursor:pointer;user-select:none;">⏱ <span class="wls-tpl-perf__path">%s</span> · total %sms · %s · %s · %s · %s</div>',
            \htmlspecialchars($copyText, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($path, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($totalLabel, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars(\number_format($io['db_duration_ms'], 1, '.', ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars(\number_format($io['php_ms'], 1, '.', ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars(\number_format($io['wls_duration_ms'], 1, '.', ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            $io['db_span_count'],
            $bytes,
            $tone,
            \htmlspecialchars($short, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($totalLabel, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($dbPart, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($wlsPart, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($phpPart, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            \htmlspecialchars($bytesLabel, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
        );
        $badge .= $this->templatePerfOverlayCopyBootstrap();

        // 完整文档：插到 <body> 后，避免破坏 doctype/head。
        if (\preg_match('/<body\b[^>]*>/i', $html, $bodyMatch, \PREG_OFFSET_CAPTURE) === 1) {
            $insertAt = (int)$bodyMatch[0][1] + \strlen((string)$bodyMatch[0][0]);
            return \substr($html, 0, $insertAt) . $badge . \substr($html, $insertAt);
        }

        // 部件外壳：徽标作为 .widget-wrapper 首子节点，紧挨部件。
        if (\preg_match('/^(<\s*div\b[^>]*\bwidget-wrapper\b[^>]*>)/i', $html, $wrapMatch) === 1) {
            return $wrapMatch[1] . $badge . \substr($html, \strlen($wrapMatch[1]));
        }

        return $badge . $html;
    }

    /**
     * 徽标可读体积：B / KB / MB（1024 进制；data-wls-tpl-bytes 仍保留原始字节）。
     */
    private function formatTemplatePerfBytes(int $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return \rtrim(\rtrim(\number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }

        return \rtrim(\rtrim(\number_format($bytes / (1024 * 1024), 2, '.', ''), '0'), '.') . ' MB';
    }

    /**
     * 同请求只注入一次：点击 / Enter / Space 复制整条耗时信息（完整路径 + 指标）。
     */
    private function templatePerfOverlayCopyBootstrap(): string
    {
        if (RequestContext::isInitialized()) {
            if (RequestContext::get('view.template.overlay_copy_script') === true) {
                return '';
            }
            RequestContext::set('view.template.overlay_copy_script', true);
        }

        // data-wls-tpl-perf-copy=4：版本化安装；FPC 拼进旧脚本时也能被新脚本 AbortController 覆盖。
        return '<script data-wls-tpl-perf-copy="4">'
            . '(function(){var VER=4;if(window.__WLS_TPL_PERF_COPY__===VER)return;window.__WLS_TPL_PERF_COPY__=VER;'
            . 'if(window.__WLS_TPL_PERF_COPY_AC__){try{window.__WLS_TPL_PERF_COPY_AC__.abort();}catch(e0){}}'
            . 'var ac=new AbortController();window.__WLS_TPL_PERF_COPY_AC__=ac;var sig=ac.signal;'
            . 'function legacy(t){try{var a=document.createElement("textarea");a.value=t;a.setAttribute("readonly","");'
            . 'a.style.cssText="position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;";'
            . 'document.body.appendChild(a);a.focus();a.select();a.setSelectionRange(0,a.value.length);'
            . 'var ok=document.execCommand("copy");document.body.removeChild(a);return !!ok;}catch(e){return false;}}'
            . 'function write(t){if(navigator.clipboard&&navigator.clipboard.writeText){'
            . 'return navigator.clipboard.writeText(t).then(function(){return true;},function(){return legacy(t);});}'
            . 'return Promise.resolve(legacy(t));}'
            . 'function payload(el){var t=el.getAttribute("data-wls-tpl-copy")||"";'
            . 'if(t)return t;return String(el.innerText||el.textContent||"").replace(/\\s+/g," ").trim();}'
            . 'function selectAll(el){try{var r=document.createRange();r.selectNodeContents(el);'
            . 'var s=window.getSelection();s.removeAllRanges();s.addRange(r);}catch(e1){}}'
            . 'function flash(el){var prevOutline=el.style.outline;el.title="已复制整条信息";'
            . 'el.style.outline="2px solid #fff";setTimeout(function(){el.style.outline=prevOutline;'
            . 'el.title="点击复制整条耗时信息";},1200);}'
            . 'function act(el){var t=payload(el);if(!t)return;'
            . 'write(t).then(function(ok){if(ok){flash(el);}else{selectAll(el);el.title="已选中整条信息，请按 Cmd+C / Ctrl+C 复制";}});}'
            . 'document.addEventListener("click",function(e){var el=e.target&&e.target.closest&&e.target.closest(".wls-tpl-perf");'
            . 'if(!el)return;e.preventDefault();act(el);},{capture:true,signal:sig});'
            . 'document.addEventListener("keydown",function(e){if(e.key!=="Enter"&&e.key!==" ")return;'
            . 'var el=e.target&&e.target.closest&&e.target.closest(".wls-tpl-perf");'
            . 'if(!el)return;e.preventDefault();act(el);},{capture:true,signal:sig});'
            . '})();</script>';
    }

    /**
     * @param array{db_duration_ms?: float, db_span_count?: int, wls_duration_ms?: float, wls_span_count?: int, php_ms?: float}|null $io
     */
    private function recordTemplateRenderProfile(
        string $filename,
        float $initMs,
        float $includeMs,
        float $captureMs,
        float $totalMs,
        int $bytes,
        ?array $io = null
    ): void {
        $traceEnabled = TraceGate::isArmed();
        if (!$traceEnabled && $totalMs < 20.0 && $includeMs < 20.0) {
            return;
        }
        $path = \str_replace('\\', '/', $filename);
        $basePath = \defined('BP') ? \str_replace('\\', '/', BP) : '';
        if ($basePath !== '' && \str_starts_with($path, $basePath)) {
            $path = \ltrim(\substr($path, \strlen($basePath)), '/');
        }
        $io = $this->finalizeTemplateIoCounters($io, $totalMs);

        if ($traceEnabled) {
            // 小模板也会积少成多；仅在当前请求累计，避免逐次写入 trace 明细。
            $aggregate = RequestContext::get('view.template.aggregate') ?? ['files' => [], 'overflow_calls' => 0];
            if (isset($aggregate['files'][$path]) || \count($aggregate['files']) < 128) {
                $entry = $aggregate['files'][$path] ?? [
                    'calls' => 0,
                    'init_ms' => 0.0,
                    'include_ms' => 0.0,
                    'capture_ms' => 0.0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'bytes' => 0,
                    'db_duration_ms' => 0.0,
                    'db_span_count' => 0,
                    'wls_duration_ms' => 0.0,
                    'php_ms' => 0.0,
                ];
                ++$entry['calls'];
                $entry['init_ms'] += $initMs;
                $entry['include_ms'] += $includeMs;
                $entry['capture_ms'] += $captureMs;
                $entry['total_ms'] += $totalMs;
                $entry['max_ms'] = \max($entry['max_ms'], $totalMs);
                $entry['bytes'] += $bytes;
                $entry['db_duration_ms'] = \round((float)$entry['db_duration_ms'] + $io['db_duration_ms'], 2);
                $entry['db_span_count'] = (int)$entry['db_span_count'] + $io['db_span_count'];
                $entry['wls_duration_ms'] = \round((float)$entry['wls_duration_ms'] + $io['wls_duration_ms'], 2);
                $entry['php_ms'] = \round((float)$entry['php_ms'] + $io['php_ms'], 2);
                $aggregate['files'][$path] = $entry;
            } else {
                ++$aggregate['overflow_calls'];
            }
            RequestContext::set('view.template.aggregate', $aggregate);
        }

        if ($totalMs < 20.0 && $includeMs < 20.0) {
            return;
        }
        $profile = RequestContext::get('view.template.profile');
        $profile = \is_array($profile) ? $profile : [];

        $profile[] = [
            'file' => $path,
            'init_ms' => \round($initMs, 2),
            'include_ms' => \round($includeMs, 2),
            'capture_ms' => \round($captureMs, 2),
            'total_ms' => \round($totalMs, 2),
            'bytes' => $bytes,
            'db_duration_ms' => $io['db_duration_ms'],
            'db_span_count' => $io['db_span_count'],
            'wls_duration_ms' => $io['wls_duration_ms'],
            'php_ms' => $io['php_ms'],
        ];

        if (\count($profile) > 80) {
            $profile = \array_slice($profile, -80);
        }

        RequestContext::set('view.template.profile', $profile);
    }

    private function buildReusableTemplateOutputCacheKey(string $filename, array $dictionary): ?string
    {
        if (!Runtime::isPersistent()) {
            return null;
        }

        $path = \str_replace('\\', '/', $filename);
        if (!\str_contains($path, '/theme/frontend/partials/header/com_default.phtml')) {
            return null;
        }

        try {
            if ($this->request->isBackend()) {
                return null;
            }
            if ((string)($this->request->getMethod() ?: 'GET') !== 'GET') {
                return null;
            }
            if ($this->shouldDecorateHookOutput()) {
                return null;
            }
            if (\function_exists('w_env_get')) {
                $editorMode = (string)\w_env_get('editor_mode', '');
                if ($editorMode === '1' || $editorMode === 'true') {
                    return null;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        $stat = @\stat($filename);
        if (!\is_array($stat)) {
            return null;
        }

        $authContext = $this->frontendAuthStaticHookCacheContext();
        if ($authContext === null) {
            return null;
        }

        return KeyBuilder::buildEnvironmentScoped(
            'template.output.header.default',
            $path . '|' . (int)$stat['mtime'] . '|' . (int)$stat['size'],
            [
                'scope' => 'frontend-header-default',
                'cache_version' => '20261013-header-env-scoped-v2',
                'auth' => $authContext,
                'dictionary' => $this->reusableHeaderDictionaryScopeHash($dictionary),
                'request_scope' => $this->reusableHeaderRequestScope(),
            ],
            [
                'area_route' => false,
            ],
        );
    }

    private function reusableHeaderDictionaryScopeHash(array $dictionary): string
    {
        $meta = \is_array($dictionary['meta'] ?? null) ? $dictionary['meta'] : [];
        $layout = \is_array($dictionary['layout'] ?? null) ? $dictionary['layout'] : [];
        $colors = \is_array($dictionary['colors'] ?? null) ? $dictionary['colors'] : [];
        $scope = [
            'meta' => $this->normalizeReusableHeaderDictionaryValue($this->pickReusableHeaderKeys($meta)),
            'layout' => $this->normalizeReusableHeaderDictionaryValue($this->pickReusableHeaderKeys($layout)),
            'colors' => $this->normalizeReusableHeaderDictionaryValue($colors),
        ];
        $encoded = \json_encode($scope, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return \sha1(\is_string($encoded) ? $encoded : \serialize($scope));
    }

    /**
     * Header search facade reflects live request selection (type / category / q).
     * Process reusable header HTML must vary on these params or sticky selections
     * leak across sequential /search navigations in WLS workers.
     *
     * @return array{path:string,q:string,type:string,category_id:int,blog_category_id:int}
     */
    private function reusableHeaderRequestScope(): array
    {
        try {
            $request = $this->request;
            $path = '';
            if (\is_object($request) && \method_exists($request, 'getUrlPath')) {
                $path = \strtolower(\trim((string)$request->getUrlPath(), '/'));
            } else {
                $uri = (string)(\w_env('request.uri', '') ?? '');
                $path = \strtolower(\trim((string)(\parse_url($uri, \PHP_URL_PATH) ?: ''), '/'));
            }
            $first = \explode('/', $path)[0] ?? '';

            // Query identity comes from the request Context, not the process $_GET table.
            $q = \trim((string)(WelineEnv::getGet('q', '') ?? ''));
            $type = \trim((string)(WelineEnv::getGet('type', '') ?? ''));
            $categoryId = (int)(WelineEnv::getGet('category_id', 0) ?? 0);
            $blogCategoryId = (int)(WelineEnv::getGet('blog_category_id', 0) ?? 0);
            if ($q === '' && \is_object($request)) {
                $q = \trim((string)($request->getParam('q') ?? ''));
            }
            if ($type === '' && \is_object($request)) {
                $type = \trim((string)($request->getParam('type') ?? ''));
            }
            if ($categoryId <= 0 && \is_object($request)) {
                $categoryId = (int)($request->getParam('category_id') ?? 0);
            }
            if ($blogCategoryId <= 0 && \is_object($request)) {
                $blogCategoryId = (int)($request->getParam('blog_category_id') ?? 0);
            }

            return [
                'path' => $first,
                'q' => $q,
                'type' => $type,
                'category_id' => $categoryId,
                'blog_category_id' => $blogCategoryId,
            ];
        } catch (\Throwable) {
            return [
                'path' => '',
                'q' => '',
                'type' => '',
                'category_id' => 0,
                'blog_category_id' => 0,
            ];
        }
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string, mixed>
     */
    private function pickReusableHeaderKeys(array $data): array
    {
        $keys = ['logo', 'logoText', 'logoHeight', 'navItems', 'showSearch', 'showUserMenu', 'class'];
        $picked = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $data)) {
                $picked[$key] = $data[$key];
            }
        }

        return $picked;
    }

    private function normalizeReusableHeaderDictionaryValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if (!\is_array($value)) {
            return \is_object($value) && \method_exists($value, '__toString')
                ? (string)$value
                : \get_debug_type($value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (\count($normalized) >= 128) {
                break;
            }
            $normalized[\is_int($key) ? $key : (string)$key] = $this->normalizeReusableHeaderDictionaryValue($item);
        }
        \ksort($normalized);

        return $normalized;
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readReusableTemplateOutputCache(string $cacheKey, int $ttl): array
    {
        $entry = $this->normalizeStaticSwrEntry(self::$reusableTemplateOutputCache[$cacheKey] ?? null, $ttl);
        if ($entry !== null) {
            self::$reusableTemplateOutputCache[$cacheKey] = $entry;
            return $this->staticSwrEntryStatus($entry);
        }

        unset(self::$reusableTemplateOutputCache[$cacheKey]);
        $runtimeCached = $this->readRuntimeHookCache($cacheKey, $ttl);
        if ($runtimeCached['status'] !== 'miss' && \is_string($runtimeCached['html'])) {
            $this->rememberReusableTemplateOutput($cacheKey, $runtimeCached['html'], $ttl, (string)$runtimeCached['status']);
        }

        return $runtimeCached;
    }

    private function rememberReusableTemplateOutput(string $cacheKey, string $html, int $ttl, string $status = 'fresh'): void
    {
        if (!HtmlCacheAdmission::admit($html)) {
            return;
        }
        if (\count(self::$reusableTemplateOutputCache) > self::REUSABLE_TEMPLATE_OUTPUT_CACHE_MAX_ITEMS) {
            self::$reusableTemplateOutputCache = [];
        }

        self::$reusableTemplateOutputCache[$cacheKey] = $this->makeStaticSwrEntry($html, $ttl, $status);
        self::runtimeHookCacheSet($cacheKey, $html, $ttl);
    }

    /**
     * Keep child-template dictionary data request-local to that include.
     * Persistent workers otherwise accumulate large parent/child payloads and
     * extract them again for every later layout partial.
     *
     * @param array<string, mixed> $dictionary
     * @return array{restore: array<string, mixed>, remove: list<string>}
     */
    private function pushTemporaryTemplateData(array $dictionary): array
    {
        if ($dictionary === []) {
            return ['restore' => [], 'remove' => []];
        }

        $restore = [];
        $remove = [];
        foreach ($dictionary as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if ($this->hasData($key)) {
                $restore[$key] = $this->getData($key);
            } else {
                $remove[] = $key;
            }
        }

        $this->addData($dictionary);

        return ['restore' => $restore, 'remove' => $remove];
    }

    /**
     * @param array{restore: array<string, mixed>, remove: list<string>} $temporaryTemplateData
     */
    private function popTemporaryTemplateData(array $temporaryTemplateData): void
    {
        $remove = $temporaryTemplateData['remove'] ?? [];
        if ($remove !== []) {
            $this->unsetData($remove);
        }

        $restore = $temporaryTemplateData['restore'] ?? [];
        foreach ($restore as $key => $value) {
            $this->setData($key, $value);
        }
    }

    private function shouldTraceAccountSidebarTemplate(string $filename): bool
    {
        $normalized = \str_replace('\\', '/', $filename);
        if (!\str_contains($normalized, '/account/sidebar/com_side.phtml')) {
            return false;
        }

        return $this->isAccountSidebarDebugRequest();
    }

    private function shouldTraceAccountSidebarHook(string $name): bool
    {
        if ($name !== 'account.sidebar') {
            return false;
        }

        return $this->isAccountSidebarDebugRequest();
    }

    private function isAccountSidebarDebugRequest(): bool
    {
        try {
            return (string)ObjectManager::getInstance(Request::class)->getGet('debug_sidebar', '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function logAccountSidebarTemplateTrace(string $stage, array $context): void
    {
        try {
            $context += [
                'request_id' => RequestContext::getId(),
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => WelineEnv::getLang(),
                'lang_local' => WelineEnv::getLangLocal(),
            ];
        } catch (\Throwable) {
        }

        \error_log('[AccountSidebarTrace] ' . $stage . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    }

    public function writeJsonForScript(mixed $value): void
    {
        $bytesSinceYield = 0;
        self::writeJsonForScriptValue($value, $bytesSinceYield, 0);
    }

    private static function writeJsonForScriptValue(mixed $value, int &$bytesSinceYield, int $depth): void
    {
        if (!\is_array($value) || $depth > 512) {
            self::writeJsonChunk(self::jsonEncodeForScript($value), $bytesSinceYield);
            return;
        }

        $isList = \array_is_list($value);
        self::writeJsonChunk($isList ? '[' : '{', $bytesSinceYield);
        $first = true;
        foreach ($value as $key => $item) {
            if ($first) {
                $first = false;
            } else {
                self::writeJsonChunk(',', $bytesSinceYield);
            }

            if (!$isList) {
                self::writeJsonChunk(self::jsonEncodeForScript((string)$key), $bytesSinceYield);
                self::writeJsonChunk(':', $bytesSinceYield);
            }

            self::writeJsonForScriptValue($item, $bytesSinceYield, $depth + 1);
        }
        self::writeJsonChunk($isList ? ']' : '}', $bytesSinceYield);
    }

    private static function writeJsonChunk(string $chunk, int &$bytesSinceYield): void
    {
        echo $chunk;
        $bytesSinceYield += \strlen($chunk);
        if ($bytesSinceYield < self::WLS_JSON_YIELD_BYTES) {
            return;
        }

        $bytesSinceYield = 0;
        self::cooperativeTemplateYield(true);
    }

    private static function jsonEncodeForScript(mixed $value): string
    {
        return (string)\json_encode($value, self::JSON_SCRIPT_FLAGS);
    }

    private static function cooperativeTemplateYield(bool $force = false): void
    {
        if (!Runtime::isPersistent() || !SchedulerSystem::isSchedulerActive()) {
            return;
        }
        if (!(bool)Env::get('wls.performance.template_cooperative_yield_enabled', false)) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }

        $now = \microtime(true);
        self::$fiberRenderYieldAt ??= new \WeakMap();
        $lastYieldAt = (float)(self::$fiberRenderYieldAt[$fiber] ?? 0.0);
        if (!$force && $lastYieldAt <= 0.0) {
            self::$fiberRenderYieldAt[$fiber] = $now;
            return;
        }
        if (!$force && $lastYieldAt > 0.0 && (($now - $lastYieldAt) * 1000000) < self::WLS_TEMPLATE_YIELD_MIN_INTERVAL_US) {
            return;
        }

        if (!FiberOutputBuffer::flushBeforeYield()) {
            return;
        }

        self::$fiberRenderYieldAt[$fiber] = $now;
        SchedulerSystem::yield();
    }

    /**
     * @DESC         |替换模板中的占位符
     *
     * 参数区：
     *
     * @param string $content 文本
     * @param string $fileName 模板文件
     *
     * @return string|string[]|null
     * @throws Core
     */
    public function tmp_replace(string $content, string $fileName = ''): array|string|null
    {
        $eventData = new DataObject(['content' => $content, 'tplFile' => $fileName, 'template' => $this]);
        $this->eventsManager->dispatch('Weline_Framework_Template::before_compile', $eventData);
        $content = (string)$eventData->getData('content');
        return $this->getTaglib()->tagReplace($this, $content, $fileName);
    }

    /*_______________URL____________*/
    private function getUrlObject(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }

    public function getUrl(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getUrl($path, $params, $merge_query);
    }

    public function getFrontendUrl(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getFrontendUrl($path, $params, $merge_query);
    }

    public function getApi(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getUrl($path, $params, $merge_query);
    }

    public function getBackendUrl(string $path, array|bool $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendUrl($path, $params, $merge_query);
    }

    /**
     * 后台 URL 的路径部分（不含 scheme/host/port），表单 action / 站内链接应优先使用，避免代理端口与直连端口不一致导致 POST 丢失。
     */
    public function getBackendUrlPath(string $path = '', array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendUrlPath($path, $params, $merge_query);
    }

    public function getBackendApi(string $path, array|bool $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendApiUrl($path, $params, $merge_query);
    }
    /*_______________URL____________*/

    /**
     * Stream hook contributors into the current capture (memoized file list per request).
     */
    private function streamHookIntoCurrentCapture(string $name, bool $forceRefresh): void
    {
        $templateCachePolicies = self::templateCachePolicies();
        if (!$forceRefresh && $templateCachePolicies->isRequestCacheable($name)) {
            $context = Context::current();
            $requestId = RequestContext::getId();
            if ($requestId === null || $requestId === '') {
                $requestId = (string)($context->get('runtime.request_count') ?? \spl_object_id($context));
            }
            $requestCacheKey = KeyBuilder::buildEnvironmentScoped(
                'view.hook.request',
                $requestId . ':' . $name,
                [
                    'cache_version' => '20261013-hook-request-v2',
                ],
                [
                    'area_route' => false,
                ],
            );
            $cachedHookHtml = RequestContext::get($requestCacheKey);
            if (\is_string($cachedHookHtml)) {
                echo $cachedHookHtml;

                return;
            }
        }

        $aggregatePolicy = $templateCachePolicies->aggregate($name);
        if ($aggregatePolicy !== null) {
            $aggregateContext = $this->resolveTemplateCachePolicyContext($aggregatePolicy, $name);
            if ($aggregateContext !== null) {
                $aggregateCacheKey = KeyBuilder::buildEnvironmentScoped(
                    'view.hook.aggregate',
                    $name . '|' . $aggregateContext . '|' . $templateCachePolicies->aggregateDigest($name),
                    [
                        'cache_version' => '20261013-hook-aggregate-v2',
                        'origin' => $this->baseUrlCacheContext(),
                    ],
                    [
                        'area_route' => false,
                    ],
                );
                $aggregateTtl = $this->staticHookAggregateCacheTtl();
                if (!$forceRefresh) {
                    $cachedAggregateHtml = $this->readStaticHookAggregateCache($aggregateCacheKey, $aggregateTtl);
                    if ($cachedAggregateHtml['status'] !== 'miss') {
                        echo (string)$cachedAggregateHtml['html'];

                        return;
                    }
                    $runtimeCachedAggregate = $this->readRuntimeHookCache($aggregateCacheKey, $aggregateTtl);
                    if ($runtimeCachedAggregate['status'] !== 'miss') {
                        echo (string)$runtimeCachedAggregate['html'];

                        return;
                    }
                }
            }
        }

        foreach ($this->resolveHookContributorFiles($name) as $hookerFile) {
            $this->streamHookContributorFile((string)$hookerFile);
        }
    }

    /**
     * @return array<string, string> module => logical hook file
     */
    private function resolveHookContributorFiles(string $name): array
    {
        $memoKey = 'view.hook.files:' . $name;
        $memoized = RequestContext::get($memoKey);
        if (\is_array($memoized)) {
            return $memoized;
        }

        $hookFiles = [];
        $hookFilesWithMeta = [];
        try {
            /** @var \Weline\Framework\Hook\Config\HookReader $hookReader */
            $hookReader = ObjectManager::make(\Weline\Framework\Hook\Config\HookReader::class);
            $hookReader->setPath($name);
            $hookFilesWithMeta = $hookReader->getFileListWithMeta();
            if ($hookFilesWithMeta !== []) {
                foreach ($hookFilesWithMeta as $module => $meta) {
                    $filePath = (string)($meta['file'] ?? $meta['path'] ?? '');
                    if ($filePath === '') {
                        continue;
                    }
                    $hookFiles[$module] = strpos($filePath, '::') !== false
                        ? $filePath
                        : $module . '::hooks/' . $filePath;
                }
            }
            if ($hookFiles === []) {
                $hookFiles = $hookReader->getFileList();
            }
        } catch (\Throwable) {
            /** @var Hooker $hooker */
            $hooker = ObjectManager::getInstance(Hooker::class);
            $hookFiles = $hooker->getHook($name);
        }

        $soloHook = null;
        foreach ($hookFilesWithMeta as $module => $meta) {
            if (!empty($meta['solo'])) {
                $soloHook = $module;
                break;
            }
        }
        if ($soloHook !== null && isset($hookFiles[$soloHook])) {
            $hookFiles = [$soloHook => $hookFiles[$soloHook]];
        }

        if ($hookFiles !== [] && \method_exists(\Weline\Framework\Phrase\Parser::class, 'prefetchGlobalDictionaryModules')) {
            $dictionaryModules = [];
            foreach ($hookFiles as $hookFile) {
                try {
                    [, $sourceModule] = $this->processModuleSourceFilePath('hooks', (string)$hookFile);
                    if ($sourceModule !== '') {
                        $dictionaryModules[$sourceModule] = $sourceModule;
                    }
                } catch (\Throwable) {
                }
            }
            if ($dictionaryModules !== []) {
                $dictionaryModules = \array_values($dictionaryModules);
                \sort($dictionaryModules);
                if (!\Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator::coversModules($dictionaryModules)) {
                    TraceGate::measurePhase(
                        'view.hook.dictionary_prefetch',
                        static fn() => \Weline\Framework\Phrase\Parser::prefetchGlobalDictionaryModules($dictionaryModules),
                        ['hook' => $name, 'modules' => \count($dictionaryModules), 'module_set_hash' => \sha1(\implode('|', $dictionaryModules))],
                    );
                    \Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator::noteAdditionalModules($dictionaryModules);
                }
            }
        }

        RequestContext::set($memoKey, $hookFiles);

        return $hookFiles;
    }

    private function streamHookContributorFile(string $hookFile): void
    {
        $this->addSourceModuleToRequest('hooks', $hookFile);
        $templateCachePolicies = self::templateCachePolicies();
        $cachePolicy = $templateCachePolicies->output($hookFile);
        if ($cachePolicy === null) {
            TemplateRenderRouteTrace::mark('hook_stream_file', $this->shortRenderRouteIdentity($hookFile), ['policy' => 'none']);
            $compiledFile = $this->fetchTagSource('hooks', $hookFile);
            $this->streamCompiledFile($compiledFile);

            return;
        }

        // Output-cacheable contributors still need a string for HIT/store.
        echo $this->fetchHookHtml($hookFile);
    }

    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws Core
     */
    public function getHook(string $name, bool $forceRefresh = false): string
    {
        $traceAccountSidebarHook = $this->shouldTraceAccountSidebarHook($name);
        if ($traceAccountSidebarHook) {
            $this->logAccountSidebarTemplateTrace('hook_begin', [
                'hook' => $name,
                'force_refresh' => $forceRefresh,
                'template_id' => \spl_object_id($this),
                'buffer' => FiberOutputBuffer::debugState(),
            ]);
        }

        // Nested echo getHook(...): stream contributors into the open frame (no nested OB).
        // DEV decoration and top-level/string consumers keep the capture path below.
        // getHookResult(<else/>) MUST receive a string — streaming+'' would falsely trigger
        // fallback and double-render (e.g. header language switcher ×2).
        if (HookRuntime::preferStreamIntoCapture($this->shouldDecorateHookOutput())) {
            TemplateRenderRouteTrace::armIfRequested();
            TemplateRenderRouteTrace::begin('hook_stream', $name);
            try {
                $this->streamHookIntoCurrentCapture($name, $forceRefresh);
            } finally {
                TemplateRenderRouteTrace::end('hook_stream', $name);
            }

            return '';
        }

        $requestCacheKey = null;
        $aggregateCacheKey = null;
        $templateCachePolicies = self::templateCachePolicies();
        if (!$forceRefresh && $templateCachePolicies->isRequestCacheable($name)) {
            $context = Context::current();
            $requestId = RequestContext::getId();
            if ($requestId === null || $requestId === '') {
                $requestId = (string)($context->get('runtime.request_count') ?? \spl_object_id($context));
            }
            $requestCacheKey = KeyBuilder::buildEnvironmentScoped(
                'view.hook.request',
                $requestId . ':' . $name,
                [
                    'cache_version' => '20261013-hook-request-v2',
                ],
                [
                    'area_route' => false,
                ],
            );
            $cachedHookHtml = RequestContext::get($requestCacheKey);
            if (\is_string($cachedHookHtml)) {
                if ($traceAccountSidebarHook) {
                    $this->logAccountSidebarTemplateTrace('hook_request_cache_hit', [
                        'hook' => $name,
                        'output_bytes' => \strlen($cachedHookHtml),
                        'buffer' => FiberOutputBuffer::debugState(),
                    ]);
                }
                return $cachedHookHtml;
            }
        }

        $aggregatePolicy = $templateCachePolicies->aggregate($name);
        if ($aggregatePolicy !== null && !$this->shouldDecorateHookOutput()) {
            $aggregateContext = $this->resolveTemplateCachePolicyContext($aggregatePolicy, $name);
            if ($aggregateContext !== null) {
                $aggregateCacheKey = KeyBuilder::buildEnvironmentScoped(
                    'view.hook.aggregate',
                    $name . '|' . $aggregateContext . '|' . $templateCachePolicies->aggregateDigest($name),
                    [
                        'cache_version' => '20261013-hook-aggregate-v2',
                        'origin' => $this->baseUrlCacheContext(),
                    ],
                    [
                        'area_route' => false,
                    ],
                );
                $aggregateTtl = $this->staticHookAggregateCacheTtl();
                if (!$forceRefresh) {
                    // Complete aggregates may legitimately be empty (all contributor
                    // stubs moved to footer chrome). Empty HTML is a valid HIT — do
                    // not discard it, or body-end keeps re-walking every empty file.
                    $cachedAggregateHtml = $this->readStaticHookAggregateCache($aggregateCacheKey, $aggregateTtl);
                    if ($cachedAggregateHtml['status'] !== 'miss') {
                        if ($cachedAggregateHtml['status'] === 'stale' && ($aggregatePolicy['allow_async_refresh'] ?? false)) {
                            $this->queueStaticHookAggregateRefresh($name, $aggregateCacheKey, $aggregateTtl);
                        }
                        if ($requestCacheKey !== null) {
                            RequestContext::set($requestCacheKey, $cachedAggregateHtml['html']);
                        }
                        if ($traceAccountSidebarHook) {
                            $this->logAccountSidebarTemplateTrace('hook_aggregate_cache_hit', [
                                'hook' => $name,
                                'status' => (string)$cachedAggregateHtml['status'],
                                'output_bytes' => \strlen((string)$cachedAggregateHtml['html']),
                                'buffer' => FiberOutputBuffer::debugState(),
                            ]);
                        }
                        return (string)$cachedAggregateHtml['html'];
                    }

                    $runtimeCachedAggregate = $this->readRuntimeHookCache($aggregateCacheKey, $aggregateTtl);
                    if ($runtimeCachedAggregate['status'] !== 'miss') {
                        $this->rememberStaticHookAggregate($aggregateCacheKey, (string)$runtimeCachedAggregate['html'], $aggregateTtl, (string)$runtimeCachedAggregate['status']);
                        if ($runtimeCachedAggregate['status'] === 'stale' && ($aggregatePolicy['allow_async_refresh'] ?? false)) {
                            $this->queueStaticHookAggregateRefresh($name, $aggregateCacheKey, $aggregateTtl);
                        }
                        if ($requestCacheKey !== null) {
                            RequestContext::set($requestCacheKey, $runtimeCachedAggregate['html']);
                        }
                        if ($traceAccountSidebarHook) {
                            $this->logAccountSidebarTemplateTrace('hook_runtime_aggregate_cache_hit', [
                                'hook' => $name,
                                'status' => (string)$runtimeCachedAggregate['status'],
                                'output_bytes' => \strlen((string)$runtimeCachedAggregate['html']),
                                'buffer' => FiberOutputBuffer::debugState(),
                            ]);
                        }
                        return (string)$runtimeCachedAggregate['html'];
                    }
                }
            }
        }

        $traceEnabled = TraceGate::isArmed();
        $traceName = 'view::hook::' . substr($name, 0, 180);
        $traceStart = 0.0;
        if ($traceEnabled) {
            $traceStart = microtime(true);
            TraceGate::pushCurrentParent($traceName);
        }

        $hooker_content = '';
        
        // 获取hook文件列表（已按顺序排序）
        $hookFiles = [];
        $hookFilesWithMeta = [];
        try {
            /** @var \Weline\Framework\Hook\Config\HookReader $hookReader */
            $hookReader = ObjectManager::make(\Weline\Framework\Hook\Config\HookReader::class);
            $hookReader->setPath($name);
            $hookFilesWithMeta = $hookReader->getFileListWithMeta();
            if ($hookFilesWithMeta !== []) {
                foreach ($hookFilesWithMeta as $module => $meta) {
                    $filePath = (string)($meta['file'] ?? $meta['path'] ?? '');
                    if ($filePath === '') {
                        continue;
                    }

                    $hookFiles[$module] = strpos($filePath, '::') !== false
                        ? $filePath
                        : $module . '::hooks/' . $filePath;
                }
            }

            if ($hookFiles === []) {
                $hookFiles = $hookReader->getFileList(); // 已按顺序排序
            }
        } catch (\Throwable $e) {
            // 如果获取失败，尝试使用Hooker（向后兼容）
            /**@var Hooker $hooker */
            $hooker = ObjectManager::getInstance(Hooker::class);
            $hookFiles = $hooker->getHook($name);
        }
        
        // 检查是否有solo（独享）的hook
        $soloHook = null;
        $affectedHooks = [];
        foreach ($hookFilesWithMeta as $module => $meta) {
            if (!empty($meta['solo'])) {
                $soloHook = $module;
                // 收集所有被影响的hook（除了solo的hook本身）
                foreach ($hookFilesWithMeta as $otherModule => $otherMeta) {
                    if ($otherModule !== $module) {
                        $affectedHooks[] = $otherModule;
                    }
                }
                break; // 只允许一个solo hook
            }
        }
        
        // 如果存在solo hook，只执行solo hook
        if ($soloHook !== null) {
            $hookFiles = [$soloHook => $hookFiles[$soloHook]];
        }

        if ($hookFiles !== [] && \method_exists(\Weline\Framework\Phrase\Parser::class, 'prefetchGlobalDictionaryModules')) {
            $dictionaryModules = [];
            foreach ($hookFiles as $hookFile) {
                try {
                    // 使用实际文件来源；注册模块可能引用另一个模块，不能直接取列表键。
                    [, $sourceModule] = $this->processModuleSourceFilePath('hooks', (string)$hookFile);
                    if ($sourceModule !== '') {
                        $dictionaryModules[$sourceModule] = $sourceModule;
                    }
                } catch (\Throwable) {
                    // 路径异常仍由原来的逐文件渲染处理，预取不改变模块激活。
                }
            }
            if ($dictionaryModules !== []) {
                $dictionaryModules = \array_values($dictionaryModules);
                \sort($dictionaryModules);
                // Page-level prime already covered these modules — skip per-hook phase/MGET.
                if (!\Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator::coversModules($dictionaryModules)) {
                    TraceGate::measurePhase(
                        'view.hook.dictionary_prefetch',
                        static fn() => \Weline\Framework\Phrase\Parser::prefetchGlobalDictionaryModules($dictionaryModules),
                        ['hook' => $name, 'modules' => \count($dictionaryModules), 'module_set_hash' => \sha1(\implode('|', $dictionaryModules))],
                    );
                    \Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator::noteAdditionalModules($dictionaryModules);
                }
            }
        }

        $hookOutputBytes = 0;
        $emptyHookFiles = 0;
        if ($hookFiles === [] && $this->shouldLogHookDiagnostics($name)) {
            $this->logHookCacheDiagnostic('empty_hook_file_list', [
                'hook' => $name,
            ]);
        }
        if ($traceAccountSidebarHook) {
            $this->logAccountSidebarTemplateTrace('hook_file_list', [
                'hook' => $name,
                'files' => $hookFiles,
                'meta_modules' => \array_keys($hookFilesWithMeta),
                'buffer' => FiberOutputBuffer::debugState(),
            ]);
        }
        
        // 按顺序遍历hook文件（HookReader已按优先级和排序顺序排序）
        $decorateHookOutput = \defined('DEV') && DEV && $this->shouldDecorateHookOutput();
        foreach ($hookFiles as $module => $hooker_file) {
            // 获取hook文件路径（用于属性备注）
            $hookFilePath = $hookFiles[$module] ?? $hooker_file;
            // 提取相对路径
            if (strpos($hookFilePath, BP) === 0) {
                $relativePath = str_replace(BP, '', $hookFilePath);
                $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
            } else {
                // 如果已经是相对路径格式（Module::path），直接使用
                $relativePath = str_replace($module . '::', '', $hookFilePath);
            }
            
            // HookReader 已经返回正确格式：ModuleName::hooks/path/to/file.phtml
            // 直接使用，不需要再次构建路径
            $fileTraceName = 'view::hook_file::' . substr((string)$module . '::' . $name, 0, 180);
            $fileTraceStart = 0.0;
            $hookHtml = '';
            if ($traceEnabled) {
                $fileTraceStart = microtime(true);
                TraceGate::pushCurrentParent($fileTraceName);
            }
            if ($traceAccountSidebarHook) {
                $this->logAccountSidebarTemplateTrace('hook_file_before', [
                    'hook' => $name,
                    'module' => (string)$module,
                    'file' => (string)$hooker_file,
                    'buffer' => FiberOutputBuffer::debugState(),
                ]);
            }
            try {
                $hookHtml = $this->fetchHookHtml((string)$hooker_file);
            } finally {
                if ($traceEnabled) {
                    TraceGate::popCurrentParent();
                    TraceGate::recordSpan($fileTraceName, (microtime(true) - $fileTraceStart) * 1000, 'view', null, [
                        'hook' => $name,
                        'module' => (string)$module,
                        'file' => (string)$hooker_file,
                        'output_bytes' => \strlen($hookHtml),
                        'empty_output' => \trim($hookHtml) === '',
                    ]);
                }
            }
            if ($traceAccountSidebarHook) {
                $this->logAccountSidebarTemplateTrace('hook_file_after', [
                    'hook' => $name,
                    'module' => (string)$module,
                    'file' => (string)$hooker_file,
                    'output_bytes' => \strlen($hookHtml),
                    'empty_output' => \trim($hookHtml) === '',
                    'buffer' => FiberOutputBuffer::debugState(),
                ]);
            }
            $hookHtmlBytes = \strlen($hookHtml);
            $hookOutputBytes += $hookHtmlBytes;
            if (\trim($hookHtml) === '') {
                $emptyHookFiles++;
            }
            if ($this->shouldLogHookDiagnostics($name)) {
                $this->logHookCacheDiagnostic('hook_file_output', [
                    'hook' => $name,
                    'module' => (string)$module,
                    'file' => (string)$hooker_file,
                    'output_bytes' => $hookHtmlBytes,
                    'empty_output' => \trim($hookHtml) === '',
                ], \trim($hookHtml) === '');
            }
            
            // 检查是否是solo hook
            $isSolo = ($soloHook === $module);
            $hookMeta = $hookFilesWithMeta[$module] ?? [];
            
            $hookHtml = $this->applyHookSourceDecoration(
                $hookHtml,
                (string)$module,
                $relativePath,
                $isSolo,
                $affectedHooks,
                $decorateHookOutput
            );

            if ($decorateHookOutput) {
                $soloInfo = $isSolo ? ' | 独享模式（已禁用其他' . count($affectedHooks) . '个hook）' : '';
                $content = "<!-- Hook: {$name} | 模块: {$module} | 文件: {$relativePath}{$soloInfo} -->\n"
                    . $hookHtml
                    . "\n<!-- /Hook: {$name} | 模块: {$module} -->";
            } else {
                $content = $hookHtml;
            }
            
            $hooker_content .= $content;
        }
        
        if ($traceEnabled) {
            TraceGate::popCurrentParent();
            TraceGate::recordSpan($traceName, (microtime(true) - $traceStart) * 1000, 'view', null, [
                'hook' => $name,
                'files' => count($hookFiles),
                'output_bytes' => $hookOutputBytes,
                'empty_files' => $emptyHookFiles,
            ]);
        }

        if ($requestCacheKey !== null) {
            RequestContext::set($requestCacheKey, $hooker_content);
        }
        if ($aggregateCacheKey !== null) {
            // Store even when all contributors render empty: complete body-end
            // stubs still need an aggregate HIT to avoid re-walking N empty files.
            $aggregateTtl = $this->staticHookAggregateCacheTtl();
            $this->rememberStaticHookAggregate($aggregateCacheKey, $hooker_content, $aggregateTtl);
            self::runtimeHookCacheSet($aggregateCacheKey, $hooker_content, $aggregateTtl);
        }

        if ($traceAccountSidebarHook) {
            $this->logAccountSidebarTemplateTrace('hook_end', [
                'hook' => $name,
                'output_bytes' => \strlen($hooker_content),
                'empty_files' => $emptyHookFiles,
                'buffer' => FiberOutputBuffer::debugState(),
            ]);
        }

        return $hooker_content;
    }

    /**
     * Structured hook result（P2C / REQ-008）.
     *
     * @param bool $preferFallbackOnEmpty Taglib `<else/>` opt-in：有实现但空输出且非 handled_empty 时允许 fallback
     */
    public function getHookResult(string $name, bool $forceRefresh = false, bool $preferFallbackOnEmpty = false): \Weline\Framework\Hook\HookRenderResult
    {
        $name = \trim($name);
        if ($name === '') {
            return new \Weline\Framework\Hook\HookRenderResult(
                html: '',
                handledEmpty: false,
                useFallback: true,
                fileCount: 0,
            );
        }

        $fileCount = $this->countHookImplementationFiles($name);
        $handledEmptyKey = 'view.hook.handled_empty.' . $name;
        $decorateOutputKey = 'view.hook.decorate_output';
        $requireStringKey = 'view.hook.require_string_result';
        $hadDecorateOutput = RequestContext::has($decorateOutputKey);
        $previousDecorateOutput = RequestContext::get($decorateOutputKey);
        $hadRequireString = RequestContext::has($requireStringKey);
        $previousRequireString = RequestContext::get($requireStringKey);
        RequestContext::remove($handledEmptyKey);

        if ($preferFallbackOnEmpty) {
            // Fallback must be decided from semantic output, never from DEV/visual-editor comments.
            RequestContext::set($decorateOutputKey, false);
            // Block Fiber stream-into-capture: that path returns '' after already echoing,
            // which would both paint the hook AND take <else/> (duplicate chrome controls).
            RequestContext::set($requireStringKey, true);
        }

        try {
            // Opt-in fallback needs a fresh semantic render: an older request-cache entry
            // cannot prove whether an empty result was explicitly handled.
            $html = $this->getHook($name, $forceRefresh || $preferFallbackOnEmpty);
            $handledEmpty = (bool)RequestContext::get($handledEmptyKey);
        } finally {
            RequestContext::remove($handledEmptyKey);
            if ($preferFallbackOnEmpty) {
                if ($hadDecorateOutput) {
                    RequestContext::set($decorateOutputKey, $previousDecorateOutput);
                } else {
                    RequestContext::remove($decorateOutputKey);
                }
                if ($hadRequireString) {
                    RequestContext::set($requireStringKey, $previousRequireString);
                } else {
                    RequestContext::remove($requireStringKey);
                }
            }
        }

        $marker = '<!--weline:hook:handled_empty-->';
        if (\str_contains($html, $marker)) {
            $handledEmpty = true;
            $html = \str_replace($marker, '', $html);
        }
        $empty = \trim($html) === '';
        $useFallback = $fileCount === 0
            || ($preferFallbackOnEmpty && $empty && !$handledEmpty);

        return new \Weline\Framework\Hook\HookRenderResult(
            html: $html,
            handledEmpty: $handledEmpty,
            useFallback: $useFallback,
            fileCount: $fileCount,
        );
    }

    /** Mark current hook render as intentional empty (blocks opt-in fallback). */
    public function markHookHandledEmpty(string $name): void
    {
        $name = \trim($name);
        if ($name !== '') {
            RequestContext::set('view.hook.handled_empty.' . $name, true);
        }
    }

    private function countHookImplementationFiles(string $name): int
    {
        try {
            /** @var \Weline\Framework\Hook\Config\HookReader $hookReader */
            $hookReader = ObjectManager::make(\Weline\Framework\Hook\Config\HookReader::class);
            $hookReader->setPath($name);
            $files = $hookReader->getFileList();
            return \is_array($files) ? \count($files) : 0;
        } catch (\Throwable) {
            try {
                /** @var Hooker $hooker */
                $hooker = ObjectManager::getInstance(Hooker::class);
                $files = $hooker->getHook($name);
                return \is_array($files) ? \count($files) : 0;
            } catch (\Throwable) {
                return 0;
            }
        }
    }

    private function rememberStaticHookAggregate(string $cacheKey, string $html, int $ttl, string $status = 'fresh'): void
    {
        if (\count(self::$staticHookAggregateOutputCache) > 64) {
            self::$staticHookAggregateOutputCache = [];
        }

        self::$staticHookAggregateOutputCache[$cacheKey] = $this->makeStaticSwrEntry($html, $ttl, $status);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readStaticHookAggregateCache(string $cacheKey, int $ttl): array
    {
        $entry = $this->normalizeStaticSwrEntry(self::$staticHookAggregateOutputCache[$cacheKey] ?? null, $ttl);
        if ($entry === null) {
            unset(self::$staticHookAggregateOutputCache[$cacheKey]);
            return ['status' => 'miss', 'html' => null];
        }
        self::$staticHookAggregateOutputCache[$cacheKey] = $entry;

        return $this->staticSwrEntryStatus($entry);
    }

    private function queueStaticHookAggregateRefresh(string $name, string $cacheKey, int $ttl): void
    {
        if (PostResponseTaskQueue::isDraining()) {
            return;
        }
        if (!$this->acquireRuntimeHookRefreshLock($cacheKey)) {
            return;
        }

        // SCOPE + drain-nested gate: force refresh must not enqueue child Full/Scope tasks.
        PostResponseTaskQueue::enqueue(
            'theme-hook-aggregate:' . $cacheKey,
            function () use ($name): void {
                $this->getHook($name, true);
            },
            0.0,
            PostResponseCaptureMode::Scope,
        );
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function readRuntimeHookCache(string $cacheKey, int $ttl): array
    {
        $entry = $this->normalizeStaticSwrEntry(self::runtimeHookCacheGet($cacheKey), $ttl);
        if ($entry === null) {
            return ['status' => 'miss', 'html' => null];
        }

        return $this->staticSwrEntryStatus($entry);
    }

    /**
     * @return array{fresh_until: float, stale_until: float, html: string}|null
     */
    private function normalizeStaticSwrEntry(mixed $entry, int $ttl): ?array
    {
        $now = \microtime(true);
        if (\is_string($entry)) {
            return [
                'fresh_until' => $now + \max(1, $ttl),
                'stale_until' => $now + \max(1, $ttl) + $this->staticHookStaleTtl($ttl),
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
            $freshUntil = $now + \max(1, $ttl);
        }
        $staleUntil = (float)($entry['stale_until'] ?? ($freshUntil + $this->staticHookStaleTtl($ttl)));
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
    private function staticSwrEntryStatus(array $entry): array
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
    private function makeStaticSwrEntry(string $html, int $ttl, string $status = 'fresh'): array
    {
        $ttl = \max(1, $ttl);
        $now = \microtime(true);
        $freshUntil = $status === 'stale' ? $now - 0.001 : $now + $ttl;

        return [
            'fresh_until' => $freshUntil,
            'stale_until' => $freshUntil + $this->staticHookStaleTtl($ttl),
            'html' => $html,
        ];
    }

    private function isEmptyCacheHtml(string $html): bool
    {
        return \trim($html) === '';
    }

    private function shouldLogHookDiagnostics(string $name): bool
    {
        if (self::templateCachePolicies()->isDiagnostic($name)) {
            return true;
        }

        try {
            return (string)$this->request->getGet('debug_hooks', '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function logHookCacheDiagnostic(string $event, array $context = [], bool $warning = true): void
    {
        $logger = $warning ? 'w_log_warning' : 'w_log_info';
        if (!\function_exists($logger)) {
            return;
        }

        try {
            $context += [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'uri' => \function_exists('w_env_request_uri') ? (string)\w_env_request_uri() : '',
                'lang' => (string)WelineEnv::getLang(),
                'lang_local' => (string)WelineEnv::getLangLocal(),
                'currency' => (string)WelineEnv::getCurrency(),
            ];
        } catch (\Throwable) {
        }

        $payload = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logger('[HookCacheDiagnostics] ' . $event . ' ' . ($payload ?: '{}'));
    }

    private function staticHookStaleTtl(int $ttl): int
    {
        return self::cachePolicy()->ttl('theme.hook_stale_ttl', \max(self::STATIC_HOOK_STALE_TTL, $ttl * 10), 1, 86400);
    }

    private function staticHookRefreshLockTtl(): int
    {
        return self::cachePolicy()->ttl('theme.hook_refresh_lock_ttl', self::STATIC_HOOK_REFRESH_LOCK_TTL, 1, 300);
    }

    private function acquireRuntimeHookRefreshLock(string $cacheKey): bool
    {
        $cache = self::runtimeHookCache();
        $lockKey = $cacheKey . '.refresh_lock';
        if ($cache !== null) {
            try {
                return $cache->incr('theme_runtime', $lockKey, 1, $this->staticHookRefreshLockTtl()) === 1;
            } catch (\Throwable) {
                self::$staticHookRuntimeCache = null;
                self::$staticHookRuntimeCacheResolved = true;
            }
        }

        static $localLocks = [];
        $now = \microtime(true);
        $expiresAt = (float)($localLocks[$lockKey] ?? 0);
        if ($expiresAt >= $now) {
            return false;
        }
        $localLocks[$lockKey] = $now + $this->staticHookRefreshLockTtl();

        return true;
    }

    private function accountSidebarStaticHookCacheContext(): ?string
    {
        // Logical only; outer hook.output buildEnvironmentScoped owns env/origin.
        return 'account-sidebar|v2';
    }

    private function headerActionStaticHookCacheContext(string $name): ?string
    {
        $name = \trim($name);
        if ($name === '') {
            return null;
        }

        // Logical only; outer hook.output buildEnvironmentScoped owns env/origin.
        return 'header-action|' . $name;
    }

    private function baseUrlCacheContext(): string
    {
        try {
            // Origin only. Request::getBaseUrl() on PDPs may include the product
            // path and would shard chrome hook / aggregate process caches.
            $env = KeyBuilder::environmentContext([], [
                'area' => false,
                'area_route' => false,
                'website' => false,
                'website_url' => false,
                'host' => true,
                'base_url' => true,
                'lang' => false,
                'lang_local' => false,
                'currency' => false,
            ]);
            $origin = \trim((string)($env['base_url'] ?? ''));
            if ($origin !== '') {
                return $origin;
            }

            return \trim((string)($env['host'] ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Attach hook source metadata without breaking grid/flex direct-child layouts.
     *
     * @param array<int, string> $affectedHooks
     */
    private function applyHookSourceDecoration(
        string $hookHtml,
        string $module,
        string $relativePath,
        bool $isSolo,
        array $affectedHooks,
        bool $includeHookFile
    ): string {
        if (trim($hookHtml) === '') {
            return $hookHtml;
        }

        $hookHtml = ltrim($hookHtml);
        $dataAttrs = 'data-hook-source="' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . '"';
        if ($includeHookFile && $relativePath !== '') {
            $dataAttrs .= ' data-hook-file="' . htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($isSolo) {
            $dataAttrs .= ' data-hook-solo="true"';
            if ($affectedHooks !== []) {
                $dataAttrs .= ' data-hook-affected="' . htmlspecialchars(implode(',', $affectedHooks), ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        $hasScriptOrStyle = preg_match('/<(script|style)[^>]*>/i', $hookHtml) === 1;
        if (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)[^>]*>/', $hookHtml, $matches) === 1) {
            $tagName = $matches[1];
            $decorated = preg_replace(
                '/^(<' . preg_quote($tagName, '/') . '[^>]*)(>)/',
                '$1 ' . $dataAttrs . '$2',
                $hookHtml,
                1
            );

            return is_string($decorated) ? $decorated : $hookHtml;
        }
        if ($hasScriptOrStyle) {
            return '<!-- Hook source: ' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . ' -->' . $hookHtml;
        }

        return '<span ' . $dataAttrs . ' class="weline-hook-source" style="display:contents">' . $hookHtml . '</span>';
    }

    private function getHookCacheContext(): string
    {
        $parent = TraceGate::getCurrentParent();
        if (\is_string($parent) && $parent !== '') {
            return $parent;
        }

        $frames = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $parts = [];
        foreach ($frames as $frame) {
            $file = isset($frame['file']) ? (string)$frame['file'] : '';
            if ($file === __FILE__ || $file === '') {
                continue;
            }
            $parts[] = $file . ':' . (string)($frame['line'] ?? 0);
            if (\count($parts) >= 3) {
                break;
            }
        }

        return $parts === [] ? 'unknown' : \implode('|', $parts);
    }

    private function shouldDecorateHookOutput(): bool
    {
        $cacheKey = 'view.hook.decorate_output';
        $cached = RequestContext::get($cacheKey);
        if (\is_bool($cached)) {
            return $cached;
        }

        try {
            // Path gate via getUri() (legacy PathInfo accessor is absent on Request).
            $requestPath = $this->requestPathForCacheGate();
            $enabled = (string)$this->request->getGet('visual_editor', '') === '1'
                || (string)$this->request->getGet('preview', '') === '1'
                || (string)$this->request->getGet('debug_hooks', '') === '1'
                || \str_contains($requestPath, 'workspace-preview');
        } catch (\Throwable) {
            $enabled = false;
        }

        RequestContext::set($cacheKey, $enabled);
        return $enabled;
    }

    /** Path-only request URI for cache/editor gates (Request has no getPathInfo). */
    private function requestPathForCacheGate(): string
    {
        $uri = '';
        try {
            $uri = \trim((string)$this->request->getUri());
        } catch (\Throwable) {
            $uri = '';
        }
        if ($uri === '' && \function_exists('w_env_request_uri')) {
            try {
                $uri = \trim((string)\w_env_request_uri());
            } catch (\Throwable) {
                $uri = '';
            }
        }
        if ($uri === '') {
            return '';
        }
        $path = \parse_url($uri, PHP_URL_PATH);

        return \strtolower((string)(\is_string($path) && $path !== '' ? $path : $uri));
    }

    private function staticHookOutputCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.hook_output_ttl', (int)self::STATIC_HOOK_OUTPUT_CACHE_TTL);
    }

    private function staticHookAggregateCacheTtl(): int
    {
        return self::cachePolicy()->ttl('theme.hook_aggregate_ttl', (int)self::STATIC_HOOK_AGGREGATE_CACHE_TTL);
    }

    private static function runtimeHookCacheGet(string $key): mixed
    {
        $cache = self::runtimeHookCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get('theme_runtime', $key);
        } catch (\Throwable) {
            self::$staticHookRuntimeCache = null;
            self::$staticHookRuntimeCacheResolved = true;
            return null;
        }
    }

    private static function runtimeHookCacheSet(string $key, mixed $value, int $ttl): void
    {
        if (Runtime::isPersistent() && !PostResponseTaskQueue::isDraining()) {
            PostResponseTaskQueue::enqueue(
                'theme-runtime-cache-set:' . \sha1($key),
                static function () use ($key, $value, $ttl): void {
                    self::runtimeHookCacheSetImmediate($key, $value, $ttl);
                },
                \microtime(true) + self::STATIC_HOOK_SHARED_WRITE_IDLE_GRACE_SECONDS,
                PostResponseCaptureMode::None,
            );
            return;
        }

        self::runtimeHookCacheSetImmediate($key, $value, $ttl);
    }

    private static function runtimeHookCacheSetImmediate(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeHookCache();
        if ($cache === null) {
            return;
        }

        try {
            $ttl = \max(1, $ttl);
            $staleTtl = self::cachePolicy()->ttl('theme.hook_stale_ttl', \max(self::STATIC_HOOK_STALE_TTL, $ttl * 10), 1, 86400);
            $now = \microtime(true);
            $cache->set('theme_runtime', $key, [
                'fresh_until' => $now + $ttl,
                'stale_until' => $now + $ttl + $staleTtl,
                'value' => $value,
            ], $ttl + $staleTtl);
        } catch (\Throwable) {
            self::$staticHookRuntimeCache = null;
            self::$staticHookRuntimeCacheResolved = true;
        }
    }

    private static function runtimeHookCacheDelete(string $key): void
    {
        $cache = self::runtimeHookCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->delete('theme_runtime', $key);
        } catch (\Throwable) {
            self::$staticHookRuntimeCache = null;
            self::$staticHookRuntimeCacheResolved = true;
        }
    }

    private static function runtimeHookCache(): ?SharedCacheStateInterface
    {
        if (self::$staticHookRuntimeCacheResolved) {
            return self::$staticHookRuntimeCache;
        }
        self::$staticHookRuntimeCacheResolved = true;

        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return null;
        }

        try {
            $adapter = ObjectManager::getInstance(WlsRuntimeAdapterResolver::class)->resolve();
            self::$staticHookRuntimeCache = $adapter?->createSharedState(self::cachePolicy()->memoryOptions([
                'consumer_code' => 'theme_runtime_hook',
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]));
        } catch (\Throwable) {
            self::$staticHookRuntimeCache = null;
        }

        return self::$staticHookRuntimeCache;
    }

    private static function cachePolicy(): RuntimeCachePolicy
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class);
    }

    private static function templateCachePolicies(): TemplateCachePolicyRegistry
    {
        return self::$templateCachePolicyRegistry ??= new TemplateCachePolicyRegistry();
    }

    public function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function getTaglib()
    {
        if (isset($this->taglib)) {
            return $this->taglib;
        }
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        return $this->taglib;
    }
}
