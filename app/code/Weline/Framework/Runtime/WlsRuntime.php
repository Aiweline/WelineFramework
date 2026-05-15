<?php
declare(strict_types=1);

/**
 * Weline Framework - WLS 运行时
 * 
 * Weline Server 常驻内存模式的运行时实现
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\App;
use Weline\Framework\App\Env;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser as PhraseParser;
use Weline\Framework\Router\Core as Router;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Env\WelineEnv;
use Weline\I18n\Parser as I18nParser;
use Weline\Server\Log\LogConfig;
/**
 * WLS 运行时
 * 
 * 特点：
 * - 进程启动时初始化框架（只执行一次）
 * - 每个请求调用 handle()
 * - 请求结束调用 reset() 清理状态
 * - 常驻内存，高性能
 */
class WlsRuntime implements RuntimeInterface
{
    /**
     * 是否已初始化
     */
    private bool $bootstrapped = false;
    
    /**
     * 事件管理器（进程级缓存）
     */
    private ?EventsManager $eventManager = null;
    
    /**
     * 路由器（进程级缓存）
     */
    private ?Router $router = null;
    
    /**
     * 请求计数
     */
    private int $requestCount = 0;

    /**
     * Pending response state must stay request-scoped. Store it per fiber so
     * one request cannot overwrite another request's cookies/headers/status
     * during scheduler yields.
     *
     * @var \WeakMap<\Fiber, array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}>|null
     */
    private ?\WeakMap $fiberPendingResponseStates = null;

    /**
     * Fallback pending response state for non-fiber callers such as unit tests.
     *
     * @var array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private array $mainPendingResponseState = [
        'cookies' => [],
        'headers' => [],
        'status_code' => null,
        'explicit' => false,
        'sse_started' => false,
    ];
    
    /**
     * 超全局变量模拟器
     */
    /**
     * 待发送的 Cookie（在 StateManager 重置前从 HeaderCollector 提取）
     * Worker 在构建 HTTP 响应时读取这些 Cookie 并添加 Set-Cookie 头
     */
    /**
     * 待发送的响应头（在 StateManager 重置前从 HeaderCollector 提取）
     */
    /**
     * Pending HTTP status captured before request state is reset.
     */
    /**
     * Whether the pending HTTP status was explicitly overridden by application code.
     */
    /** WLS 运行时性能配置缓存 */
    private ?array $performanceConfig = null;
    
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        
        // 定义 WLS 模式常量
        if (!\defined('WLS_MODE')) {
            \define('WLS_MODE', true);
        }
        
        // 定义框架核心常量（原本在 bootstrap.php 中定义）
        if (!\defined('VENDOR_PATH')) {
            \define('VENDOR_PATH', BP . 'vendor' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DIRECTORY_SEPARATOR);
        }
        if (!\defined('VAR_PATH')) {
            \define('VAR_PATH', BP . 'var' . DIRECTORY_SEPARATOR);
        }
        
        // 初始化框架核心
        App::init();
        
        // 注册框架核心重置回调
        StateManager::registerFrameworkResets();
        FiberOutputBuffer::install();
        
        // 预加载常用对象（进程级缓存）
        $this->eventManager = ObjectManager::getInstance(EventsManager::class);
        $this->router = ObjectManager::getInstance(Router::class);
        Router::preloadGeneratedRouterFiles();
        PhraseParser::preloadWorkerDictionaries();
        I18nParser::preloadWorkerDictionaries();
        
        // Router 在 WLS 模式下是进程级单例。
        // 请求级状态由 Router::__init() 在每个请求开始时重置，
        // 无需在此注册额外回调（__init 通过 RequestContext ID 检测新请求）。
        
