<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
use Weline\Framework\Cache\CacheManager as FrameworkCacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;

class Core
{
    public const dir_static = 'static';

    public const url_path_split = '/';

    private Request $request;

    private string $request_area;

    private string $area_router;

    private bool $is_backend;
    private bool $is_match = false;

    private ?CachePoolInterface $cache = null;
    
    /**
     * 框架缓存管理器
     */
    private ?FrameworkCacheManager $frameworkCacheManager = null;
    
    /**
     * URL 处理器 - URL 规范化和解析
     */
    private ?UrlProcessor $urlProcessor = null;

    private ?FullPageCacheCoordinator $fullPageCacheCoordinator = null;

    protected array $router;
    protected string $url;
    /**缓存建*/
    protected string $_router_cache_key;
    protected string $url_cache_key;
    protected string $rule_cache_key;
    protected string $unified_cache_key;

    /**缓存结果*/
    protected mixed $rule_cache_data = null;
    protected mixed $url_cache_data = null;

    private array $routerGeneratedGetParams = [];

    private const RULE_CACHE_RULE_KEY = 'rule';
    private const RULE_CACHE_PARAMS_KEY = 'generated_get_params';
    
    // 性能优化：缓存统一缓存数据，避免重复读取
    // 注意：cache->get() 可能返回 false，所以使用 mixed 类型
    private mixed $unifiedCacheData = null;
    
    // 统一缓存结构键名（已移至 KeyBuilder 类，请使用 KeyBuilder::UNIFIED_CACHE_*_KEY）

    /**
     * 上次初始化的请求标识
     * 用于检测是否是新请求，避免重复初始化
     */
    private string $lastRequestId = '';

    /** @var array<string, array> */
    private static array $generatedRouterFileCache = [];

    /** @var array<string, int> */
    private static array $generatedRouterFileMtimes = [];

    /** @var array<string, bool> */
    private static array $generatedRouterFileFrozen = [];

    private const GENERATED_ROUTER_SNAPSHOT_DIR = 'generated_router_snapshots';

    /** @var array<string, array<string, array<string, array>>> */
    private static array $generatedRouterLookupCache = [];

    /** @var array<class-string, list<array{name:string, arguments:array}>> */
    private static array $controllerAttributeMetadataCache = [];
    
    /**
     * @DESC         |任何时候都会初始化
     *
     * 参数区：
     *
     */
    public function __init(): void
    {
        // 检测是否是新请求（通过请求 ID 判断，避免使用 WLS_MODE）
        // 在常驻内存模式下，Router 是进程级单例，需要每次请求刷新状态
        // 在 FPM 模式下，每次请求都是新进程，请求 ID 总是不同的
        $currentRequestId = $this->getCurrentRequestId();
        $isNewRequest = ($currentRequestId !== $this->lastRequestId);
        
        // 【关键】新请求到来时，必须重置所有请求级缓存属性
        // 这些属性在上一个请求中被填充，如果不清理会导致跨请求状态污染：
        // - unifiedCacheData：上个请求的统一缓存数据（包含路由、FPC、规则等），不清理会导致新请求使用旧路由
        // - rule_cache_data / url_cache_data：上个请求的路由规则缓存
        // - is_match：上个请求的路由匹配标志
        if ($isNewRequest) {
            $this->unifiedCacheData = null;
            $this->rule_cache_data = null;
            $this->url_cache_data = null;
            $this->is_match = false;
            $this->router = [];
            $this->url = '';
        }
        
        // 优先使用已注册的 Request（支持 WlsRequest 等替代实现）
        // 检查 ObjectManager 中是否已经有注册的 Request
        $resolvedClass = ObjectManager::parserClass(Request::class);
        $resolvedInstance = ObjectManager::_getInstance($resolvedClass);
        $requestInstance = ObjectManager::_getInstance(Request::class);
        if ($resolvedInstance !== null) {
            $this->request = $resolvedInstance;
        } elseif ($requestInstance !== null) {
            $this->request = $requestInstance;
        } else {
            // 如果没有注册的 Request，从 ObjectManager 获取
            $this->request = ObjectManager::getInstance(Request::class);
        }
        
        // 初始化框架缓存管理器
        if ($this->frameworkCacheManager === null) {
            $this->frameworkCacheManager = ObjectManager::getInstance(FrameworkCacheManager::class);
        }
        
        // 获取 router 缓存池
        if ($this->cache === null) {
            $this->cache = $this->frameworkCacheManager->pool('router');
        }

        // 每次 __init 都必须与当前请求的 $_SERVER / Request 对齐（WLS 下单例 Router 跨请求复用）。
        // 禁止用 empty(is_backend) 等短路：上一请求 is_backend=true 时若误判为同请求会沿用旧值，
        // 导致未带后台区域 key 的 URL 仍走后台 processUrl / 路由逻辑。
        $this->request_area = $this->request->getRequestArea();
        $this->area_router = (string) $this->request->getAreaRouter();
        $backendFlag = \w_env('is_backend', null);
        if ($backendFlag !== null) {
            $this->is_backend = (bool) $backendFlag;
        } else {
            $this->is_backend = is_int(strpos(strtolower($this->request_area), \Weline\Framework\Router\DataInterface::area_BACKEND));
        }
        
        $this->routerGeneratedGetParams = [];
        
        // 读取url
        $uri = (string) (\w_env('request.uri', $this->request->getUri()) ?? $this->request->getUri());
        $method = $this->request->getMethod() ?: 'GET';
        
        // 规范化 URI（去除查询参数），确保缓存键的一致性
        $uri = KeyBuilder::normalizeUri($uri);
        
        $this->url_cache_key = KeyBuilder::buildUrlCacheKey($uri, $method);
        $this->rule_cache_key = KeyBuilder::buildRuleCacheKey($uri, $method);
        $this->_router_cache_key = KeyBuilder::buildRouterStartCacheKey($uri, $method);
        $this->unified_cache_key = KeyBuilder::buildUnifiedRequestCacheKey('', $method);
        
        // 初始化 UrlProcessor
        if ($this->urlProcessor === null) {
            $this->urlProcessor = new UrlProcessor();
        }
        
        // 更新请求标识
        $this->lastRequestId = $currentRequestId;
    }
    
    /**
     * 获取当前请求的唯一标识
     * 
     * 使用请求 URI + 请求方法 + 微秒时间戳生成
     * 足以区分不同的请求
     */
    private function getCurrentRequestId(): string
    {
        // 使用 RequestContext 的请求 ID（如果可用）
        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            $contextId = \Weline\Framework\Runtime\RequestContext::getRequestId();
            if ($contextId !== null && $contextId !== '') {
                return $contextId;
            }
        }
        
        // 回退：使用请求 URI 生成标识
        $uri = (string) (\w_env('request.uri', '/') ?? '/');
        $method = (string) (\w_env('request.method', 'GET') ?? 'GET');
        $time = (float) (\w_env('request.time_float', \microtime(true)) ?? \microtime(true));
        
        return \md5($uri . $method . $time);
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
    
    /**
     * 获取框架缓存管理器
     * 
     * @return FrameworkCacheManager
     */
    public function getCacheManager(): FrameworkCacheManager
    {
        if ($this->frameworkCacheManager === null) {
            $this->frameworkCacheManager = ObjectManager::getInstance(FrameworkCacheManager::class);
        }
        return $this->frameworkCacheManager;
    }
    
