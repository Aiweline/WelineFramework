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
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core as Router;
use Weline\Framework\Runtime\StateManager;

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
     * 超全局变量模拟器
     */
    private ?GlobalsEmulator $globalsEmulator = null;
    
    /**
     * 待发送的 Cookie（在 StateManager 重置前从 HeaderCollector 提取）
     * Worker 在构建 HTTP 响应时读取这些 Cookie 并添加 Set-Cookie 头
     */
    private array $pendingCookies = [];
    
    /**
     * 待发送的响应头（在 StateManager 重置前从 HeaderCollector 提取）
     */
    private array $pendingHeaders = [];
    
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
        
        // 预加载常用对象（进程级缓存）
        $this->eventManager = ObjectManager::getInstance(EventsManager::class);
        $this->router = ObjectManager::getInstance(Router::class);
        $this->globalsEmulator = new GlobalsEmulator();
        
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
        
        $this->requestCount++;
        
        // 性能统计：仅当请求耗时 > 1 秒时写入 var/log/wls_timing.log，便于定位 TTFB 瓶颈
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
            'reset_ms' => 0,
            'total_ms' => 0
        ];
        
        // 重置重定向计数器（每个新请求重置）
        if (!isset($_SERVER['WLS_REDIRECT_COUNT'])) {
            $_SERVER['WLS_REDIRECT_COUNT'] = 0;
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
                $this->globalsEmulator->emulate($request);
            }
            $timing['uri'] = ($_SERVER['REQUEST_URI'] ?? '') ?: '/';
            // 早期从 URL 提取语言/货币，供 RequestContext::syncFromServer() 使用
            $this->parseUrlLangCurrency($timing['uri']);
            // 在 $_SERVER 已为当前请求后再初始化请求上下文
            RequestContext::init();
            
            // DEV 环境或前端模式：记录请求日志
            $isDev = \defined('DEV') && DEV;
            $isFrontend = \defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE;
            if (($isDev || $isFrontend) && $request !== null) {
                $this->logWlsRequest($request, $isFrontend);
            }
            
            $_SERVER['WELINE_PARSER_URL'] = true;
            $_SERVER['WELINE_IS_MEDIA'] = false;
            $_SERVER['WLS_REQUEST_COUNT'] = $this->requestCount;
            
            // 语言/货币已在 init() 前通过 parseUrlLangCurrency() 从 URL 提取，run_before 可直接使用
            
            $t1 = \microtime(true);
            // 触发 run_before 事件
            $this->eventManager->dispatch('Weline_Framework::App::run_before');
            $t2 = \microtime(true);
            $timing['run_before_ms'] = \round(($t2 - $t1) * 1000, 2);
            
            // 如果run_before事件耗时过长，记录警告
            if ($timing['run_before_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_before event took ' . $timing['run_before_ms'] . 'ms');
            }
            
            // URL 解析
            // 注意：Url 类的静态变量重置现在由 StateManager 自动处理
            // 通过 Url::registerStateResets() 注册到 StateManager
            $urlParserStart = \microtime(true);
            $parse = \Weline\Framework\Http\Url::parser();
            $urlParserEnd = \microtime(true);
            $timing['url_parser_call_ms'] = \round(($urlParserEnd - $urlParserStart) * 1000, 2);
            
            if (\is_array($parse)) {
                $processUrlStart = \microtime(true);
                $this->processUrlParse($parse);
                $processUrlEnd = \microtime(true);
                $timing['process_url_parse_ms'] = \round(($processUrlEnd - $processUrlStart) * 1000, 2);
            }
            
            // 关键修复：Url::parser() 修改了 $_SERVER['REQUEST_URI']（去除了区域/货币/语言前缀）
            // 如果在 parser 之前有代码调用了 Request::getUri()（如 run_before 事件观察者），
            // 原始 URI 已被缓存在 Request 对象上，必须清除，否则 Router 会使用旧 URI 导致间歇性 404
            if ($request !== null) {
                $request->invalidateUriCache();
            }
            
            $t3 = \microtime(true);
            $timing['url_parser_ms'] = \round(($t3 - $t2) * 1000, 2);
            
            // WLS 模式：URL 解析后 $_SERVER 已被正确填充，重新调用 Router.__init() 刷新缓存键
            // 必须在此时调用，因为此时 WELINE_* 等关键变量已由 Url::parser() 设置到 $_SERVER
            $routerInitStart = \microtime(true);
            $this->router->__init();
            $routerInitEnd = \microtime(true);
            $timing['router_init_ms'] = \round(($routerInitEnd - $routerInitStart) * 1000, 2);
            
            // 路由处理（含控制器、视图，通常为主要耗时）
            $routerStartStart = \microtime(true);
            $result = $this->router->start();
            $routerStartEnd = \microtime(true);
            $timing['router_start_call_ms'] = \round(($routerStartEnd - $routerStartStart) * 1000, 2);
            $t4 = \microtime(true);
            $timing['router_start_ms'] = \round(($t4 - $t3) * 1000, 2);
            
            // 触发 run_after 事件
            $runAfterStart = \microtime(true);
            $data = new \Weline\Framework\DataObject\DataObject(['result' => $result]);
            $this->eventManager->dispatch('Weline_Framework::App::run_after', $data);
            $result = $data->getData('result');
            $runAfterEnd = \microtime(true);
            $timing['run_after_ms'] = \round(($runAfterEnd - $runAfterStart) * 1000, 2);
            $t5 = \microtime(true);
            
            // 如果run_after事件耗时过长，记录警告
            if ($timing['run_after_ms'] > 100) {
                w_log_warning('[WLS Performance Warning] run_after event took ' . $timing['run_after_ms'] . 'ms');
            }
            
            // 计算总耗时（用于性能监控）
            $t5_end = \microtime(true);
            $timing['total_ms'] = \round(($t5_end - $t0) * 1000, 2);
            
            // 如果总耗时超过500ms或DEV模式，添加性能数据到响应头
            $isDev = \defined('DEV') && DEV;
            if ($timing['total_ms'] >= 500 || $isDev) {
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
            if (\Weline\Framework\Http\Sse\SseContext::isSseEnabled()) {
                return '';  // SSE 响应已流式发送，不需要返回内容
            }
            
            if (\is_array($result)) {
                return \json_encode($result);
            }
            
            return (string) $result;
            
        } catch (\Weline\Framework\Http\StaticFileException $staticEx) {
            // 静态文件异常：转换为文件响应
            return $staticEx->toHttpString();
            
        } catch (\Weline\Framework\Http\DownloadException $downloadEx) {
            // 下载异常：转换为文件下载响应
            return $downloadEx->toHttpString();
            
        } catch (\Weline\Framework\Http\RedirectException $redirectEx) {
            // 重定向异常：转换为重定向响应
            // 记录重定向信息
            $redirectCount = $_SERVER['WLS_REDIRECT_COUNT'] ?? 0;
            $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
            $redirectUrl = $redirectEx->getRedirectUrl();
            
            // 如果重定向次数过多，记录警告
            if ($redirectCount > 5) {
                w_log_warning("[WLS Redirect Warning] Too many redirects: {$redirectCount}, current URI: {$currentUri}, redirect to: {$redirectUrl}");
            }
            
            // 创建重定向响应，并立即把 HeaderCollector 中的 Cookie 写入响应（登录 302 必须带 Set-Cookie，不依赖 Worker 合并）
            $redirectResponse = \Weline\Framework\Http\WlsResponse::redirect($redirectUrl, $redirectEx->getStatusCode());
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            $cookies = $hc->getCookies();
            foreach ($cookies as $cookie) {
                $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
                if (isset($cookie['expire']) && $cookie['expire'] !== 0) {
                    $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']);
                }
                if (!empty($cookie['path'])) {
                    $parts[] = 'Path=' . $cookie['path'];
                }
                if (!empty($cookie['domain'])) {
                    $parts[] = 'Domain=' . $cookie['domain'];
                }
                if (!empty($cookie['secure'])) {
                    $parts[] = 'Secure';
                }
                if (!empty($cookie['httpOnly'])) {
                    $parts[] = 'HttpOnly';
                }
                if (!empty($cookie['sameSite'])) {
                    $parts[] = 'SameSite=' . $cookie['sameSite'];
                }
                $redirectResponse->addCookieHeader(\implode('; ', $parts));
            }
            // 诊断头：便于在浏览器中确认 302 是否带 Cookie（0=未带，排查 Session/Nginx）
            $redirectResponse->setHeader('X-WLS-Redirect-Cookies', (string)\count($cookies));
            return $redirectResponse->toHttpString(false);
            
        } catch (\Weline\Framework\Http\NoRouterException $noRouterEx) {
            // 无路由异常：转换为 404/403 响应
            
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
            return \Weline\Framework\Http\WlsResponse::fromContent($errorContent, $noRouterEx->getStatusCode())->toHttpString(false);
            
        } catch (\Weline\Framework\Http\ResponseTerminateException $terminateEx) {
            // 通用响应终止异常：使用异常的 toHttpString() 方法
            return $terminateEx->toHttpString();
            
        } catch (\Throwable $e) {
            // 记录错误日志（DEV 环境）
            $isDev = \defined('DEV') && DEV;
            if ($isDev) {
                $this->logWlsError($e);
            } else {
                w_log_error('[WlsRuntime] Request error: ' . $e->getMessage());
            }
            
            // 返回错误响应
            return $this->handleException($e);
            
        } finally {
            $t6 = \microtime(true);
            // 在重置前保存 HeaderCollector 的 Cookie/Header（Worker 构建响应时需要）
            // StateManager::reset() 会清空 HeaderCollector，必须在此之前提取
            $hc = \Weline\Framework\Http\HeaderCollector::getInstance();
            $this->pendingCookies = $hc->getCookies();
            $this->pendingHeaders = $hc->getHeaders();
            // 确保总是重置状态
            $this->reset();
            $t7 = \microtime(true);
            $timing['reset_ms'] = \round(($t7 - $t6) * 1000, 2);
            $timing['total_ms'] = \round(($t7 - $t0) * 1000, 2);
            // 性能监控：记录所有超过500ms的请求，或DEV模式下记录所有请求
            $isDev = \defined('DEV') && DEV;
            // 添加请求方法、IP等信息
            $timing['method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $timing['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $timing['timestamp'] = date('Y-m-d H:i:s');
            $timing['redirect_count'] = $_SERVER['WLS_REDIRECT_COUNT'] ?? 0;

            // 总是记录到控制台（如果超过500ms或DEV模式）
            // 在DEV模式下，总是记录性能数据；在生产模式下，只记录超过500ms的请求
            if ($timing['total_ms'] >= 500 || $isDev) {
                // 构建性能日志字符串，包含关键指标
                $performanceSummary = sprintf(
                    '[WLS Performance] URI=%s Total=%.2fms | run_before=%.2fms | url_parser=%.2fms | router_init=%.2fms | router_start=%.2fms | run_after=%.2fms',
                    $timing['uri'],
                    $timing['total_ms'],
                    $timing['run_before_ms'],
                    $timing['url_parser_call_ms'] ?? 0,
                    $timing['router_init_ms'] ?? 0,
                    $timing['router_start_call_ms'] ?? 0,
                    $timing['run_after_ms']
                );
                w_log_debug($performanceSummary);
                
                $performanceLog = '[WLS Performance Detail] ' . \json_encode($timing, \JSON_UNESCAPED_UNICODE);
                w_log_debug($performanceLog);
                
                // 写入性能日志到 var/log
                $logFile = Env::VAR_DIR . 'log' . \DIRECTORY_SEPARATOR . 'wls_timing.log';
                $dir = \dirname($logFile);
                if (!\is_dir($dir)) {
                    @\mkdir($dir, 0755, true);
                }
                if (\is_dir($dir)) {
                    @\file_put_contents($logFile, \json_encode($timing, \JSON_UNESCAPED_UNICODE) . "\n", \FILE_APPEND);
                }
                
                // 如果总耗时超过1秒，输出详细分析
                if ($timing['total_ms'] >= 1000) {
                    $analysis = [];
                    if ($timing['run_before_ms'] > 200) {
                        $analysis[] = "run_before事件耗时过长: {$timing['run_before_ms']}ms";
                    }
                    if (isset($timing['url_parser_call_ms']) && $timing['url_parser_call_ms'] > 200) {
                        $analysis[] = "URL解析耗时过长: {$timing['url_parser_call_ms']}ms";
                    }
                    if (isset($timing['router_start_call_ms']) && $timing['router_start_call_ms'] > 500) {
                        $analysis[] = "路由处理耗时过长: {$timing['router_start_call_ms']}ms";
                    }
                    if ($timing['run_after_ms'] > 200) {
                        $analysis[] = "run_after事件耗时过长: {$timing['run_after_ms']}ms";
                    }
                    if (!empty($analysis)) {
                        w_log_debug('[WLS Performance Analysis] ' . implode('; ', $analysis));
                    }
                }
            }
        }
    }
    
    /**
     * 处理 URL 解析结果
     */
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
        $isBackendArea = ($area === 'backend' || $area === 'rest_backend');
        if (isset($_SERVER['REQUEST_METHOD']) && isset($parse['uri'])) {
            $uri = \Weline\Framework\Http\Url::decode_url($parse['uri']);
            // 后台/API 后台不覆盖 REQUEST_URI，保留 parser 已设置的带 /admin/ 前缀的路径，否则 Router 会拿到 pure_uri 导致 404
            if (!$isBackendArea) {
                $parse['server']['REQUEST_URI'] = $uri;
            }
            $parse['server']['QUERY_STRING'] = \Weline\Framework\Http\Url::parse_url($uri, 'query');
        }
        
        // 合并而非替换 $_SERVER
        foreach ($parse['server'] as $key => $value) {
            $_SERVER[$key] = $value;
        }

        // 确保 WELINE_AREA 与本次解析结果一致（防御 cache/合并遗漏导致 MessageManager、ACL 等误判区域）
        if (isset($parse['area']) && $parse['area'] !== '') {
            $_SERVER['WELINE_AREA'] = $parse['area'];
            RequestContext::area($parse['area']);
        }

        // 合并后确保 WELINE_FULL_REQUEST_URI 有效（防御 parser 未设置或覆盖）
        $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? '';
        if ($fullUri === '' || !\str_contains($fullUri, '://')) {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            $_SERVER['WELINE_FULL_REQUEST_URI'] = $scheme . '://' . $host . (\str_starts_with($path, '/') ? '' : '/') . $path;
        }
        
        // 设置后端标识
        $welineArea = $_SERVER['WELINE_AREA'] ?? '';
        $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'backend' || $welineArea === 'rest_backend');
        
        // 存入请求上下文
        RequestContext::area($welineArea);
        
        // 处理语言和货币
        if (!empty($parse['currency'])) {
            $_SERVER['WELINE_USER_CURRENCY'] = $parse['currency'];
            RequestContext::currency($parse['currency']);
        } else {
            // 设置默认值，确保模板访问时不会出现 undefined 警告
            $_SERVER['WELINE_USER_CURRENCY'] = $_SERVER['WELINE_USER_CURRENCY'] ?? RequestContext::currency();
        }
        if (!empty($parse['language'])) {
            $_SERVER['WELINE_USER_LANG'] = $parse['language'];
            RequestContext::locale($parse['language']);
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
        }
        if ($language !== null) {
            $_SERVER['WELINE_USER_LANG'] = $language;
            RequestContext::locale($language);
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
            return \Weline\Framework\Http\WlsResponse::fromContent(
                $this->formatExceptionAsHtml($e, $statusCode, $message),
                $statusCode
            )->setHeader('Content-Type', 'text/html; charset=UTF-8')->toHttpString(false);
        }
        
        // DEBUG 和生产模式：使用统一的 ErrorResponse 生成 JSON
        $isDebug = \defined('DEBUG') && DEBUG;
        $response = \Weline\Framework\Exception\ErrorResponse::fromException($e, $isDebug);
        
        return \Weline\Framework\Http\WlsResponse::fromContent(
            \Weline\Framework\Exception\ErrorResponse::toJson($response),
            $statusCode
        )->setHeader('Content-Type', 'application/json; charset=UTF-8')->toHttpString(false);
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
                    <dd>' . \htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') . '</dd>
                    <dt>请求方法：</dt>
                    <dd>' . \htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'GET', ENT_QUOTES, 'UTF-8') . '</dd>
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
     * 记录 WLS 请求日志（DEV 环境或前端模式）
     * 
     * @param Request $request 请求对象
     * @param bool $isFrontend 是否前端模式（前端模式输出到控制台，DEV 模式写入文件）
     */
    private function logWlsRequest(Request $request, bool $isFrontend = false): void
    {
        $isDev = \defined('DEV') && DEV;
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'type' => 'request',
            'request_uri' => $request->getUri(),
            'request_method' => $request->getMethod(),
            'request_count' => $this->requestCount,
        ];
        
        // 前端模式：输出到控制台（已在 worker.php 中输出，这里不再重复）
        // 注意：请求日志已在 worker.php 接收到请求的第一时间输出
        
        // DEV 模式：写入日志文件
        if ($isDev) {
            $logFile = Env::VAR_DIR . 'log' . \DIRECTORY_SEPARATOR . 'wls.log';
            $logDir = \dirname($logFile);
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            
            @\file_put_contents($logFile, \json_encode($logEntry, \JSON_UNESCAPED_UNICODE) . "\n", \FILE_APPEND);
        }
    }
    
    /**
     * 记录 WLS 错误日志（DEV 环境）
     */
    private function logWlsError(\Throwable $e): void
    {
        $isDev = \defined('DEV') && DEV;
        if (!$isDev) {
            return;
        }
        
        $logFile = Env::VAR_DIR . 'log' . \DIRECTORY_SEPARATOR . 'wls.log';
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => \date('Y-m-d H:i:s'),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'exception' => \get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
        
        @\file_put_contents($logFile, \json_encode($logEntry, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n", \FILE_APPEND);
    }
    
    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        // 使用 StateManager 执行所有重置操作
        StateManager::reset();
        
        // 重置超全局变量
        if ($this->globalsEmulator !== null) {
            $this->globalsEmulator->reset();
        }
        
        // 触发状态重置事件（允许其他模块清理状态）
        if ($this->eventManager !== null) {
            try {
                $this->eventManager->dispatch('Weline_Framework::Runtime::reset');
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
        $this->globalsEmulator = null;
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
        $cookies = $this->pendingCookies;
        $this->pendingCookies = [];
        return $cookies;
    }
    
    /**
     * 获取待发送的响应头（在 reset 前从 HeaderCollector 提取的副本）
     * 
     * @return array
     */
    public function consumePendingHeaders(): array
    {
        $headers = $this->pendingHeaders;
        $this->pendingHeaders = [];
        return $headers;
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