        $this->bootstrapped = true;
    }
    
    /**
     * @inheritDoc
     */
    public function handle(?Request $request = null): string
    {
        // 确保已初始化
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        if ($request === null) {
            throw new \LogicException('WLS: WlsRuntime::handle() requires a Request instance for fiber-local context isolation.');
        }

        FiberOutputBuffer::ensureInstalled('request_start');

        Context::enter(Context::fromRequest($request, [
            'mode' => RuntimeInterface::MODE_WLS,
            'type' => 'request',
            'instance' => (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_SERVER['WLS_INSTANCE'] ?? ''),
            'process_tag' => (string)($_SERVER['WLS_PROCESS_TAG'] ?? 'WLS'),
        ]));

        $app = new App();

        $globalsEmulator = null;
        
        $this->requestCount++;
        
        // 性能统计：仅当请求耗时 > 1 秒时写入 var/log/wls/timing.log，便于定位 TTFB 瓶颈
        $t0 = \microtime(true);
        $timing = [
            'uri' => '',
            'run_before_ms' => 0,
            'url_parser_call_ms' => 0,
            'process_url_parse_ms' => 0,
            'url_parser_ms' => 0,
            'router_init_ms' => 0,
            'router_start_call_ms' => 0,
            'router_start_ms' => 0,
            'run_after_ms' => 0,
            'pre_telemetry_total_ms' => 0,
            'telemetry_ms' => 0,
            'dev_tool_ms' => 0,
            'reset_ms' => 0,
            'total_ms' => 0
        ];
        
        // 重置重定向计数器（每个新请求重置）
        if (!isset($_SERVER['WLS_REDIRECT_COUNT'])) {
            $_SERVER['WLS_REDIRECT_COUNT'] = 0;
            WelineEnv::set('wls.redirect_count', '0', 'WlsRuntime init');
        }
        
        // 直接写入调试日志（WlsRuntime::handle 开始）
        try {
            // WLS 状态管理：必须先按当前请求更新 $_SERVER，再初始化 RequestContext。
            // 否则 RequestContext::init() -> syncFromServer() 会读到上一请求的 $_SERVER，
            // 导致 area_router、WELINE_AREA 等错误，进而出现 502/404 或“存了上一个人的访问链接”。
            if ($request !== null) {
                ObjectManager::setInstance(Request::class, $request);
                $resolvedClass = ObjectManager::parserClass(Request::class);
                if ($resolvedClass !== Request::class) {
                    ObjectManager::setInstance($resolvedClass, $request);
                }
                $request->resetResponse();
                $requestResponse = $request->getResponse();
                ObjectManager::setInstance(Response::class, $requestResponse);
                $resolvedResponseClass = ObjectManager::parserClass(Response::class);
                if ($resolvedResponseClass !== Response::class) {
                    ObjectManager::setInstance($resolvedResponseClass, $requestResponse);
                }
                $globalsEmulator = new GlobalsEmulator();
                $globalsEmulator->emulate($request);
                WelineEnv::getInstance()->initFromRequest($request);
            }
            // WLS：请求入口再清一次 URL/ACL 请求级缓存，避免上一 finally 未跑全、fiber 交错或 parser 前
            // 观察者调用 getUrlPath 导致 static $url_paths / Acl 路由判定沿用旧路径，误判无权限跳 admin。
            Request::clearStaticUrlPathCache();
            if ($request !== null) {
                $request->invalidateUriCache();
            }
            if (\class_exists(\Weline\Acl\Service\AclService::class, false)) {
                \Weline\Acl\Service\AclService::resetRequestCache();
            }
            if (\class_exists(\Weline\Acl\Observer\RouteBefore::class, false)) {
                \Weline\Acl\Observer\RouteBefore::resetRequestCache();
            }
            try {
                $ref = new \ReflectionClass(\Weline\Framework\Router\Cache\ProcessUrlCache::class);
                if ($ref->hasProperty('staticCache')) {
                    $prop = $ref->getProperty('staticCache');
                    $prop->setAccessible(true);
                    $prop->setValue(null, null);
                }
            } catch (\Throwable) {
                // 忽略：模块未加载或非 WLS 路由缓存
            }
            // 常驻内存：新请求入口 OM/标签基线（与 StateManager 中对应 reset 回调对齐，供 peer Fiber 存在时 finally 可安全 omit）
            if (Runtime::isPersistent()) {
                StateManager::runWlsPersistentRequestEntryBaseline();
            }
            $_SERVER['WLS_REQUEST_COUNT'] = $this->requestCount;
            Context::current()->set('runtime.request_count', $this->requestCount);
            $app->bootstrapRequestCycle();
            $timing['uri'] = ($_SERVER['REQUEST_URI'] ?? '') ?: '/';
            WelineEnv::set('request.uri', $timing['uri'], 'WlsRuntime handle');
            WelineEnv::set('request.method', $_SERVER['REQUEST_METHOD'] ?? 'GET', 'WlsRuntime handle');
            
            // 请求日志：默认始终写入 runtime.log（由 shouldWriteRequestLog 控制），全量调试见 -log
            $isDev = \defined('DEV') && DEV;
            $isFrontend = \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
            if ($request !== null) {
                $this->logWlsRequest($request, $isFrontend);
            }
            
            WelineEnv::set('wls.request_count', (string) $this->requestCount, 'WlsRuntime handle');
            // WLS 请求入口：在 dispatchRunBefore 之前重置 URL 解析器请求级缓存。
            // StateManager::reset() 在请求结束时运行，但 run_before 观察者可能在 URL parser
            // 之前就生成 URL，此时 static 属性（parserServer/parserMatchs/parserCache 等）
            // 仍持有上一个请求的残留值，导致 URL 拼接时生成错误的 website_url 前缀。
            if (Runtime::isPersistent()) {
                \Weline\Framework\Http\Url::resetParserRequestCaches();
            }
            $t1 = \microtime(true);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('run_before');
            }
            $app->dispatchRunBefore();
            $t2 = \microtime(true);
            $timing['run_before_ms'] = \round(($t2 - $t1) * 1000, 2);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('run_before', $timing['run_before_ms'], 'framework');
            }
            // 如果run_before事件耗时过长，记录警告
            if ($timing['run_before_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_before event took ' . $timing['run_before_ms'] . 'ms');
            }
            
            // URL 解析
            // 注意：Url 类的静态变量重置现在由 StateManager 自动处理
            // 通过 Url::registerStateResets() 注册到 StateManager
            $urlParserStart = \microtime(true);
            $parse = $app->parseUrl();
            $urlParserEnd = \microtime(true);
            $timing['url_parser_call_ms'] = \round(($urlParserEnd - $urlParserStart) * 1000, 2);
            
            if (\is_array($parse)) {
                $processUrlStart = \microtime(true);
                $app->applyParsedUrl($parse);
                $processUrlEnd = \microtime(true);
                $timing['process_url_parse_ms'] = \round(($processUrlEnd - $processUrlStart) * 1000, 2);
            }
            // 关键修复：Url::parser() 修改了 $_SERVER['REQUEST_URI']（去除了区域/货币/语言前缀）
            // 如果在 parser 之前有代码调用了 Request::getUri()（如 run_before 事件观察者），
            // 原始 URI 已被缓存在 Request 对象上，必须清除，否则 Router 会使用旧 URI 导致间歇性 404
            $t3 = \microtime(true);
            $timing['url_parser_ms'] = \round(($t3 - $t2) * 1000, 2);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('url_parser', $timing['url_parser_ms'], 'framework');
            }
            
            // WLS：StateManager::reset() 会在请求结束时 removeInstance(Router\Core)，bootstrap 里缓存的
            // $this->router 会变成指向已脱离 ObjectManager 的孤儿实例；若继续对其 __init/start，
            // 会出现 request_area / is_backend 与当前 $_SERVER 不一致（误判后台、命中错误路由缓存）。
            // 每请求必须从 OM 取当前 Router 单例再初始化。
            $routerInitStart = \microtime(true);
            $router = $app->initializeRouter();
            $routerInitEnd = \microtime(true);
            $timing['router_init_ms'] = \round(($routerInitEnd - $routerInitStart) * 1000, 2);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('router_init', $timing['router_init_ms'], 'framework');
            }
            // 请求早期统一启动 Session（与 App::run 一致）；静态资源不启动，避免 Set-Cookie 与无意义 IO
            $app->startSessionIfNeeded();
            // 路由处理（含控制器、视图，通常为主要耗时）；push 使控制器链路与事件挂到 router_start 下
            $routerStartStart = \microtime(true);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('router_start');
            }
            $result = $app->runRouter($router);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
            }
            $routerStartEnd = \microtime(true);
            $timing['router_start_call_ms'] = \round(($routerStartEnd - $routerStartStart) * 1000, 2);
            $t4 = \microtime(true);
            $timing['router_start_ms'] = \round(($t4 - $t3) * 1000, 2);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('router_start', $timing['router_start_ms'], 'framework');
            }
            // 触发 run_after 事件
            $runAfterStart = \microtime(true);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('run_after');
            }
            $result = $app->dispatchRunAfter($result);
            $runAfterEnd = \microtime(true);
            $timing['run_after_ms'] = \round(($runAfterEnd - $runAfterStart) * 1000, 2);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('run_after', $timing['run_after_ms'], 'framework');
            }
            $t5 = \microtime(true);
            
            // 如果run_after事件耗时过长，记录警告
            if ($timing['run_after_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_after event took ' . $timing['run_after_ms'] . 'ms');
            }
            
            // 计算总耗时（用于性能监控）
            $t5_end = \microtime(true);
            $timing['total_ms'] = \round(($t5_end - $t0) * 1000, 2);
            
            // 如果总耗时超过阈值或 DEV 模式，按配置追加性能响应头
            $isDev = \defined('DEV') && DEV;
            $performanceConfig = $this->getPerformanceConfig();
            $slowThreshold = (float)($performanceConfig['slow_request_threshold_ms'] ?? 500.0);
            if (!empty($performanceConfig['response_headers_enabled']) && ($timing['total_ms'] >= $slowThreshold || $isDev)) {
                // 尝试将性能数据添加到响应头（如果响应对象可用）
                try {
                    $request = ObjectManager::getInstance(Request::class);
                    if ($request && method_exists($request, 'getResponse')) {
                        $response = $request->getResponse();
                        if ($response && method_exists($response, 'setHeader')) {
                            $response->setHeader('X-WLS-Performance-Total', (string)$timing['total_ms']);
                            $response->setHeader('X-WLS-Performance-RunBefore', (string)$timing['run_before_ms']);
                            $response->setHeader('X-WLS-Performance-UrlParser', (string)($timing['url_parser_call_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-RouterStart', (string)($timing['router_start_call_ms'] ?? 0));
                            $response->setHeader('X-WLS-Performance-RunAfter', (string)$timing['run_after_ms']);
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略错误，不影响主流程
                }
            }
            
            // 检查是否是 SSE 模式（如果是，响应已经流式发送，返回空字符串）
            // 关键：必须使用“请求级”标记，不能只看 SseContext 全局静态状态。
            // WLS 多 Fiber 并发下，全局静态标记可能被其它 Fiber 的 SSE 请求短暂置为 true，
            // 若据此短路，普通 HTTP 请求会被误判为 SSE 并返回空响应。
            if ($this->isSseStreamHandledInCurrentRequest($request)) {
                return '';  // SSE 响应已流式发送，不需要返回内容
            }
            
            $resultStr = $app->normalizeOutput($result);
            $timing['pre_telemetry_total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
            $telemetryStart = \microtime(true);
            // 仅广播遥测事件，具体注入/展示由监听者模块处理（Framework 与上层模块解耦）
            $resultStr = $app->broadcastTelemetry($resultStr, $request);
            $timing['telemetry_ms'] = \round((\microtime(true) - $telemetryStart) * 1000, 2);
            $timing['dev_tool_ms'] = RequestLifecycleTrace::sumDurationsByName('dev_tool_panel');
            $timing['total_ms'] = \round((\microtime(true) - $t0) * 1000, 2);
            $isDev = \defined('DEV') && DEV;
            $performanceConfig = $this->getPerformanceConfig();
            $slowThreshold = (float)($performanceConfig['slow_request_threshold_ms'] ?? 500.0);
            if (!empty($performanceConfig['response_headers_enabled']) && ($timing['total_ms'] >= $slowThreshold || $isDev)) {
                $this->applyPerformanceHeaders($timing, $request);
            }

            return $resultStr;
            
        } catch (\Weline\Framework\Http\StaticFileException $staticEx) {
            // 静态文件异常：转换为文件响应
            return $staticEx->toHttpString();
            
        } catch (\Weline\Framework\Http\DownloadException $downloadEx) {
            // 下载异常：转换为文件下载响应
            return $downloadEx->toHttpString();
            
        } catch (\Weline\Framework\Http\RedirectException $redirectEx) {
            // 重定向异常：转换为重定向响应
            Session::flushRequestSessions();
            if ($this->isSseRequest($request)) {
                $redirectUrl = $redirectEx->getRedirectUrl();
                $statusCode = str_contains(strtolower($redirectUrl), 'admin/login') ? 401 : 403;
                $message = $statusCode === 401
                    ? __('SSE 会话已失效，请重新登录后重试。')
                    : __('当前账号无权限执行该 SSE 操作。');
                return $this->buildSseFailedResponse($statusCode, $message, ['redirect' => $redirectUrl]);
            }
            // 记录重定向信息
            $redirectCount = (int) ($_SERVER['WLS_REDIRECT_COUNT'] ?? 0);
            $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
            // 同步到 WelineEnv
            WelineEnv::set('wls.redirect_count', (string) $redirectCount, 'WlsRuntime catch RedirectException');
            WelineEnv::set('request.uri', $currentUri, 'WlsRuntime catch RedirectException');
            $redirectUrl = $this->withBackendLoginReturnUrl($redirectEx->getRedirectUrl(), $request);
            
            // 如果重定向次数过多，记录警告
            if ($redirectCount > 5) {
                w_log_warning("[WLS Redirect Warning] Too many redirects: {$redirectCount}, current URI: {$currentUri}, redirect to: {$redirectUrl}");
            }
            
            // 创建重定向响应，并立即把 HeaderCollector 中的 Cookie 写入响应（登录 302 必须带 Set-Cookie，不依赖 Worker 合并）
            $redirectResponse = Response::text('', $redirectEx->getStatusCode());
            $redirectResponse->setHeader('Location', $redirectUrl);
            $redirectResponse->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            $redirectResponse->setHeader('Pragma', 'no-cache');
            $redirectResponse->setHeader('Expires', '0');
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            $cookies = $hc->getCookies();
            foreach ($cookies as $cookie) {
                $redirectResponse->setCookie(
                    (string)$cookie['name'],
                    (string)$cookie['value'],
                    (int)($cookie['expire'] ?? 0),
                    (string)($cookie['path'] ?? '/'),
                    (string)($cookie['domain'] ?? ''),
                    (bool)($cookie['secure'] ?? false),
                    (bool)($cookie['httpOnly'] ?? true),
                    (string)($cookie['sameSite'] ?? 'Lax')
                );
            }
            // 诊断头：便于在浏览器中确认 302 是否带 Cookie（0=未带，排查 Session/Nginx）
            $redirectResponse->setHeader('X-WLS-Redirect-Cookies', (string)\count($cookies));
            return $redirectResponse->toHttpString(false);
            
        } catch (\Weline\Framework\Http\NoRouterException $noRouterEx) {
            // 无路由异常：转换为 404/403 响应
            if ($this->isSseRequest($request)) {
                return $this->buildSseFailedResponse(
                    $noRouterEx->getStatusCode(),
                    $noRouterEx->getErrorMessage()
                );
            }
            
            // 尝试加载错误页面模板
            $errorFile = BP . 'pub/errors/' . $noRouterEx->getStatusCode() . '.php';
            $errorContent = '';
            if (is_file($errorFile)) {
                ob_start();
                try {
                    include $errorFile;
                    $errorContent = ob_get_clean();
                } catch (\Throwable $e) {
                    ob_end_clean();
                    $errorContent = '<h1>' . $noRouterEx->getStatusCode() . ' ' . htmlspecialchars($noRouterEx->getErrorMessage()) . '</h1>';
                }
            } else {
                $errorContent = '<h1>' . $noRouterEx->getStatusCode() . ' ' . htmlspecialchars($noRouterEx->getErrorMessage()) . '</h1>';
            }
            
            // 创建错误响应
            return Response::fromContent($errorContent, $noRouterEx->getStatusCode(), 'text/html; charset=utf-8')->toHttpString(false);
            
        } catch (\Weline\Framework\Http\ResponseTerminateException $terminateEx) {
            // 通用响应终止异常：使用异常的 toHttpString() 方法
            if ($this->isSseRequest($request)) {
                $headers = $terminateEx->getHeaders();
                $contentType = strtolower((string)($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
                if (str_contains($contentType, 'text/event-stream')) {
                    return $terminateEx->toHttpString();
                }
                $code = $terminateEx->getStatusCode();
                $message = $this->extractSseErrorMessage($terminateEx);
                return $this->buildSseFailedResponse($code > 0 ? $code : 500, $message);
            }
            return $terminateEx->toHttpString();
            
        } catch (\Throwable $e) {
            // 302 等响应终止异常若落入此处（不应发生），按正常响应处理，不记错误
            if ($e instanceof \Weline\Framework\Http\ResponseTerminateException) {
                return $e->toHttpString();
            }

            // 记录错误日志（DEV 环境）
            $isDev = \defined('DEV') && DEV;
            if ($isDev) {
                $this->logWlsError($e);
            } else {
                w_log_error('[WlsRuntime] Request error: ' . $e->getMessage());
            }
            
            // 返回错误响应
            if ($this->isSseRequest($request)) {
                $message = (\defined('DEV') && DEV)
                    ? $e->getMessage()
                    : __('SSE 请求处理失败，请稍后重试。');
                return $this->buildSseFailedResponse(500, $message);
            }
            return $this->handleException($e);
            
        } finally {
            $t6 = \microtime(true);
            Session::flushRequestSessions();
            if (Runtime::isPersistent() && WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0
                && \defined('DEV') && DEV) {
                w_log_debug(
                    '[WlsRuntime] request ended with other suspended fibers=' . WlsConcurrency::getOtherSuspendedRequestFiberCount(),
                    [],
                    'wls'
                );
            }
            // 在重置前保存 HeaderCollector 的 Cookie/Header（Worker 构建响应时需要）
            // StateManager::reset() 会清空 HeaderCollector，必须在此之前提取
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            $this->snapshotPendingResponseState($hc);
            // 确保总是重置状态（存在挂起 Fiber 时仍执行完整 reset，见 WlsConcurrency 类说明）
            $this->reset();
            FiberOutputBuffer::ensureInstalled('request_end');
            if ($globalsEmulator !== null) {
                $globalsEmulator->reset();
            }
            $t7 = \microtime(true);
            $timing['reset_ms'] = \round(($t7 - $t6) * 1000, 2);
            $timing['total_ms'] = \round(($t7 - $t0) * 1000, 2);
            // 性能监控：记录所有超过500ms的请求，或DEV模式下记录所有请求
            $isDev = \defined('DEV') && DEV;
            // 添加请求方法、IP等信息
            $timing['method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $timing['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $timing['timestamp'] = date('Y-m-d H:i:s');
            $timing['redirect_count'] = (int) ($_SERVER['WLS_REDIRECT_COUNT'] ?? 0);
            // 同步到 WelineEnv
            WelineEnv::set('request.method', $timing['method'], 'WlsRuntime finally');
            WelineEnv::set('server.remote_addr', $timing['ip'], 'WlsRuntime finally');
            WelineEnv::set('wls.redirect_count', (string) $timing['redirect_count'], 'WlsRuntime finally');
            $this->recordPerformanceTiming($timing, $isDev);
            if (\class_exists(\Weline\Server\Log\WlsLogger::class, false)) {
                \Weline\Server\Log\WlsLogger::flush_(true);
            }
            Context::leave();
        }
    }
    
    /**
     * 处理 URL 解析结果
     */
    private function normalizeParsedUri(mixed $uri): string
    {
        if (\is_array($uri)) {
            $path = $uri['path'] ?? $uri['uri'] ?? $uri['REQUEST_URI'] ?? $uri['data'] ?? '';
            if (!\is_scalar($path)) {
                $path = '';
            }
            $path = (string)$path;

            $query = $uri['query'] ?? '';
            if (\is_array($query)) {
                $query = \http_build_query($query);
            }
            if (\is_scalar($query) && $query !== '') {
                $query = (string)$query;
                $path .= (\str_contains($path, '?') ? '&' : '?') . \ltrim($query, '?');
            }

            return $path;
        }

        return \is_scalar($uri) ? (string)$uri : '';
    }

    private function processUrlParse(array $parse): void
    {
        // 防御性检查：如果 parse 缺少 server 字段（如 parserMatchs 早期返回），
        // 则使用 Url::$parserServer 或当前 $_SERVER 作为基础，避免后续代码访问 null
        if (!isset($parse['server']) || !is_array($parse['server'])) {
            w_log_warning('[WlsRuntime] processUrlParse: parse[server] is missing! URL parse data may be incomplete. '
                . 'area=' . ($parse['area'] ?? '(none)') 
                . ', uri=' . ($parse['uri'] ?? '(none)')
                . ', REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '(none)')
            );
            // 回退到当前 $_SERVER（已被 GlobalsEmulator 正确初始化）
            $parse['server'] = [];
        }

        $area = $parse['area'] ?? $parse['server']['WELINE_AREA'] ?? '';
        // 同步到 WelineEnv
        WelineEnv::set('area', $area, 'WlsRuntime processUrlParse');
        $isBackendArea = ($area === 'backend' || $area === 'rest_backend');
        if (isset($parse['uri'])) {
            $uri = \Weline\Framework\Http\Url::decode_url($this->normalizeParsedUri($parse['uri']));
            // 后台/API 后台不覆盖 REQUEST_URI，保留 parser 已设置的带 /admin/ 前缀的路径，否则 Router 会拿到 pure_uri 导致 404
            if (!$isBackendArea) {
                $parse['server']['REQUEST_URI'] = $uri;
            }
            $parse['server']['QUERY_STRING'] = \Weline\Framework\Http\Url::parse_url($uri, 'query');
        }
        // 兜底防污染：WLS 多请求复用进程下，backend 场景也必须确保 REQUEST_URI 来自当前请求，
        // 否则前一个 frontend/preview 请求可能残留，导致后台路由命中错误页面（需手动刷新才恢复）。
        if (!isset($parse['server']['REQUEST_URI']) || $parse['server']['REQUEST_URI'] === '') {
            if (isset($parse['uri']) && $parse['uri'] !== '') {
                $parse['server']['REQUEST_URI'] = \Weline\Framework\Http\Url::decode_url($this->normalizeParsedUri($parse['uri']));
            } else {
                $parse['server']['REQUEST_URI'] = (string)($_SERVER['REQUEST_URI'] ?? '/');
            }
        }
        
        // 合并而非替换 $_SERVER
        foreach ($parse['server'] as $key => $value) {
            $_SERVER[$key] = $value;
        }

        // 确保 WELINE_AREA 与本次解析结果一致（防御 cache/合并遗漏导致 MessageManager、ACL 等误判区域）
        if (isset($parse['area']) && $parse['area'] !== '') {
            $_SERVER['WELINE_AREA'] = $parse['area'];
            RequestContext::area($parse['area']);

            // 诊断日志：记录 WELINE_AREA 设置（已移除临时调试代码）
        }

        // 每次请求都基于当前解析结果重建完整 URI，避免 Fiber/长连接恢复旧值后污染统一路由缓存键。
        $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? 'http');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $currentUri = $this->normalizeParsedUri($parse['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        if ($currentUri === '') {
            $currentUri = '/';
        }
        $currentUri = \Weline\Framework\Http\Url::decode_url($currentUri);
        if (!\str_starts_with($currentUri, '/')) {
            $currentUri = '/' . $currentUri;
        }
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $currentUri;
        $_SERVER['WELINE_FULL_REQUEST_URI'] = $scheme . '://' . $host . $currentUri;
        WelineEnv::set('request.uri', $currentUri, 'WlsRuntime processUrlParse');
        WelineEnv::set('origin_request_uri', $currentUri, 'WlsRuntime processUrlParse');
        WelineEnv::set('full_request_uri', $_SERVER['WELINE_FULL_REQUEST_URI'], 'WlsRuntime processUrlParse');
        
        // 设置后端标识
        $welineArea = $_SERVER['WELINE_AREA'] ?? '';
        $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'backend' || $welineArea === 'rest_backend');
        
        // 存入请求上下文
        RequestContext::area($welineArea);
        
        // 处理语言和货币
        if (!empty($parse['currency'])) {
            $_SERVER['WELINE_USER_CURRENCY'] = $parse['currency'];
            RequestContext::currency($parse['currency']);
            // 同步到 WelineEnv
            WelineEnv::set('user.currency', $parse['currency'], 'WlsRuntime processUrlParse');
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_CURRENCY'] = $_SERVER['WELINE_USER_CURRENCY'] ?? RequestContext::currency();
        }
        if (!empty($parse['language'])) {
            $_SERVER['WELINE_USER_LANG'] = $parse['language'];
            RequestContext::locale($parse['language']);
            // 同步到 WelineEnv
            WelineEnv::set('user.lang', $parse['language'], 'WlsRuntime processUrlParse');
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_LANG'] = $_SERVER['WELINE_USER_LANG'] ?? RequestContext::locale();
        }
        
        // 存储网站信息到上下文
        if (!empty($_SERVER['WELINE_WEBSITE_ID'])) {
            RequestContext::websiteId((int) $_SERVER['WELINE_WEBSITE_ID']);
        }
        
        // 标记 URL 解析已完成
        // CheckFullPageCache 在 url_parsed_after 事件中可以使用此标志判断
        $_SERVER['WELINE_URL_PARSED'] = true;
        WelineEnv::set('url_parsed', true, 'WlsRuntime processUrlParse');
        WelineEnv::getInstance()->initFromSnapshot(
            \is_array($_GET ?? null) ? $_GET : [],
            \is_array($_POST ?? null) ? $_POST : [],
            \is_array($_COOKIE ?? null) ? $_COOKIE : [],
            \is_array($_FILES ?? null) ? $_FILES : [],
            \is_array($_SERVER ?? null) ? $_SERVER : [],
        );
    }
    
    /**
     * 早期 URL 解析：从 URL 路径中快速提取语言和货币
     * 
     * 在 run_before 事件之前调用，确保事件处理器能获取正确的语言/货币。
     * 这是一个轻量级解析，不涉及网站匹配、路由识别等复杂逻辑。
     * 
     * URL 结构示例：
     * - /backendKey/USD/zh_Hans_CN/module/backend/controller
     * - /USD/zh_Hans_CN/module/controller
     * - /zh_Hans_CN/module/controller
     * 
     * 货币识别规则：3 位大写字母（如 USD、CNY、EUR）
     * 语言识别规则：xx_Xxxx_XX 格式（如 zh_Hans_CN、en_US）
     * 
     * @param string $uri 请求 URI
     */
    private function parseUrlLangCurrency(string $uri): void
    {
        if (empty($uri) || $uri === '/') {
            return;
        }
        
        // 分割 URI 路径段
        $segments = \explode('/', \trim($uri, '/'));
        if (empty($segments)) {
            return;
        }
        
        $currency = null;
        $language = null;
        
        // 检查前 4 个路径段（足够覆盖 backendKey/currency/language/... 结构）
        $checkCount = \min(4, \count($segments));
        for ($i = 0; $i < $checkCount; $i++) {
            $segment = $segments[$i];
            if (empty($segment)) {
                continue;
            }
            
            // 货币识别：3 位大写字母
            if ($currency === null && \strlen($segment) === 3 && \ctype_upper($segment)) {
                $currency = $segment;
                continue;
            }
            
            // 语言识别：xx_Xxxx_XX 或 xx_XX 格式
            // 例如：zh_Hans_CN, en_US, fr_FR, pt_BR
            if ($language === null && \strlen($segment) >= 5 && \strlen($segment) <= 11) {
                // 检查是否符合 locale 格式
                if (\preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(_[A-Z]{2})?$/', $segment)) {
                    $language = $segment;
                    continue;
                }
            }
            
            // 如果都找到了，提前退出
            if ($currency !== null && $language !== null) {
                break;
            }
        }
        
        // 设置到 $_SERVER（URL 路径中的值优先级最高）
        if ($currency !== null) {
            $_SERVER['WELINE_USER_CURRENCY'] = $currency;
            RequestContext::currency($currency);
            // 同步到 WelineEnv
            WelineEnv::set('user.currency', $currency, 'WlsRuntime parseUrlLangCurrency');
        }
        if ($language !== null) {
            $_SERVER['WELINE_USER_LANG'] = $language;
            RequestContext::locale($language);
            // 同步到 WelineEnv
            WelineEnv::set('user.lang', $language, 'WlsRuntime parseUrlLangCurrency');
        }
    }
    
    /**
     * 处理异常
     * 
     * 使用统一的 ErrorResponse 类生成错误响应，支持多语言
     * 返回格式与 FPM 模式的 JsonRenderer 一致
     */
    private function handleException(\Throwable $e): string
    {
        $statusCode = \Weline\Framework\Exception\ErrorResponse::getStatusCode($e);
        $message = $e->getMessage() ?: 'Internal Server Error';
        
        // DEV 模式下输出 HTML 格式的错误页面（前端可见）
        $isDev = \defined('DEV') && DEV;
        if ($isDev) {
            $this->logWlsError($e);
            return Response::fromContent(
                $this->formatExceptionAsHtml($e, $statusCode, $message),
                $statusCode,
                'text/html; charset=UTF-8'
            )->toHttpString(false);
        }
        
        // DEBUG 和生产模式：使用统一的 ErrorResponse 生成 JSON
        $isDebug = \defined('DEBUG') && DEBUG;
        $response = \Weline\Framework\Exception\ErrorResponse::fromException($e, $isDebug);
        
        return Response::fromContent(
            \Weline\Framework\Exception\ErrorResponse::toJson($response),
            $statusCode,
            'application/json; charset=UTF-8'
        )->toHttpString(false);
    }
    
    /**
     * 将异常格式化为 HTML 错误页面（DEV 模式）
     */
    private function formatExceptionAsHtml(\Throwable $e, int $statusCode, string $message): string
    {
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTraceAsString();
        $class = \get_class($e);
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WLS Error - ' . \htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .error-container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .error-header { background: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .error-header h1 { margin: 0; font-size: 24px; }
        .error-body { padding: 20px; }
        .error-message { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .error-message strong { color: #856404; }
        .error-details { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; font-family: "Courier New", monospace; font-size: 13px; }
        .error-details dt { font-weight: bold; color: #495057; margin-top: 10px; }
        .error-details dd { margin-left: 20px; color: #6c757d; }
        .error-trace { background: #212529; color: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; font-family: "Courier New", monospace; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
        .error-link { color: #007bff; text-decoration: none; }
        .error-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <h1>WLS Runtime Error</h1>
        </div>
        <div class="error-body">
            <div class="error-message">
                <strong>错误信息：</strong> ' . \htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '
            </div>
            <div class="error-details">
                <dl>
                    <dt>异常类型：</dt>
                    <dd>' . \htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '</dd>
                    <dt>状态码：</dt>
                    <dd>' . $statusCode . '</dd>
                    <dt>文件位置：</dt>
                    <dd>' . \htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ':' . $line . '</dd>
                    <dt>请求 URI：</dt>
                    <dd>' . \htmlspecialchars(\w_env('request.uri', '/'), ENT_QUOTES, 'UTF-8') . '</dd>
                    <dt>请求方法：</dt>
                    <dd>' . \htmlspecialchars(\w_env('request.method', 'GET'), ENT_QUOTES, 'UTF-8') . '</dd>
                </dl>
            </div>
            <div class="error-trace">' . \htmlspecialchars($trace, ENT_QUOTES, 'UTF-8') . '</div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * 记录 WLS 请求日志（默认写入 runtime.log，可由 wls.performance.request_log_enabled 关闭）
     *
     * @param Request $request 请求对象
     * @param bool $isFrontend 是否前端模式（保留参数供扩展）
     */
    private function logWlsRequest(Request $request, bool $isFrontend = false): void
    {
        if (!$this->shouldWriteRequestLog()) {
            return;
        }
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'type' => 'request',
            'request_uri' => $request->getUri(),
            'request_method' => $request->getMethod(),
            'request_count' => $this->requestCount,
        ];
        
        // 前端模式：输出到控制台（已在 worker.php 中输出，这里不再重复）
        // 注意：请求日志已在 worker.php 接收到请求的第一时间输出
        
        $this->appendJsonLine($this->getRuntimeLogFile(), $logEntry);
    }
    
    /**
     * 记录 WLS 错误日志（DEV 环境）
     */
    private function logWlsError(\Throwable $e): void
    {
        if (!$this->shouldWriteErrorLog()) {
            return;
        }
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'request_uri' => \w_env('request.uri', '/'),
            'request_method' => \w_env('request.method', 'GET'),
            'exception' => \get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->appendJsonLine($this->getRuntimeLogFile(), $logEntry, true);
    }

    private function applyPerformanceHeaders(array $timing, ?Request $request): void
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            if (!$request || !method_exists($request, 'getResponse')) {
                return;
            }

            $response = $request->getResponse();
            if (!$response || !method_exists($response, 'setHeader')) {
                return;
            }

            $response->setHeader('X-WLS-Performance-Total', (string)($timing['total_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RunBefore', (string)($timing['run_before_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-UrlParser', (string)($timing['url_parser_call_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RouterStart', (string)($timing['router_start_call_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-RunAfter', (string)($timing['run_after_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-PreTelemetryTotal', (string)($timing['pre_telemetry_total_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-Telemetry', (string)($timing['telemetry_ms'] ?? 0));
            $response->setHeader('X-WLS-Performance-DevTool', (string)($timing['dev_tool_ms'] ?? 0));
        } catch (\Throwable) {
            // 蹇界暐鍝嶅簲澶村啓鍏ラ敊璇紝涓嶅奖鍝嶄富娴佺▼銆?
        }
    }

    private function recordPerformanceTiming(array $timing, bool $isDev): void
    {
        $config = $this->getPerformanceConfig();
        $slowThreshold = (float)($config['slow_request_threshold_ms'] ?? 500.0);
        $shouldLog = ($timing['total_ms'] >= $slowThreshold) || ($isDev && !empty($config['log_all_in_dev']));
        if (!$shouldLog) {
            return;
        }

        if (!empty($config['debug_log_enabled'])) {
            $performanceSummary = sprintf(
                '[WLS Performance] URI=%s Total=%.2fms | run_before=%.2fms | url_parser=%.2fms | router_init=%.2fms | router_start=%.2fms | run_after=%.2fms | telemetry=%.2fms | dev_tool=%.2fms',
                $timing['uri'],
                $timing['total_ms'],
                $timing['run_before_ms'],
                $timing['url_parser_call_ms'] ?? 0,
                $timing['router_init_ms'] ?? 0,
                $timing['router_start_call_ms'] ?? 0,
                $timing['run_after_ms'],
                $timing['telemetry_ms'] ?? 0,
                $timing['dev_tool_ms'] ?? 0
            );
            w_log_debug($performanceSummary);
            w_log_debug('[WLS Performance Detail] ' . \json_encode($timing, \JSON_UNESCAPED_UNICODE));
        }

        // 进入本方法前已由 shouldLog 过滤；timing 文件记录慢请求与全量调试场景
        $this->appendJsonLine($this->getPerformanceLogFile(), $timing);

        if (!empty($config['analysis_log_enabled']) && $timing['total_ms'] >= 1000) {
            $analysis = [];
            if ($timing['run_before_ms'] > 200) {
                $analysis[] = "run_before事件耗时过长: {$timing['run_before_ms']}ms";
            }
            if (($timing['url_parser_call_ms'] ?? 0) > 200) {
                $analysis[] = "URL解析耗时过长: {$timing['url_parser_call_ms']}ms";
            }
            if (($timing['router_start_call_ms'] ?? 0) > 500) {
                $analysis[] = "路由处理耗时过长: {$timing['router_start_call_ms']}ms";
            }
            if ($timing['run_after_ms'] > 200) {
                $analysis[] = "run_after事件耗时过长: {$timing['run_after_ms']}ms";
            }
            if (($timing['telemetry_ms'] ?? 0) > 100) {
                $analysis[] = "telemetry闃舵鑰楁椂杩囬暱: {$timing['telemetry_ms']}ms";
            }
            if (($timing['dev_tool_ms'] ?? 0) > 50) {
                $analysis[] = "dev_tool闈㈡澘鑰楁椂杩囬暱: {$timing['dev_tool_ms']}ms";
            }
            if (!empty($analysis)) {
                w_log_debug('[WLS Performance Analysis] ' . implode('; ', $analysis));
            }
        }
    }

    private function getPerformanceConfig(): array
    {
        if ($this->performanceConfig !== null) {
            return $this->performanceConfig;
        }

        $serverConfig = Env::getInstance()->getConfig('wls') ?? [];
        $performanceConfig = \is_array($serverConfig['performance'] ?? null) ? $serverConfig['performance'] : [];
        $verbose = LogConfig::isVerboseWlsLog();
        $this->performanceConfig = \array_merge([
            'slow_request_threshold_ms' => 500,
            'response_headers_enabled' => true,
            // 以下项默认随「全量日志」(-log) 开启；未开启时仅保留慢请求 timing 落盘（见 recordPerformanceTiming）
            'file_log_enabled' => $verbose,
            'debug_log_enabled' => $verbose,
            'analysis_log_enabled' => $verbose,
            'log_all_in_dev' => $verbose,
            'request_log_enabled' => $verbose,
            'error_log_enabled' => null,
            'runtime_log_file' => 'var/log/wls/runtime.log',
            'timing_log_file' => 'var/log/wls/timing.log',
        ], $performanceConfig);

        return $this->performanceConfig;
    }

    private function shouldWriteRequestLog(): bool
    {
        $enabled = $this->getPerformanceConfig()['request_log_enabled'];
        if ($enabled === null) {
            return true;
        }

        return (bool)$enabled;
    }

    private function shouldWriteErrorLog(): bool
    {
        $enabled = $this->getPerformanceConfig()['error_log_enabled'];
        if ($enabled === null) {
            return true;
        }

        return (bool)$enabled;
    }

    private function getRuntimeLogFile(): string
    {
        return $this->resolveLogPath((string)$this->getPerformanceConfig()['runtime_log_file']);
    }

    private function getPerformanceLogFile(): string
    {
        return $this->resolveLogPath((string)$this->getPerformanceConfig()['timing_log_file']);
    }

    private function resolveLogPath(string $path): string
    {
        if ($path === '') {
            $path = 'var/log/wls/runtime.log';
        }

        if (\str_starts_with($path, '/') || \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return BP . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
    }

    private function appendJsonLine(string $logFile, array $data, bool $pretty = false): void
    {
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        $flags = \JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        @\file_put_contents($logFile, \json_encode($data, $flags) . "\n", \FILE_APPEND);
    }

    private function absorbResponseObject(Response $response): string
    {
        $requestResponse = ObjectManager::getInstance(Request::class)->getResponse();
        $requestResponse->setHttpResponseCode($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $requestResponse->setHeader($name, (string)$headerValue);
                }
            } else {
                $requestResponse->setHeader($name, (string)$value);
            }
        }

        foreach ($response->getCookies() as $cookie) {
            $requestResponse->setCookie(
                (string)$cookie['name'],
                (string)$cookie['value'],
                (int)($cookie['expire'] ?? 0),
                (string)($cookie['path'] ?? '/'),
                (string)($cookie['domain'] ?? ''),
                (bool)($cookie['secure'] ?? false),
                (bool)($cookie['httpOnly'] ?? true),
                (string)($cookie['sameSite'] ?? 'Lax')
            );
        }

        return $response->getBody();
    }

    /**
     * SSE 协议请求（EventSource）统一识别。
     *
     * 只有当 Accept 头明确以 text/event-stream 开头，或者是唯一的 Accept 类型时，才认为是 SSE 请求。
     * 避免误判：浏览器可能在 Accept 头中包含多种类型（如 text/html,text/event-stream;q=0.8），
     * 此时应优先按照 q 值最高的类型处理，而不是简单地检查是否包含 text/event-stream。
     *
     * @param Request|null $request 请求对象，如果为 null 则从 $_SERVER 读取（兜底）
     */
    private function withBackendLoginReturnUrl(string $redirectUrl, ?Request $request): string
    {
        $method = strtoupper((string)($request?->getMethod() ?: ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $redirectUrl;
        }

        $redirectPath = (string)(parse_url($redirectUrl, PHP_URL_PATH) ?: '');
        $normalizedRedirectPath = strtolower($redirectPath);
        if ($normalizedRedirectPath === ''
            || !str_ends_with($normalizedRedirectPath, '/admin/login')
        ) {
            return $redirectUrl;
        }

        $uri = (string)(
            ($request?->getServer('WELINE_ORIGIN_REQUEST_URI') ?: null)
            ?: ($request?->getServer('REQUEST_URI') ?: null)
            ?: ($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? null)
            ?: ($_SERVER['REQUEST_URI'] ?? '')
        );
        if ($uri === '') {
            return $redirectUrl;
        }
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? $request?->getServer('QUERY_STRING') ?? '');
        if ($queryString !== '' && !str_contains($uri, '?')) {
            $uri .= '?' . $queryString;
        }

        $currentPath = strtolower((string)(parse_url($uri, PHP_URL_PATH) ?: ''));
        if ($currentPath === ''
            || str_ends_with($currentPath, '/admin/login')
            || str_ends_with($currentPath, '/admin/login/post')
            || str_ends_with($currentPath, '/admin/login/logout')
        ) {
            return $redirectUrl;
        }

        $backendPrefix = substr($redirectPath, 0, -strlen('/admin/login'));
        $uriPath = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        if ($backendPrefix !== '' && $uriPath !== '' && !str_starts_with($uriPath, $backendPrefix . '/')) {
            $uri = $backendPrefix . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        }

        $scheme = $request?->isSecure() ? 'https' : 'http';
        $host = (string)(
            ($request?->getServer('HTTP_HOST') ?: null)
            ?: ($request?->getServer('SERVER_NAME') ?: null)
            ?: ($_SERVER['HTTP_HOST'] ?? null)
            ?: ($_SERVER['SERVER_NAME'] ?? 'localhost')
        );
        $returnUrl = $scheme . '://' . $host . (str_starts_with($uri, '/') ? $uri : '/' . $uri);
        $query = [
            'no_access_reason' => 'not_logged_in',
            'return_url' => $returnUrl,
        ];

        return $this->removeBackendLoginReturnParams($redirectUrl) . (str_contains($this->removeBackendLoginReturnParams($redirectUrl), '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function removeBackendLoginReturnParams(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }

        parse_str((string)$parts['query'], $params);
        unset($params['no_access_reason'], $params['return_url']);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $base .= $parts['path'] ?? '';
        return $query === '' ? $base : $base . '?' . $query;
    }

    private function isSseRequest(?Request $request = null): bool
    {
        // 优先从 Request 对象获取 Accept 头，避免 WLS 并发下 $_SERVER 污染
        if ($request !== null) {
            $acceptHeader = $request->getHeader('Accept');
            // getHeader 可能返回 array|string|null，统一转为字符串
            if (is_array($acceptHeader)) {
                $accept = strtolower(implode(',', $acceptHeader));
            } else {
                $accept = strtolower((string)$acceptHeader);
            }
        } else {
            // 兜底：从 $_SERVER 读取（仅在 Request 对象不可用时）
            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        }

        if ($accept === '') {
            return false;
        }

        // 精确匹配：Accept 头只包含 text/event-stream（可能带参数）
        if (str_starts_with($accept, 'text/event-stream')) {
            return true;
        }

        // 如果 Accept 头包含多个类型，检查 text/event-stream 是否是第一个（优先级最高）
        // 例如：text/event-stream,*/*;q=0.8
        $parts = explode(',', $accept);
        if (count($parts) > 0) {
            $firstType = trim(explode(';', $parts[0])[0]);
            return $firstType === 'text/event-stream';
        }

        return false;
    }

    /**
     * 当前 Fiber/请求是否已经进入 SSE 流式发送。
     *
     * 判定必须是请求级：
     * 1) 当前请求上下文中，SSE Writer 已调用 start() 并打上请求级标记
     * 2) 不再强依赖 Accept: text/event-stream，因为 fetch + POST 的流式读取
     *    场景也会走 SSE Writer，但请求头未必声明 EventSource 风格的 Accept
     */
    private function isSseStreamHandledInCurrentRequest(?Request $request = null): bool
    {
        return (bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false);
    }

    /**
     * WLS 下统一将 SSE 异常转换为 failed 事件响应。
     */
    private function buildSseFailedResponse(int $statusCode, string $message, array $extra = []): string
    {
        $statusCode = $statusCode > 0 ? $statusCode : 500;
        $payload = 'event: failed' . "\n";
        $data = array_merge([
            'code' => $statusCode,
            'http_status' => $statusCode,
            'message' => $message,
        ], $extra);
        $payload .= 'data: ' . \json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // EventSource 对非 200 状态码兼容性差，可能导致 failed 事件体无法被前端读取。
        // 统一使用 200 作为传输状态，真实业务错误码放在 data.code/http_status 中。
        $response = Response::fromContent($payload, 200, 'text/event-stream; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('X-Accel-Buffering', 'no');
        return $response->toHttpString(false);
    }

    /**
     * 从通用终止异常中提取可用于 SSE 的友好错误文本。
     */
    private function extractSseErrorMessage(\Weline\Framework\Http\ResponseTerminateException $terminateEx): string
    {
        $body = trim((string)$terminateEx->getBody());
        if ($body === '') {
            return __('SSE 请求被终止。');
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $msg = (string)($decoded['msg'] ?? $decoded['message'] ?? '');
            if ($msg !== '') {
                return $msg;
            }
        }

        $plain = trim(strip_tags($body));
        if ($plain !== '') {
            return mb_substr($plain, 0, 300);
        }

        return __('SSE 请求失败。');
    }
    
    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $omitCallbacks = null;
        if (Runtime::isPersistent() && WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0) {
            $omitCallbacks = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        }
        StateManager::reset($omitCallbacks);
        
        // 重置超全局变量
        
        // 触发状态重置事件（允许其他模块清理状态）
        $eventManager = $this->eventManager;
        if (Runtime::isPersistent()) {
            try {
                $eventManager = ObjectManager::getInstance(EventsManager::class);
            } catch (\Throwable) {
            }
        }
        if ($eventManager !== null) {
            try {
                $eventManager->dispatch('Weline_Framework::Runtime::reset');
            } catch (\Throwable $e) {
                w_log_error('[WlsRuntime] Reset event error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * @inheritDoc
     */
    public function terminate(): void
    {
        // 完全清理状态
        StateManager::cleanup();
        
        // 触发终止事件
        if ($this->eventManager !== null) {
            try {
                $this->eventManager->dispatch('Weline_Framework::Runtime::terminate');
            } catch (\Throwable $e) {
                w_log_error('[WlsRuntime] Terminate event error: ' . $e->getMessage());
            }
        }
        
        $this->bootstrapped = false;
        $this->eventManager = null;
        $this->router = null;
    }
    
    /**
     * @inheritDoc
     */
    public function getMode(): string
    {
        return self::MODE_WLS;
    }
    
    /**
     * @inheritDoc
     */
    public function isPersistent(): bool
    {
        return true;
    }
    
    /**
     * 获取待发送的 Cookie（在 reset 前从 HeaderCollector 提取的副本）
     * 
     * Worker 在构建 HTTP 响应时调用此方法获取 Cookie 并添加 Set-Cookie 头。
     * 每次调用后清空，避免重复发送。
     * 
     * @return array
     */
    public function consumePendingCookies(): array
    {
        $state = $this->getPendingResponseState();
        $cookies = $state['cookies'];
        $state['cookies'] = [];
        $this->setPendingResponseState($state);
        return $cookies;
    }
    
    /**
     * 获取待发送的响应头（在 reset 前从 HeaderCollector 提取的副本）
     * 
     * @return array
     */
    public function consumePendingHeaders(): array
    {
        $state = $this->getPendingResponseState();
        $headers = $state['headers'];
        $state['headers'] = [];
        $this->setPendingResponseState($state);
        return $headers;
    }

    /**
     * @return array{status_code:?int, explicit:bool, sse_started:bool}
     */
    public function consumePendingResponseStatus(): array
    {
        $state = $this->getPendingResponseState();
        $status = [
            'status_code' => $state['status_code'],
            'explicit' => $state['explicit'],
            'sse_started' => $state['sse_started'],
        ];
        $state['status_code'] = null;
        $state['explicit'] = false;
        $state['sse_started'] = false;
        $this->setPendingResponseState($state);

        return $status;
    }

    protected function snapshotPendingResponseState(\Weline\Framework\Http\HeaderCollector $headerCollector): void
    {
        $headers = $headerCollector->getHeaders();
        $sseStarted = (bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false);
        if ($sseStarted) {
            // 强制覆盖：避免 HeaderCollector / 中间层先写入的 Content-Type（如 text/plain）
            // 在 Worker 合并响应头时盖掉 SSE，导致浏览器/devtools 显示为普通文档请求。
            $headers['Content-Type'] = 'text/event-stream; charset=utf-8';
        }

        $this->setPendingResponseState([
            'cookies' => $headerCollector->getCookies(),
            'headers' => $headers,
            'status_code' => $headerCollector->getStatusCode(),
            'explicit' => $headerCollector->hasExplicitStatusCode(),
            'sse_started' => $sseStarted,
        ]);
    }

    /**
     * @return array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private function getPendingResponseState(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainPendingResponseState;
        }

        if ($this->fiberPendingResponseStates === null) {
            $this->fiberPendingResponseStates = new \WeakMap();
        }

        return $this->fiberPendingResponseStates[$fiber] ?? $this->emptyPendingResponseState();
    }

    /**
     * @param array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool} $state
     */
    private function setPendingResponseState(array $state): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainPendingResponseState = $state;
            return;
        }

        if ($this->fiberPendingResponseStates === null) {
            $this->fiberPendingResponseStates = new \WeakMap();
        }

        $this->fiberPendingResponseStates[$fiber] = $state;
    }

    /**
     * @return array{cookies: array, headers: array, status_code:?int, explicit: bool, sse_started: bool}
     */
    private function emptyPendingResponseState(): array
    {
        return [
            'cookies' => [],
            'headers' => [],
            'status_code' => null,
            'explicit' => false,
            'sse_started' => false,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }
    
    /**
     * 获取请求计数
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
    
    /**
     * 重新加载配置（热更新使用）
     */
    /**
     * 软重载 - 仅清理运行时缓存
     * 
     * 注意：PHP 常驻内存进程无法真正重新加载已加载的类。
     * 要加载新代码，需要重启 Worker 进程（由 Master 自动完成）。
     * 此方法仅用于清理运行时状态，不会加载新的代码文件。
     */
    public function reload(): void
    {
        // 清理运行时状态
        $this->router = null;
        $this->eventManager = null;
        
        // 重新获取实例（注意：这不会加载新的类定义）
        $this->router = ObjectManager::getInstance(Router::class);
        $this->eventManager = ObjectManager::getInstance(EventsManager::class);
    }
}