    /**
     * 获取路由缓存池
     * 
     * @return CachePoolInterface
     */
    public function getRouterCache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->getCacheManager()->pool('router');
        }
        return $this->cache;
    }
    
    /**
     * 获取 URL 处理器
     * 
     * @return UrlProcessor
     */
    public function getUrlProcessor(): UrlProcessor
    {
        if ($this->urlProcessor === null) {
            $this->urlProcessor = new UrlProcessor();
        }
        return $this->urlProcessor;
    }

    private function getFullPageCacheCoordinator(): FullPageCacheCoordinator
    {
        if ($this->fullPageCacheCoordinator === null) {
            $this->fullPageCacheCoordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        }

        return $this->fullPageCacheCoordinator;
    }

    private function clearCurrentRequestRouteCaches(): void
    {
        $this->getRouterCache()->deleteMultiple([
            $this->url_cache_key,
            $this->rule_cache_key,
            $this->_router_cache_key,
            $this->unified_cache_key,
        ]);
        $this->unifiedCacheData = null;
        $this->router = [];
    }

    private function isFrontendRootRequest(): bool
    {
        if ($this->request_area !== \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND) {
            return false;
        }

        $uri = (string)(\w_env('request.uri', $this->request->getUri()) ?? '');
        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: $uri);

        return \trim($path, '/') === '';
    }

    private function shouldUseDirectGeneratedRouteFastPath(bool $hasPreviewTheme, bool $isFrontendRootRequest): bool
    {
        if ($this->is_backend || $hasPreviewTheme || $isFrontendRootRequest) {
            return false;
        }

        if ($this->request_area !== \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND) {
            return false;
        }

        if ($this->isStaticFile()) {
            return false;
        }

        return true;
    }

    private function resolveDirectGeneratedRouteUrl(): ?string
    {
        $url = $this->normalizeUrlTail($this->getStrippedUrlPath());
        if ($url === '') {
            return null;
        }

        if (!$this->hasGeneratedPcRoute($url, false)) {
            return null;
        }

        return $url;
    }

    private function hasGeneratedPcRoute(string $url, bool $isPcAdmin): bool
    {
        $url = $this->normalizeRouterUrlPathSegments($url);
        $routerFilepath = $isPcAdmin ? Env::path_BACKEND_PC_ROUTER_FILE : Env::path_FRONTEND_PC_ROUTER_FILE;
        $routers = self::loadGeneratedRouterFile($routerFilepath);
        if ($routers === []) {
            return false;
        }

        $requestMethod = strtoupper($this->request->getMethod() ?: 'GET');
        $method = '::' . $requestMethod;
        $getFallback = $requestMethod === 'HEAD' ? '::GET' : '';
        $defaultRoute = $isPcAdmin ? 'admin' : 'index/index';

        return self::resolveGeneratedRouterRule($routers, $url, $method, $getFallback, $defaultRoute) !== null;
    }

    private static function resolveGeneratedRouterRule(
        array $routers,
        string $url,
        string $method,
        string $getFallback,
        string $defaultRoute
    ): ?array {
        $candidates = [$url, $url . $method];
        if ($getFallback !== '') {
            $candidates[] = $url . $getFallback;
        }

        if (\in_array(\trim($url, '/'), ['', 'index', 'index/index'], true)) {
            $candidates[] = $defaultRoute;
            $candidates[] = $defaultRoute . $method;
            if ($getFallback !== '') {
                $candidates[] = $defaultRoute . $getFallback;
            }
            $candidates[] = '';
            $candidates[] = $method;
            if ($getFallback !== '') {
                $candidates[] = $getFallback;
            }
        }

        foreach (\array_unique($candidates) as $candidate) {
            if (isset($routers[$candidate]) && \is_array($routers[$candidate])) {
                return $routers[$candidate];
            }
        }

        return null;
    }

    /**
     * @DESC         |路由处理
     *
     * 参数区：
     *
     * @throws Exception
     * @throws \ReflectionException
     */
    public function start()
    {
        # ----------事件：路由开始前（控制器链路二层）------------
        static $eventManager = null;
        if ($eventManager === null) {
            $eventManager = ObjectManager::getInstance(EventsManager::class);
        }
        $eventData = ['router' => $this];
        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('controller_chain::router_before_start');
        }
        $eventManager->dispatch('Weline_Framework_Router::before_start', $eventData);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('controller_chain::router_before_start', (microtime(true) - $t0) * 1000, 'controller');
        }

        # 获取URL
        $this->url = $url = $this->processUrl();

        // 诊断日志：记录路由开始时的关键状态
        $originalUri = (string) (\w_env('full_request_uri', \w_env('request.uri', '')) ?? '');
        if (Env::get('wls.debug.hot_path_logs', false) && str_contains($originalUri, 'ai-site-agent')) {
            if (\class_exists(\Weline\Server\Log\WlsLogger::class)) {
                \Weline\Server\Log\WlsLogger::info_('[Router::start] ai-site-agent request', [
                    'request_uri' => \w_env('request.uri', '(empty)'),
                    'weline_area' => \w_env('area', '(empty)'),
                    'weline_is_backend' => ((\w_env('is_backend', false) ? 'true' : 'false')),
                    'is_backend' => ($this->is_backend ? 'true' : 'false'),
                    'request_area' => $this->request_area ?? '(empty)',
                    'area_router' => $this->area_router ?? '(empty)',
                    'processed_url' => $url,
                ]);
            }
        }
        $hasPreviewTheme = \w_env_get('preview_theme') !== null && (int)\w_env_get('preview_theme') > 0;
        $isFrontendRootRequest = $this->isFrontendRootRequest();
        $canUseRouteCache = !$this->is_backend && !$hasPreviewTheme && !$isFrontendRootRequest;
        
        
        // 性能优化：复用已读取的统一缓存数据
        if ($this->unifiedCacheData === null && $canUseRouteCache) {
            $cached = $this->cache->get($this->unified_cache_key);
            // 将 false 转换为 null，保持类型一致性
            $this->unifiedCacheData = ($cached === false) ? null : $cached;
        }
        
        // 优先从统一缓存中读取 router
        if (
            $canUseRouteCache
            && is_array($this->unifiedCacheData)
            && isset($this->unifiedCacheData[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY])
            && !empty($this->unifiedCacheData[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY])
        ) {
            $cachedRouter = $this->unifiedCacheData[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY];
            $this->router = $cachedRouter;
            return $this->route();
        }
        
        // 回退到旧的缓存方式（兼容性）
        $router = $canUseRouteCache ? $this->cache->get($this->_router_cache_key) : null;
        if ($router) {
            $this->router = $router;
            return $this->route();
        }
        # 后台接口请求
        
        switch ($this->request_area) {
            case \Weline\Framework\Controller\Data\DataInterface::type_api_BACKEND:
            case \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND:
                // API
                if (($api_result = $this->Api($url)) || $this->is_match) {
                    return $api_result;
                }
                $this->request->getResponse()->noRouter();
                break;
            case \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND:
            case \Weline\Framework\Controller\Data\DataInterface::type_pc_BACKEND:
                if (($pc_result = $this->Pc($url)) || $this->is_match) {
                    return $pc_result;
                }
                break;
            default:
                try {
                    // StaticFile 会抛出 StaticFileException，由 Runtime 层统一处理
                    $this->StaticFile($url, true);
                } catch (\Weline\Framework\Http\StaticFileException $e) {
                    // 静态文件响应异常，直接向上抛出由 Runtime 层处理
                    throw $e;
                } catch (\ReflectionException|Exception $e) {
                    $this->request->getResponse()->noRouter();
                }
        }
        // 非开发模式（匹配不到任何路由将报错）
        if (PROD) {
            // 诊断日志：记录 PROD 模式路由 404
            w_log_warning('[Router 404] No route matched in PROD mode | URL: ' . ($this->url ?? '(empty)')
                . ' | REQUEST_URI: ' . (\w_env('request.uri', '(empty)'))
                . ' | WELINE_AREA: ' . (\w_env('area', '(empty)'))
                . ' | request_area: ' . ($this->request_area ?? '(empty)')
                . ' | is_backend: ' . ($this->is_backend ? 'true' : 'false')
            );
            $this->request->getResponse()->noRouter();
        } else {
            // 开发模式(静态资源可访问app本地静态资源)
            // StaticFile 会抛出 StaticFileException，由 Runtime 层统一处理
            $this->StaticFile($url);
            // 如果没有抛出异常，说明文件不存在
            throw new \Weline\Framework\Http\NoRouterException(404, '未知的路由！');
        }
        return '';
    }

    /**
     * 获取去除区域路由前缀后的 URL 路径
     *
     * 后台 URL 结构为 /backendKey/currency/language/module/controller/action，
     * 解析后 getUrlPath() 为 /currency/language/admin/...（已去 backendKey），
     * 路由表键为 admin/controller/action，故需再去除前两段（货币+语言）再匹配。
     */
    private function getStrippedUrlPath(): string
    {
        $url = $this->request->getUrlPath();
        if ($this->is_backend || (\Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND === $this->request_area)) {
            $url = str_replace($this->area_router, '', $url);
        }
        $url = str_replace('//', '/', trim($url, '/'));
        // 后台：WELINE_AREA=backend 时 getAreaRouter() 为空，str_replace(area_router) 不会去掉入口 key。
        // 若 REQUEST_URI 仍含 /{backendPrefix}/...（解析未写回或上下文漂移），此处兜底剥掉首段，避免误匹配。
        if ($this->is_backend && $url !== '') {
            $backendPrefix = Env::getAreaRoutePrefix('backend');
            if (\is_string($backendPrefix) && $backendPrefix !== '') {
                $segments = \explode('/', $url);
                if (isset($segments[0]) && \strcasecmp((string)$segments[0], $backendPrefix) === 0) {
                    \array_shift($segments);
                    $url = \implode('/', $segments);
                }
            }
        }
        // 后台：路径可能为 currency/language/admin/...，需去掉前两段再与路由表匹配
        return $this->stripLeadingLocaleCurrencySegments($url);
    }

    private function stripLeadingLocaleCurrencySegments(string $url): string
    {
        $url = trim(str_replace('//', '/', $url), '/');
        if ($url === '') {
            return '';
        }

        $segments = explode('/', $url);
        $stripCount = 0;
        foreach (array_slice($segments, 0, 2) as $segment) {
            if ($this->isCurrencySegment($segment) || $this->isLocaleSegment($segment)) {
                $stripCount++;
                continue;
            }
            break;
        }

        if ($stripCount === 0 || count($segments) <= $stripCount) {
            return $url;
        }

        return implode('/', array_slice($segments, $stripCount));
    }

    private function isCurrencySegment(string $segment): bool
    {
        return State::isAllowedCurrencyCode($segment);
    }

    private function isLocaleSegment(string $segment): bool
    {
        $length = strlen($segment);
        return $length > 3
            && $length <= 16
            && ctype_lower(substr($segment, 0, 2))
            && isset($segment[2])
            && ($segment[2] === '_' || $segment[2] === '-');
    }
    
    /**
     * 规范化 URL 尾部：去除 trailing 'index' 段，修复双斜杠
     * 
     * 性能优化：提取重复逻辑，避免在 processUrl() 中多处重复
     * 
     * @param string $url 原始 URL
     * @return string 规范化后的 URL
     */
    private function normalizeUrlTail(string $url): string
    {
        $url = trim($url, self::url_path_split);
        $url_arr = explode('/', $url);
        $last_rule_value = $url_arr[array_key_last($url_arr)] ?? '';
        while ('index' === array_pop($url_arr)) {
            $last_rule_value = $url_arr[array_key_last($url_arr)] ?? '';
        }
        $url = implode('/', $url_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
        $url = trim($url, '/');
        $normalized = str_replace('//', '/', $url);
        return $normalized;
    }

    public function processUrl()
    {
        $hasPreviewTheme = \w_env_get('preview_theme') !== null && (int)\w_env_get('preview_theme') > 0;
        $isFrontendRootRequest = $this->isFrontendRootRequest();
        // 后端请求不缓存，直接跳过缓存读取
        if ($this->is_backend) {
            $this->routerGeneratedGetParams = [];
            $url = $this->getStrippedUrlPath();
            # ----------事件：处理url之前 开始------------
            /**@var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            /** @var DataObject $routerData */
            $routerData = new DataObject(['path' => $url, 'rule' => new DataObject()]);
            $originalGet = \w_env_get();
            $eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
            $pathData = $routerData->getData('path');
            $url = is_string($pathData) ? $pathData : (string)($pathData ?? '');
            $ruleData = $routerData->getData('rule');
            if (!($ruleData instanceof DataObject)) {
                $ruleDataArray = is_array($ruleData) ? $ruleData : [];
                $ruleData = new DataObject($ruleDataArray);
                $routerData->setData('rule', $ruleData);
            }
            /** @var DataObject $ruleData */
            $rule = $ruleData->getData();
            $this->routerGeneratedGetParams = $this->collectRouterGeneratedGetParams($originalGet);
            if (!empty($this->routerGeneratedGetParams)) {
                $this->applyRouterGeneratedGetParams();
            }

            # 将规则设置到请求类
            $this->request->setRule($rule);
            $this->request->setData($rule);
            # ----------事件：处理url之前 结束------------

            return $this->normalizeUrlTail($url);
        }

        if ($this->shouldUseDirectGeneratedRouteFastPath($hasPreviewTheme, $isFrontendRootRequest)) {
            $fastPathStart = RequestLifecycleTrace::isEnabled() ? \microtime(true) : 0.0;
            $directRouteUrl = $this->resolveDirectGeneratedRouteUrl();
            if ($directRouteUrl !== null) {
                $this->routerGeneratedGetParams = [];
                $this->request->setRule([]);
                $this->request->setData([]);
                if ($fastPathStart > 0) {
                    RequestLifecycleTrace::recordSpan(
                        'router::direct_generated_route_fast_path',
                        (\microtime(true) - $fastPathStart) * 1000,
                        'framework',
                        null,
                        ['url' => $directRouteUrl]
                    );
                }
                return $directRouteUrl;
            }
            if ($fastPathStart > 0) {
                RequestLifecycleTrace::recordSpan(
                    'router::direct_generated_route_fast_path_miss',
                    (\microtime(true) - $fastPathStart) * 1000,
                    'framework'
                );
            }
        }
        
        // 性能优化：复用已读取的统一缓存数据
        if ($this->unifiedCacheData === null && !$hasPreviewTheme && !$isFrontendRootRequest) {
            $cached = $this->cache->get($this->unified_cache_key);
            // 将 false 转换为 null，保持类型一致性
            $this->unifiedCacheData = ($cached === false) ? null : $cached;
        }
        
        // 优先尝试读取统一缓存（减少 IO 操作）
        if (!$hasPreviewTheme && !$isFrontendRootRequest && is_array($this->unifiedCacheData) && !empty($this->unifiedCacheData)) {
            $unifiedCache = $this->unifiedCacheData;
            // 从统一缓存中提取数据
            $url = $unifiedCache[KeyBuilder::UNIFIED_CACHE_URL_KEY] ?? null;
            $ruleFromCache = $unifiedCache[KeyBuilder::UNIFIED_CACHE_RULE_KEY] ?? [];
            $cachedGeneratedGetParams = $unifiedCache[KeyBuilder::UNIFIED_CACHE_PARAMS_KEY] ?? [];
            
            // 如果统一缓存中有路由信息，也设置到 router 属性
            if (isset($unifiedCache[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY])) {
                $this->router = $unifiedCache[KeyBuilder::UNIFIED_CACHE_ROUTER_KEY];
            }
            
            // 验证缓存的有效性
            if (PROD && $url && !empty($ruleFromCache)) {
                $this->url_cache_data = $url;
                $this->rule_cache_data = $ruleFromCache;
                $this->routerGeneratedGetParams = $cachedGeneratedGetParams;
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }
                # 将规则设置到请求类
                $this->request->setRule($ruleFromCache);
                $this->request->setData($ruleFromCache);
                return $url;
            }
        }
        
        // 从缓存池读取 URL 缓存
        $url = ($hasPreviewTheme || $isFrontendRootRequest) ? null : $this->cache->get($this->url_cache_key);
        {
            # 如果后缀是静态文件后缀 .css,.js,.jpg,.png,.jpeg,.gif,.svg,.ico,.woff,.woff2,.eot,.ttf,.otf,.ttf2,.woff3,.mp4,.mp3,.m3u8,.webp
            $isStaticFile = $this->isStaticFile();
            if ($isStaticFile) {
                // 确保 $url 不为 null，缓存未命中时从请求中获取
                if ($url === null || $url === false || $url === '') {
                    $url = (string) (\w_env('parser_url', '') ?? '');
                }
                if ($url !== '') {
                    try {
                        // StaticFile 会抛出 StaticFileException，由 Runtime 层统一处理
                        $this->StaticFile($url, true);
                    } catch (\Weline\Framework\Http\StaticFileException $e) {
                        // 静态文件响应异常，直接向上抛出由 Runtime 层处理
                        throw $e;
                    } catch (\ReflectionException|Exception $e) {
                        $this->request->getResponse()->noRouter();
                    }
                }
            }
            $ruleCache = $this->cache->get($this->rule_cache_key);
            [$ruleFromCache, $cachedGeneratedGetParams] = $this->normalizeRuleCache($ruleCache);
            // 修复：验证缓存的有效性，确保 rule 不为空且包含必要信息
            if (!$isFrontendRootRequest && PROD && $url && !empty($ruleFromCache)) {
                $this->url_cache_data = $url;
                $this->rule_cache_data = $ruleFromCache;
                $this->routerGeneratedGetParams = $cachedGeneratedGetParams;
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }
                # 将规则设置到请求类
                $this->request->setRule($ruleFromCache);
                $this->request->setData($ruleFromCache);
            } else {
                $this->routerGeneratedGetParams = [];
                $url = $this->getStrippedUrlPath();
                # ----------事件：处理url之前 开始------------
                /**@var EventsManager $eventManager */
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                /** @var DataObject $routerData */
                $routerData = new DataObject(['path' => $url, 'rule' => new DataObject()]);
                $originalGet = \w_env_get();
                $eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
                $pathData = $routerData->getData('path');
                $url = is_string($pathData) ? $pathData : (string)($pathData ?? '');
                $ruleData = $routerData->getData('rule');
                if (!($ruleData instanceof DataObject)) {
                    $ruleDataArray = is_array($ruleData) ? $ruleData : [];
                    $ruleData = new DataObject($ruleDataArray);
                    $routerData->setData('rule', $ruleData);
                }
                /** @var DataObject $ruleData */
                $rule = $ruleData->getData();
                $this->routerGeneratedGetParams = $this->collectRouterGeneratedGetParams($originalGet);
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }

                # 将规则设置到请求类
                $this->request->setRule($rule);
                $this->request->setData($rule);
                # ----------事件：处理url之前 结束------------

                $url = $this->normalizeUrlTail($url);
                
            }
        }
        return $url;
    }

    /**
     * @DESC         |api路由
     *
     * 参数区：
     *
     * @param string $url
     *
     * @return false|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function Api(string $url)
    {
        $url = $this->normalizeRouterUrlPathSegments($url);
        $is_api_admin = $this->request_area === \Weline\Framework\Controller\Data\DataInterface::type_api_BACKEND;

        if (!$is_api_admin && $this->isExternalBinQueryApiUrl($url)) {
            return $this->routeExternalBinQueryApi();
        }

        if (!$is_api_admin && $this->isFrontendQueryBinApiUrl($url)) {
            return $this->routeFrontendQueryBinApi();
        }

        if ($is_api_admin && $this->isBackendFrameworkQueryApiUrl($url)) {
            return $this->routeBackendFrameworkQueryApi();
        }

        if ($is_api_admin) {
            $router_filepath = Env::path_BACKEND_REST_API_ROUTER_FILE;
        } else {
            // 检测api路由
            $router_filepath = Env::path_FRONTEND_REST_API_ROUTER_FILE;
        }
        if (file_exists($router_filepath)) {
            $routers = self::loadGeneratedRouterFile($router_filepath);
            $requestMethod = strtoupper((string)($this->request->getMethod() ?: 'GET'));
            $matchedRouter = self::matchGeneratedRouterEntry($router_filepath, $routers, $url, $requestMethod, 'index/index');
            // HEAD 请求应该回退到 GET 路由（HTTP 规范：HEAD 返回与 GET 相同的响应头）
            if ($matchedRouter !== null) {
                // 优先处理没有请求方法后缀的路由（如 save 而不是 save::POST），这样可以避免需要强制使用 postSave 这样的命名
                // 对于 HEAD 请求，如果没有专门的 HEAD 路由，则回退到 GET 路由
                $this->router = $matchedRouter;
                # 缓存路由结果
                $this->router['type'] = 'api';
                if (!$is_api_admin) {
                    $this->cache->set($this->_router_cache_key, $this->router);
                }
                return $this->route();
            }
        }
        // 如果是API后端请求，找不到路由就直接404
        if ($is_api_admin) {
            $this->request->getResponse()->noRouter();
        }
        return false;
    }

    private function isFrontendQueryBinApiUrl(string $url): bool
    {
        $normalized = \strtolower(\trim($url, '/'));
        $restFrontendPrefix = \strtolower(\trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/'));
        $prefixedQueryBin = $restFrontendPrefix !== '' ? $restFrontendPrefix . '/framework/query-bin' : '';

        return $normalized === 'framework/query-bin'
            || $normalized === 'api/framework/query-bin'
            || ($prefixedQueryBin !== '' && $normalized === $prefixedQueryBin);
    }

    private function isExternalBinQueryApiUrl(string $url): bool
    {
        $normalized = \strtolower(\trim($url, '/'));
        $restFrontendPrefix = \strtolower(\trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/'));
        $prefixedBinQuery = $restFrontendPrefix !== '' ? $restFrontendPrefix . '/bin/query' : '';

        return $normalized === 'bin/query'
            || ($prefixedBinQuery !== '' && $normalized === $prefixedBinQuery);
    }

    private function isBackendFrameworkQueryApiUrl(string $url): bool
    {
        $normalized = \strtolower(\trim($url, '/'));
        $restBackendPrefix = \strtolower(\trim((string)(Env::getAreaRoutePrefix('rest_backend') ?: 'api_admin'), '/'));

        if ($restBackendPrefix !== '' && \str_starts_with($normalized, $restBackendPrefix . '/')) {
            $normalized = \substr($normalized, \strlen($restBackendPrefix) + 1);
        }

        return $normalized === 'framework/query'
            || $normalized === 'rest/v1/framework/query'
            || $normalized === 'framework/rest/v1/query';
    }

    private function routeBackendFrameworkQueryApi()
    {
        $this->router = [
            'module' => Env::MODULE_FRAMEWORK,
            'module_path' => Env::framework_code_path,
            'router' => 'framework',
            'class' => [
                'area' => \Weline\Framework\Controller\Data\DataInterface::type_api_BACKEND,
                'name' => \Weline\Framework\Controller\Backend\Api\Query::class,
                'controller_name' => 'Query',
                'method' => 'postIndex',
                'request_method' => 'POST',
            ],
            'type' => 'api',
        ];

        return $this->route();
    }

    private function routeExternalBinQueryApi()
    {
        $this->router = [
            'module' => Env::MODULE_FRAMEWORK,
            'module_path' => Env::framework_code_path,
            'router' => 'framework',
            'class' => [
                'area' => \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND,
                'name' => \Weline\Framework\Controller\Api\BinQuery::class,
                'controller_name' => 'BinQuery',
                'method' => 'postIndex',
                'request_method' => 'POST',
            ],
            'type' => 'api',
        ];

        return $this->route();
    }

    private function routeFrontendQueryBinApi()
    {
        $this->router = [
            'module' => Env::MODULE_FRAMEWORK,
            'module_path' => Env::framework_code_path,
            'router' => 'framework',
            'class' => [
                'area' => \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND,
                'name' => \Weline\Framework\Controller\Api\QueryBin::class,
                'controller_name' => 'QueryBin',
                'method' => 'postIndex',
                'request_method' => 'POST',
            ],
            'type' => 'api',
        ];

        return $this->route();
    }

    public static function resetGeneratedRouterFileCache(): void
    {
        self::$generatedRouterFileCache = [];
        self::$generatedRouterFileMtimes = [];
        self::$generatedRouterFileFrozen = [];
        self::$generatedRouterLookupCache = [];
    }

    public static function preloadGeneratedRouterFiles(): void
    {
        foreach (Env::router_files_PATH as $routerFilepath) {
            self::loadGeneratedRouterFile($routerFilepath);
        }
    }

    public static function snapshotGeneratedRouterFiles(): void
    {
        foreach (Env::router_files_PATH as $routerFilepath) {
            if (!is_file($routerFilepath)) {
                continue;
            }

            try {
                $routers = include $routerFilepath;
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($routers)) {
                $routers = [];
            }

            if ($routers === []) {
                self::deleteGeneratedRouterSnapshot($routerFilepath);
                continue;
            }

            self::writeGeneratedRouterSnapshot($routerFilepath, $routers);
        }
    }

    private static function loadGeneratedRouterFile(string $routerFilepath): array
    {
        $persistentRuntime = Runtime::isPersistent();
        $cachedRouters = self::$generatedRouterFileCache[$routerFilepath] ?? [];
        $hasCacheEntry = \array_key_exists($routerFilepath, self::$generatedRouterFileCache);
        $hasCachedRouters = $persistentRuntime && $cachedRouters !== [];

        if ($persistentRuntime && $hasCachedRouters) {
            return $cachedRouters;
        }

        if (!empty(self::$generatedRouterFileFrozen[$routerFilepath]) && $hasCachedRouters) {
            return $cachedRouters;
        }

        if (!is_file($routerFilepath)) {
            if ($hasCachedRouters) {
                self::$generatedRouterFileFrozen[$routerFilepath] = true;
                return $cachedRouters;
            }

            if ($persistentRuntime && self::shouldUseGeneratedRouterSnapshotFallback()) {
                $snapshotRouters = self::loadGeneratedRouterSnapshot($routerFilepath);
                if ($snapshotRouters !== []) {
                    self::$generatedRouterFileCache[$routerFilepath] = $snapshotRouters;
                    self::$generatedRouterFileMtimes[$routerFilepath] = 0;
                    self::$generatedRouterFileFrozen[$routerFilepath] = true;
                    return $snapshotRouters;
                }
            }

            if (!$persistentRuntime) {
                self::$generatedRouterFileCache[$routerFilepath] = [];
                self::$generatedRouterFileMtimes[$routerFilepath] = 0;
                unset(self::$generatedRouterLookupCache[$routerFilepath]);
            }
            return [];
        }

        $mtime = (int)(@\filemtime($routerFilepath) ?: 0);
        if (!$hasCacheEntry
            || (self::$generatedRouterFileMtimes[$routerFilepath] ?? -1) !== $mtime
            || ($persistentRuntime && $cachedRouters === [])
        ) {
            try {
                $routers = include $routerFilepath;
            } catch (\Throwable $throwable) {
                if ($hasCachedRouters) {
                    self::$generatedRouterFileFrozen[$routerFilepath] = true;
                    return $cachedRouters;
                }

                if ($persistentRuntime && self::shouldUseGeneratedRouterSnapshotFallback()) {
                    $snapshotRouters = self::loadGeneratedRouterSnapshot($routerFilepath);
                    if ($snapshotRouters !== []) {
                        self::$generatedRouterFileCache[$routerFilepath] = $snapshotRouters;
                        self::$generatedRouterFileMtimes[$routerFilepath] = $mtime;
                        self::$generatedRouterFileFrozen[$routerFilepath] = true;
                        return $snapshotRouters;
                    }
                }

                throw $throwable;
            }

            $routers = \is_array($routers) ? $routers : [];
            if ($routers === [] && $hasCachedRouters) {
                self::$generatedRouterFileFrozen[$routerFilepath] = true;
                return $cachedRouters;
            }
            if ($routers === [] && $persistentRuntime) {
                if (self::shouldUseGeneratedRouterSnapshotFallback()) {
                    $snapshotRouters = self::loadGeneratedRouterSnapshot($routerFilepath);
                    if ($snapshotRouters !== []) {
                        self::$generatedRouterFileCache[$routerFilepath] = $snapshotRouters;
                        self::$generatedRouterFileMtimes[$routerFilepath] = $mtime;
                        self::$generatedRouterFileFrozen[$routerFilepath] = true;
                        return $snapshotRouters;
                    }
                }

                unset(
                    self::$generatedRouterFileCache[$routerFilepath],
                    self::$generatedRouterFileMtimes[$routerFilepath],
                    self::$generatedRouterFileFrozen[$routerFilepath],
                    self::$generatedRouterLookupCache[$routerFilepath]
                );
                return [];
            }

            self::$generatedRouterFileCache[$routerFilepath] = $routers;
            self::$generatedRouterFileMtimes[$routerFilepath] = $mtime;
            if ($persistentRuntime) {
                self::$generatedRouterFileFrozen[$routerFilepath] = true;
                self::writeGeneratedRouterSnapshot($routerFilepath, $routers);
            }
            unset(self::$generatedRouterLookupCache[$routerFilepath]);
        }

        return self::$generatedRouterFileCache[$routerFilepath];
    }

    private static function shouldUseGeneratedRouterSnapshotFallback(): bool
    {
        if (Runtime::isPersistent()) {
            return true;
        }

        $processDir = BP . 'var' . DS . 'process' . DS;

        return is_file($processDir . 'setup_upgrade.lock')
            || is_file($processDir . 'setup_upgrade_recollect.flag');
    }

    private static function loadGeneratedRouterSnapshot(string $routerFilepath): array
    {
        $snapshotFile = self::getGeneratedRouterSnapshotFile($routerFilepath);
        if (!is_file($snapshotFile)) {
            return [];
        }

        try {
            $routers = include $snapshotFile;
        } catch (\Throwable) {
            return [];
        }

        return is_array($routers) ? $routers : [];
    }

    private static function writeGeneratedRouterSnapshot(string $routerFilepath, array $routers): void
    {
        if ($routers === []) {
            self::deleteGeneratedRouterSnapshot($routerFilepath);
            return;
        }

        $snapshotFile = self::getGeneratedRouterSnapshotFile($routerFilepath);
        $snapshotDir = dirname($snapshotFile);
        if (!is_dir($snapshotDir) && !@mkdir($snapshotDir, 0755, true) && !is_dir($snapshotDir)) {
            return;
        }

        $tmpFile = $snapshotFile . '.' . getmypid() . '.tmp';
        $fh = @fopen($tmpFile, 'wb');
        if ($fh === false) {
            return;
        }

        fwrite($fh, "<?php return [\n");
        $first = true;
        foreach ($routers as $key => $value) {
            if (!$first) {
                fwrite($fh, ",\n");
            }
            $first = false;
            fwrite($fh, var_export($key, true) . ' => ' . var_export($value, true));
        }
        fwrite($fh, "\n];\n");
        fclose($fh);

        @unlink($snapshotFile);
        @rename($tmpFile, $snapshotFile);
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    private static function deleteGeneratedRouterSnapshot(string $routerFilepath): void
    {
        $snapshotFile = self::getGeneratedRouterSnapshotFile($routerFilepath);
        if (is_file($snapshotFile)) {
            @unlink($snapshotFile);
        }
    }

    private static function getGeneratedRouterSnapshotFile(string $routerFilepath): string
    {
        return BP . 'var' . DS . 'cache' . DS . self::GENERATED_ROUTER_SNAPSHOT_DIR
            . DS . sha1($routerFilepath) . '-' . basename($routerFilepath);
    }

    private static function matchGeneratedRouterEntry(
        string $routerFilepath,
        array $routers,
        string $url,
        string $requestMethod,
        string $defaultRoute
    ): ?array {
        $index = self::getGeneratedRouterLookupIndex($routerFilepath, $routers);
        $requestMethod = \strtoupper($requestMethod ?: 'GET');
        $fallbackMethod = $requestMethod === 'HEAD' ? 'GET' : null;
        $paths = [$url];
        if ($url === '' && $defaultRoute !== '') {
            $paths[] = $defaultRoute;
        }

        foreach ($paths as $path) {
            $routesForPath = $index[$path] ?? null;
            if ($routesForPath === null) {
                continue;
            }
            if (isset($routesForPath['*'])) {
                return $routesForPath['*'];
            }
            if (isset($routesForPath[$requestMethod])) {
                return $routesForPath[$requestMethod];
            }
            if ($fallbackMethod !== null && isset($routesForPath[$fallbackMethod])) {
                return $routesForPath[$fallbackMethod];
            }
        }

        return null;
    }

    private static function getGeneratedRouterLookupIndex(string $routerFilepath, array $routers): array
    {
        if (isset(self::$generatedRouterLookupCache[$routerFilepath])) {
            return self::$generatedRouterLookupCache[$routerFilepath];
        }

        $index = [];
        foreach ($routers as $routeKey => $router) {
            if (!\is_string($routeKey) || !\is_array($router)) {
                continue;
            }

            $path = $routeKey;
            $method = '*';
            $methodSeparator = \strrpos($routeKey, '::');
            if ($methodSeparator !== false) {
                $path = \substr($routeKey, 0, $methodSeparator);
                $method = \strtoupper(\substr($routeKey, $methodSeparator + 2));
            }

            $index[$path][$method] = $router;
        }

        return self::$generatedRouterLookupCache[$routerFilepath] = $index;
    }

    /**
     * 将 path 各段规范为与路由表一致的「小写 + 连字符」形式（PC 与 REST API 路由表均如此注册）。
     *
     * 路由注册（Module\Helper\Data）把控制器/动作从 PascalCase、camelCase 转为连字符；
     * 若对整段 URL 先 strtolower，会抹掉 aiSiteAgent 中的大写边界，无法匹配 ai-site-agent。
     */
    private function normalizeRouterUrlPathSegments(string $url): string
    {
        $url = trim($url, '/');
        if ($url === '') {
            return '';
        }
        $segments = explode('/', $url);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            $s = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $segment) ?? $segment;
            $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $s) ?? $s;
            $segments[$i] = strtolower($s);
        }

        return implode('/', $segments);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $url
     *
     * @return false|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function Pc(string $url)
    {
        $url = $this->normalizeRouterUrlPathSegments($url);
        $is_pc_admin = $this->request_area === \Weline\Framework\Controller\Data\DataInterface::type_pc_BACKEND;
        if (!$is_pc_admin && $this->isExternalBinQueryApiUrl($url)) {
            return $this->routeExternalBinQueryApi();
        }
        // 检测api路由区域
        if ($is_pc_admin) {
            $router_filepath = Env::path_BACKEND_PC_ROUTER_FILE;
        } else {
            $router_filepath = Env::path_FRONTEND_PC_ROUTER_FILE;
        }
        try {
            $routers = self::loadGeneratedRouterFile($router_filepath);
        } catch (\Throwable $includeE) {
            throw $includeE;
        }
        $requestMethod = strtoupper((string)($this->request->getMethod() ?: 'GET'));
        // HEAD 请求应该回退到 GET 路由（HTTP 规范：HEAD 返回与 GET 相同的响应头）
        // 处理空路径：后台请求使用 'admin' 作为默认路由，前端请求使用 'index/index'
        $defaultRoute = $is_pc_admin ? 'admin' : 'index/index';

        // URL 已经正确保留了 admin/ 前缀（如 admin/login），不再需要 adminPrefixedUrl 补丁
        $matchedRouter = self::matchGeneratedRouterEntry($router_filepath, $routers, $url, $requestMethod, $defaultRoute);
        if ($matchedRouter !== null) {
            // 优先处理没有请求方法后缀的路由（如 save 而不是 save::POST），这样可以避免需要强制使用 postSave 这样的命名
            // 对于 HEAD 请求，如果没有专门的 HEAD 路由，则回退到 GET 路由
            $this->router = $matchedRouter;
            
            # 缓存路由结果
            $this->router['type'] = 'pc';
            if (!$is_pc_admin) {
                $this->cache->set($this->_router_cache_key, $this->router);
            }

            return $this->route();
        }
        // 如果是PC后端请求，找不到路由就直接404
        if ($is_pc_admin) {
            // 诊断日志：记录后台路由 404 的关键信息，便于排查间歇性 404 问题
            w_log_warning('[Router 404] Backend route not found | URL: ' . $url 
                . ' | Method: ' . ($requestMethod ?? 'GET')
                . ' | REQUEST_URI: ' . (\w_env('request.uri', '(empty)'))
                . ' | WELINE_AREA: ' . (\w_env('area', '(empty)'))
                . ' | is_backend: ' . ($this->is_backend ? 'true' : 'false')
                . ' | area_router: ' . ($this->area_router ?? '(empty)')
                . ' | router_file: ' . ($router_filepath ?? '(empty)')
                . ' | file_exists: ' . (isset($router_filepath) && is_file($router_filepath) ? 'true' : 'false')
            );
            $this->request->getResponse()->noRouter();
        }

        return false;
    }

    /**
     * @DESC         |返回开发静态文件
     *
     * 参数区：
     *
     * @param string $url
     * @param bool $is_media
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     * @throws \Weline\Framework\Http\StaticFileException 静态文件响应
     */
    public function StaticFile(string &$url, bool $is_media = false): mixed
    {
        # 卸载Cookie
        Cookie::static_file();
        if ($is_media) {
            $filename = BP . trim($url, DS);
            $filename = str_replace('/', DS, $filename);
            # 修复静态资源路径\\ 或者 // 等错误路径修复
            $filename = str_replace(DS . DS, DS, $filename);
        } else {
            $filename = APP_CODE_PATH . trim($url, DS);
            $filename = str_replace('/', DS, $filename);
            # 修复静态资源路径\\ 或者 // 等错误路径修复
            $filename = str_replace(DS . DS, DS, $filename);
        }

        // 阻止读取其他文件
        if (!$is_media && !str_contains($filename, \Weline\Framework\View\Data\DataInterface::dir)) {
            $this->request->getResponse()->noRouter();
        }
        if (!is_file($filename)) {
            # 检测vendor目录的组件文件 
            $filename = VENDOR_PATH . trim($url, DS);
            if (!is_file($filename)) {
                # 检测vendor目录的组件文件
                $split_array = explode('/', $url);
                $module = array_shift($split_array) . '_' . array_shift($split_array);
                $base_path = Env::getInstance()->getModuleInfo($module)['base_path'] ?? '';
                $filename = $base_path . trim(implode('/', $split_array), DS);
                $filename = str_replace('/', DS, $filename);
            }
        }
        if (is_file($filename)) {
            // Handle caching
            $fileModificationTime = gmdate('D, d M Y H:i:s', filemtime($filename)) . ' GMT';
            $ifModifiedSince = $this->getRequestHeaderValue($this->getRequestHeaders(), 'If-Modified-Since');
            if ($ifModifiedSince === $fileModificationTime) {
                $response304 = (new Response(true))
                    ->setHeaders($this->buildCacheHeaders($fileModificationTime, $filename))
                    ->setHttpResponseCode(304)
                    ->setBody('');
                throw new \Weline\Framework\Http\ResponseTerminateException($response304);
            }
            
            // 构建缓存响应头
            $cacheHeaders = $this->buildCacheHeaders($fileModificationTime, $filename);

            $filename_arr = explode('.', $filename);
            $file_ext = end($filename_arr);
            if ($file_ext === 'css') {
                $mime_type = 'text/css';
            } elseif ($file_ext === 'js') {
                $mime_type = 'text/javascript';
            } else {
                $fi = new \finfo(FILEINFO_MIME_TYPE);
                $mime_type = $fi->file($filename);
            }

            // 合并响应头
            $responseHeaders = array_merge($cacheHeaders, [
                'Content-Type' => $mime_type,
                'Content-Length' => (string)\filesize($filename),
            ]);
            
            $fileResponse = (new Response(true))
                ->setHeaders($responseHeaders)
                ->setHttpResponseCode(200)
                ->setBody((string)\file_get_contents($filename));
            throw new \Weline\Framework\Http\ResponseTerminateException($fileResponse);
        }
        // 文件不存在：后缀为 .css/.js 时返回 404 + 对应 MIME，避免浏览器 MIME 严格检查报错
        $url_path = preg_replace('/\?.*/', '', str_replace('\\', '/', trim($url, '/')));
        $seg = explode('.', $url_path);
        $ext = strtolower($seg[count($seg) - 1] ?? '');
        if ($ext === 'css') {
            $response404Css = (new Response(true))
                ->setHeader('Content-Type', 'text/css; charset=utf-8')
                ->setHttpResponseCode(404)
                ->setBody('');
            throw new \Weline\Framework\Http\ResponseTerminateException($response404Css);
        }
        if ($ext === 'js' || $ext === 'mjs') {
            $response404Js = (new Response(true))
                ->setHeader('Content-Type', 'text/javascript; charset=utf-8')
                ->setHttpResponseCode(404)
                ->setBody('');
            throw new \Weline\Framework\Http\ResponseTerminateException($response404Js);
        }
        return false;
    }

    private function getRequestHeaders(): array
    {
        if (\function_exists('getallheaders')) {
            $headers = \getallheaders();
            if (\is_array($headers)) {
                return $headers;
            }
        }

        if (\method_exists($this->request, 'getHeaders')) {
            $headers = $this->request->getHeaders();
            if (\is_array($headers)) {
                return $headers;
            }
        }

        $server = $this->request->getServer();
        if (!\is_array($server)) {
            return [];
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                continue;
            }

            if (\str_starts_with($key, 'HTTP_')) {
                $headerName = \str_replace(' ', '-', \ucwords(\strtolower(\str_replace('_', ' ', \substr($key, 5)))));
                $headers[$headerName] = (string)$value;
                continue;
            }

            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = (string)$value;
                continue;
            }

            if ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = (string)$value;
            }
        }

        return $headers;
    }

    private function getRequestHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (\strcasecmp((string)$headerName, $name) !== 0) {
                continue;
            }

            if (\is_array($value)) {
                return \implode(', ', \array_map('strval', $value));
            }

            return \is_scalar($value) ? (string)$value : '';
        }

        return '';
    }
    
    /**
     * 构建缓存响应头（不直接发送 header）
     * 
     * @param string $fileModificationTime 文件修改时间
     * @param string $filename 文件名
     * @return array 响应头数组
     */
    private function buildCacheHeaders(string $fileModificationTime, string $filename): array
    {
        $headers = [];
        $filename_arr = explode('.', $filename);
        $file_ext = end($filename_arr);
        
        // 根据环境设置缓存策略
        if (PROD) {
            // 生产环境：长缓存
            $headers['Cache-Control'] = 'public, max-age=31536000';
            $headers['Expires'] = gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT';
        } else {
            // 开发环境：短缓存，方便调试
            $headers['Cache-Control'] = 'public, max-age=60';
            $headers['Expires'] = gmdate('D, d M Y H:i:s', time() + 60) . ' GMT';
        }
        
        $headers['Last-Modified'] = $fileModificationTime;
        
        return $headers;
    }


    public function getController(array $router): array
    {
        if ($this->is_backend) {
            return [
                $router['class']['name'] ?? '',
                $router['class']['method'] ?: 'index',
            ];
        }

        $controller_cache_controller_key = 'controller_cache_key_' . implode('_', $router['class']) . '_controller';
        $controller_cache_method_key = 'controller_cache_key_' . implode('_', $router['class']) . '_method';
        $dispatch = $this->cache->get($controller_cache_controller_key);
        $dispatch_method = $this->cache->get($controller_cache_method_key);
        if ($dispatch && $dispatch_method) {
            return [$dispatch, $dispatch_method];
        } else {
            $class_name = $router['class']['name'] ?? '';
            $method = $router['class']['method'] ?: 'index';
            $this->cache->set($controller_cache_method_key, $method);
            $this->cache->set($controller_cache_controller_key, $class_name);
            return [$class_name, $method];
        }
    }

    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws \Exception
     */
    public function route()
    {
        $routeProfileStart = \microtime(true);
        $routeProfileLast = $routeProfileStart;
        $profileUri = (string) (\w_env('full_request_uri', \w_env('request.uri', '')) ?? '');
        $routeProfile = [
            'uri' => $profileUri,
            'method' => (string)($this->request->getMethod() ?: ''),
            'area' => $this->request_area,
            'area_router' => $this->area_router,
            'backend' => $this->is_backend ? 1 : 0,
        ];
        $routeProfileMark = static function (string $name) use (&$routeProfile, &$routeProfileLast, $routeProfileStart): void {
            $now = \microtime(true);
            $routeProfile[$name . '_ms'] = \round(($now - $routeProfileLast) * 1000, 2);
            $routeProfileLast = $now;
            $routeProfile['elapsed_ms'] = \round(($now - $routeProfileStart) * 1000, 2);
        };
        $routeProfilePublish = static function (string $stage = 'return') use (&$routeProfile, $routeProfileStart, $profileUri): void {
            $now = \microtime(true);
            $routeProfile['stage'] = $stage;
            $routeProfile['total_ms'] = \round(($now - $routeProfileStart) * 1000, 2);
            if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
                RequestContext::set('router.start.profile', $routeProfile);
            }
            $uriForLog = $profileUri !== '' ? $profileUri : (string)($routeProfile['uri'] ?? '');
            if (
                ($routeProfile['total_ms'] ?? 0) >= 100.0
                || \str_contains($uriForLog, 'customer/account')
                || \str_contains($uriForLog, 'query-bin')
            ) {
                \error_log('[RouterPerf] route ' . \json_encode($routeProfile, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        };
        $routeProfileLogThrowable = static function (string $stage, \Throwable $e) use (&$routeProfile): void {
            $payload = $routeProfile;
            $payload['stage'] = $stage;
            $payload['exception_class'] = \get_class($e);
            $payload['exception_message'] = $e->getMessage();
            $payload['exception_file'] = $e->getFile();
            $payload['exception_line'] = $e->getLine();
            \error_log('[RouterError] route ' . \json_encode(
                $payload,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR
            ));
        };
        // 安全检查：确保 router 存在且格式正确
        if (empty($this->router) || !\is_array($this->router)) {
            $this->request->getResponse()->noRouter();
            return '';
        }
        
        # 检测模块状态
        $module = $this->router['module'] ?? null;
        if (empty($module)) {
            $this->request->getResponse()->noRouter();
            return '';
        }
        
        if (!Env::getInstance()->getModuleStatus($module)) {
            $this->request->getResponse()->noRouter();
        }
        
        # 检查headers already sent 是否已发送
        # 页头阻止XSS
        $this->header_xss();

        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        $fpcCoordinator = null;
        $fpcBuildLock = null;

        try {
            if (!$this->is_backend && $routerCacheEnabled && $frontendCacheEnabled) {
                $fpcCoordinator = $this->getFullPageCacheCoordinator();
                if (!$fpcCoordinator->canServeCachedResponse($this->request->getMethod() ?: 'GET')) {
                    $fpcCoordinator = null;
                }
            }

            if ($fpcCoordinator !== null) {
                $cachedResponse = $fpcCoordinator->getCachedResponse($this->request->getMethod() ?: 'GET');
                if ($cachedResponse !== null) {
                    $this->is_match = true;
                    $routeProfile['fpc'] = 'hit';
                    $routeProfilePublish('fpc_hit');
                    return $cachedResponse;
                }

                if (Runtime::isPersistent()
                    && (bool)Env::get('wls.performance.fpc_serve_stale_before_build', true)
                ) {
                    $cachedResponse = $fpcCoordinator->getStaleCachedResponseForRebuild($this->request->getMethod() ?: 'GET');
                    if ($cachedResponse !== null) {
                        $this->is_match = true;
                        $routeProfile['fpc'] = 'stale_before_build';
                        $routeProfilePublish('fpc_stale_before_build');
                        return $cachedResponse;
                    }
                }

                $fpcBuildLock = $fpcCoordinator->acquireBuildLock($this->request->getMethod() ?: 'GET');
                if ($fpcBuildLock === null) {
                    $cachedResponse = $fpcCoordinator->waitForPublishedResponse($this->request->getMethod() ?: 'GET');
                    if ($cachedResponse !== null) {
                        $this->is_match = true;
                        $routeProfile['fpc'] = 'wait_hit';
                        $routeProfilePublish('fpc_wait_hit');
                        return $cachedResponse;
                    }
                }
            }
        
        # 方法体方法和请求方法不匹配时 禁止访问
        # HEAD 请求应该被允许访问 GET 方法的路由（HTTP 规范：HEAD 返回与 GET 相同的响应头）
        $routeProfile['fpc'] = $fpcCoordinator === null ? 'disabled' : ($fpcBuildLock === null ? 'wait_miss' : 'build_lock');
        $routeProfileMark('fpc_probe');
        $routeMethod = $this->router['class']['request_method'] ?? '';
        $currentRequestMethod = $this->request->getMethod();
        if ('' !== $routeMethod) {
            // HEAD 请求可以访问 GET 路由
            $isHeadToGet = ($currentRequestMethod === 'HEAD' && $routeMethod === 'GET');
            if ($routeMethod !== $currentRequestMethod && !$isHeadToGet) {
                $this->request->getResponse()->noRouter();
            }
        }
        
        $this->request->setRouter($this->router);
        
        list($dispatch, $method) = $this->getController($this->router);
        $routeProfile['module'] = (string)($this->router['module'] ?? '');
        $routeProfile['controller'] = (string)$dispatch;
        $routeProfile['action'] = (string)$method;
        $routeProfileMark('controller_resolve');
        $originalUri = (string) (\w_env('full_request_uri', \w_env('request.uri', '')) ?? '');
        if (
            Env::get('wls.debug.hot_path_logs', false)
            && \str_contains($originalUri, 'ai-site-agent')
            && \class_exists(\Weline\Server\Log\WlsLogger::class)
        ) {
            \Weline\Server\Log\WlsLogger::info_('[Router::route] controller_resolved', [
                'uri' => $originalUri,
                'request_area' => $this->request_area,
                'area_router' => $this->area_router,
                'is_backend' => $this->is_backend,
                'router_class' => (string)($this->router['class']['name'] ?? ''),
                'router_method' => (string)($this->router['class']['method'] ?? ''),
                'dispatch' => (string)$dispatch,
                'method' => (string)$method,
            ]);
        }
        
        // 解析注解
        foreach (self::getControllerAttributeMetadata((string)$dispatch) as $attribute) {
            $dispatchAttribute = ObjectManager::getInstance($attribute['name'], $attribute['arguments']);
            if (method_exists($dispatchAttribute, 'execute')) {
                $result = $dispatchAttribute->execute();
                if ($result) {
                    $routeProfileMark('attribute_execute');
                    $routeProfilePublish('attribute_return');
                    return $this->resolveRequestScopedResponse($result, '');
                }
            }
        }
        $routeProfileMark('reflection_attrs');
        
        /**@var \Weline\Framework\Controller\Core $dispatch */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = ['route' => $this];

        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('controller_chain::route_before');
        }
        $eventManager->dispatch('Weline_Framework_Router::route_before', $eventData);
        $routeProfileMark('route_before');
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('controller_chain::route_before', (microtime(true) - $t0) * 1000, 'controller');
        }

        $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        $dispatch = ObjectManager::getInstance((string)$dispatch);
        $dispatch->__setModuleInfo($this->router);
        $routeProfileMark('controller_init');
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::recordSpan('controller_chain::controller_init', (microtime(true) - $t0) * 1000, 'controller');
        }
        # 检测控制器方法
        if (!method_exists($dispatch, $method)) {
            $dispatch_class = $dispatch::class;
            throw new Exception(__('%{1}: 控制器方法 %{2} 不存在!', [$dispatch_class, $method]));
        }
        FiberOutputBuffer::beginCapture();

        // 将 action_execute 设为当前父节点，控制器内通过 RequestLifecycleTrace::recordSpan() 打的子 span 会挂到本节点下，便于拆分 1.x s 耗时
        $actionSpanStart = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('controller_chain::action_execute');
        }
        try {
            $result = call_user_func([$dispatch, $method], /*...$this->request->getParams()*/);
            $routeProfileMark('action_execute');
            // 检测是否是流式响应（SSE）- 如果是，直接返回，不进行后续处理
            // 仅依赖 headers_list 在部分 FPM 场景会失效，因此优先检查 SseContext 开关。
            if (SseContext::isSseEnabled()) {
                FiberOutputBuffer::discardCapture();
                $this->is_match = true;
                $routeProfilePublish('sse_context_return');
                return;
            }
            $currentHeaders = headers_list();
            $acceptHeader = strtolower((string)($this->request->getHeader('Accept') ?? ''));
            $isSseAcceptRequest = str_contains($acceptHeader, 'text/event-stream');
            foreach ($currentHeaders as $header) {
                if ($isSseAcceptRequest && stripos($header, 'Content-Type: text/event-stream') !== false) {
                    // 清理输出缓冲区并直接返回（流式响应已经发送）
                    FiberOutputBuffer::discardCapture();
                    $this->is_match = true;
                    $routeProfilePublish('sse_accept_return');
                    return;
                }
            }
            
            # ----------事件：route_after（控制器链路二层）------------
            $t0 = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('controller_chain::route_after');
            }
            $resultData = new DataObject(['result' => $result, 'route' => $this]);
            $eventData = ['data' => $resultData];
            $eventManager->dispatch('Weline_Framework_Router::route_after', $eventData);
            $routeProfileMark('route_after');
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('controller_chain::route_after', (microtime(true) - $t0) * 1000, 'controller');
            }
            // 获取输出缓冲区内容（控制器可能直接输出而不是返回）
            $output = FiberOutputBuffer::endCapture();
            $routeProfileMark('end_capture');
            // 如果控制器返回了结果，优先使用返回值；否则使用输出缓冲区内容
            $fpcHtml = !empty($result) ? (is_string($result) ? $result : $output) : $output;
            
            $response = $this->resolveRequestScopedResponse($result, $output);
            $fpcHtml = $response->getBody();
            $routeProfileMark('response_resolve');
        } catch (\Weline\Framework\Http\RedirectException $redirectEx) {
            // 重定向异常：直接重新抛出，让 WlsRuntime 处理
            // 异常情况下清理输出缓冲区
            FiberOutputBuffer::discardCapture();
            $routeProfilePublish('redirect_exception');
            throw $redirectEx;
        } catch (\Exception $e) {
            // 异常情况下清理输出缓冲区
            FiberOutputBuffer::discardCapture();
            DebugLogger::logException($e);
            $routeProfileLogThrowable('exception', $e);
            $routeProfilePublish('exception');
            throw $e;
        } catch (\Throwable $e) {
            // 异常情况下清理输出缓冲区
            FiberOutputBuffer::discardCapture();
            DebugLogger::logThrowable($e);
            $routeProfileLogThrowable('throwable', $e);
            $routeProfilePublish('throwable');
            throw $e;
        } finally {
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('controller_chain::action_execute', (microtime(true) - $actionSpanStart) * 1000, 'controller');
            }
        }

        $this->is_match = true;
        # 最后输出前 保证真实可靠的URL才进行缓存
        if (is_null($this->request->uri_cache_url_path_data)) {
            $this->request->cache->set($this->request->uri_cache_key, $this->request->getUri());
        }
        $routeProfileMark('uri_cache');
        
        // 后端请求不缓存，只缓存前端请求
        // 检查全页缓存是否启用（检查 router_cache 和 frontend_cache 配置）
        // 使用静态方法 Env::get()，使用点号分隔符访问嵌套配置
        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        // 编辑器预览模式不写入全页缓存
        $isEditorMode = \w_env_get('editor_mode') !== null && (\w_env_get('editor_mode') === '1' || \w_env_get('editor_mode') === 'true');
        $skipCacheDerivedResponseWrites = isset($response)
            && $response instanceof Response
            && $this->shouldSkipFpcPublishForCachedControllerResponse($response);
        if (
            !$this->is_backend
            && !$isEditorMode
            && $routerCacheEnabled
            && $frontendCacheEnabled
            && !empty($fpcHtml)
            && $fpcCoordinator !== null
            && $fpcBuildLock !== null
            && !$skipCacheDerivedResponseWrites
            && $fpcCoordinator->canPublishResponse($response, $this->request->getMethod() ?: 'GET')
        ) {
            $fpcCoordinator->publishResponse(
                $response,
                $this->url,
                $this->request->getRule(),
                $this->router,
                $this->routerGeneratedGetParams,
                $this->request->getMethod() ?: 'GET'
            );
        }
        // 兼容性：如果 url_cache_data 为空，也保存到旧的缓存键
        $routeProfileMark('fpc_publish');
        $routeCacheMethod = \strtoupper((string)($this->request->getMethod() ?: 'GET'));
        $canWriteRouteCache = $routeCacheMethod === 'GET' || $routeCacheMethod === 'HEAD';
        if (!$this->is_backend && !$this->url_cache_data && $canWriteRouteCache && !$skipCacheDerivedResponseWrites) {
            $ruleCachePayload = [
                self::RULE_CACHE_RULE_KEY => $this->request->getRule(),
                self::RULE_CACHE_PARAMS_KEY => $this->routerGeneratedGetParams,
            ];
            $this->cache->set($this->rule_cache_key, $ruleCachePayload);
            $this->cache->set($this->url_cache_key, $this->url);
        }
        // 返回结果（如果控制器返回了值）或输出缓冲区内容
        $routeProfileMark('rule_cache');
        $routeProfilePublish('return');
        return $response ?? $this->resolveRequestScopedResponse(!empty($result) ? $result : $fpcHtml, '');
        } finally {
            if ($fpcCoordinator !== null) {
                $fpcCoordinator->releaseBuildLock($fpcBuildLock);
            }
        }
    }

    private function shouldSkipFpcPublishForCachedControllerResponse(Response $response): bool
    {
        $fpcHit = $response->getHeader('X-Wls-Performance-Fpc-Hit');
        if (\is_array($fpcHit)) {
            $fpcHit = \implode(',', $fpcHit);
        }

        return \trim((string)$fpcHit) === '1';
    }

    /**
     * @return list<array{name:string, arguments:array}>
     * @throws \ReflectionException
     */
    private static function getControllerAttributeMetadata(string $dispatch): array
    {
        if (isset(self::$controllerAttributeMetadataCache[$dispatch])) {
            return self::$controllerAttributeMetadataCache[$dispatch];
        }

        $dispatchReflection = ObjectManager::getReflectionInstance($dispatch);
        $metadata = [];

        foreach ($dispatchReflection->getAttributes() as $attribute) {
            $metadata[] = [
                'name' => $attribute->getName(),
                'arguments' => $attribute->getArguments(),
            ];
        }

        return self::$controllerAttributeMetadataCache[$dispatch] = $metadata;
    }

    private function resolveRequestScopedResponse(mixed $result, string $output): Response
    {
        $requestResponse = $this->request->getResponse();

        if ($result instanceof Response) {
            return $this->absorbDetachedResponse($requestResponse, $result, $output);
        }

        $normalizedPayload = $result;
        if ($normalizedPayload === null || $normalizedPayload === '') {
            if ($output !== '') {
                $normalizedPayload = $output;
            } else {
                return $requestResponse;
            }
        }

        $normalized = Response::normalize($normalizedPayload, $requestResponse);
        if ($normalized === $requestResponse) {
            return $requestResponse;
        }

        return $this->absorbDetachedResponse($requestResponse, $normalized, '');
    }

    private function absorbDetachedResponse(Response $target, Response $source, string $outputFallback): Response
    {
        if ($target === $source) {
            if ($outputFallback !== '' && $target->getBody() === '') {
                $target->setBody($outputFallback);
            }

            return $target;
        }

        $target->setHttpResponseCode($source->getStatusCode());

        foreach ($source->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $target->setHeader($name, (string) $headerValue);
                }
            } else {
                $target->setHeader($name, (string) $value);
            }
        }

        foreach ($source->getCookies() as $cookie) {
            $target->setCookie(
                (string) ($cookie['name'] ?? ''),
                (string) ($cookie['value'] ?? ''),
                (int) ($cookie['expire'] ?? 0),
                (string) ($cookie['path'] ?? '/'),
                (string) ($cookie['domain'] ?? ''),
                (bool) ($cookie['secure'] ?? false),
                (bool) ($cookie['httpOnly'] ?? true),
                (string) ($cookie['sameSite'] ?? 'Lax'),
            );
        }

        $body = $source->getBody();
        if ($body === '' && $outputFallback !== '') {
            $body = $outputFallback;
        }
        if ($body !== '') {
            $target->setBody($body);
        }

        return $target;
    }

    /**
     * @return void
     */
    public function header_xss(): void
    {
        // 检查 headers 是否已发送
        if (headers_sent($file, $line)) {
            return;
        }

        $collector = HeaderCollector::getInstance();
        $collector->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $collector->setHeader('X-Content-Type-Options', 'nosniff');
        $collector->setHeader('X-XSS-Protection', '1; mode=block');

        $cspReportOnly = trim((string)Env::get('security.headers.csp_report_only', ''));
        if ($cspReportOnly !== '') {
            $collector->setHeader('Content-Security-Policy-Report-Only', $cspReportOnly);
        }

        $csp = trim((string)Env::get('security.headers.csp', ''));
        if ($csp !== '') {
            $collector->setHeader('Content-Security-Policy', $csp);
        }
    }

    /**
     * @param string|null $mime_type
     * @return void
     */
    public function header_response(?string $mime_type): void
    {
        header('Cache-Control: max-age=3600');
        header("Content-Type:$mime_type;charset=UTF-8");
    }

    /**
     * @param string $fileModificationTime
     * @param array|string $filename
     * @return void
     */
    public function header_cache(string $fileModificationTime, array|string $filename): void
    {
        header('Last-Modified: ' . $fileModificationTime);
        header("X-XSS-Protection: 1; mode=block");
        header('Expires: ' . (PROD ? '10' : '0'));
        header('Cache-Control: must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filename));
    }

    /** 只读请求入口写入的 WELINE_IS_STATIC_FILE，不再在此处判断 */
    private function isStaticFile(): bool
    {
        if (!(bool) \w_env('parser_url', false)) {
            return false;
        }
        return (bool) \w_env('is_static_file', false);
    }

    private function collectRouterGeneratedGetParams(array $originalGet): array
    {
        $generated = [];
        foreach (\w_env_get() as $key => $value) {
            if (!array_key_exists($key, $originalGet) || $originalGet[$key] !== $value) {
                $generated[$key] = $value;
            }
        }
        return $generated;
    }

    private function applyRouterGeneratedGetParams(): void
    {
        foreach ($this->routerGeneratedGetParams as $paramKey => $paramValue) {
            $this->request->setGet($paramKey, $paramValue);
        }
    }

    /**
     * @param mixed $cached
     * @return array{0: array, 1: array}
     */
    private function normalizeRuleCache(mixed $cached): array
    {
        if (is_array($cached) && array_key_exists(self::RULE_CACHE_RULE_KEY, $cached)) {
            $rule = $cached[self::RULE_CACHE_RULE_KEY] ?? [];
            $params = $cached[self::RULE_CACHE_PARAMS_KEY] ?? [];
            return [is_array($rule) ? $rule : [], is_array($params) ? $params : []];
        }
        return [is_array($cached) ? $cached : [], []];
    }
}
