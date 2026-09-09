<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/11
 * 时间：0:02
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * 
 * 维护模式拦截器：
 * - 监听 Weline_Framework::App::pre_route_gate 事件（最早时机）
 * - 此时 URL 未解析，无数据库连接，无第三方服务请求
 * - 从 generated/language 读取翻译，不依赖数据库
 * - 在 pub/errors/maintenance/ 下生成静态文件，可被 nginx 直接返回
 */

namespace Weline\Maintenance\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\MaintenanceStaticPage;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Maintenance\Helper\IpMatcher;
use Weline\Maintenance\Helper\UrlParser;
use Weline\Maintenance\Service\MaintenanceDevPreview;
use Weline\Maintenance\Service\MaintenanceStaticGenerator;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftService;

/**
 * 维护模式拦截器
 * 
 * 在 URL/FPC 前的强制门禁阶段拦截请求，检查维护模式状态
 * 如果处于维护模式，直接返回维护页面，避免任何数据库或第三方服务请求
 * 
 * 性能优化：
 * - 静态文件存储在 pub/errors/maintenance/ 目录，可被 nginx 直接返回
 * - 从 generated/language/{lang}.php 读取翻译，无数据库依赖
 * - 静态文件存在时直接 readfile() 返回
 */
class MaintenanceInterceptor implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * 默认白名单 URL（静态资源等）
     */
    private const DEFAULT_WHITE_URLS = [
        // 静态资源
        '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.eot',
        // 模块静态资源（维护页 CSS/JS 走此路径）
        '/view/statics/',
        // 媒体资源路径
        '/pub/static/', '/pub/media/', '/static/',
        // 维护页面资源
        '/pub/errors/',
        // 维护等待礼金 API（无库文件账本）
        '/maintenance/frontend/wait-gift',
        // 维护恢复轻量探测（禁止打完整业务页）
        '/maintenance/frontend/recovery-check',
    ];

    /**
     * 默认语言
     */
    private const DEFAULT_LANG = 'zh_Hans_CN';

    /**
     * 静态文件存储目录（相对于 BP，在 pub 下便于 nginx 直接访问）
     */
    private const STATIC_DIR = 'pub/errors/maintenance/';

    /**
     * 语言映射缓存
     */
    private static ?array $langMapping = null;

    /**
     * 翻译缓存
     */
    private static ?array $translations = null;

    private ?MaintenanceStaticGenerator $staticGenerator = null;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $rawUri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '/');
        $pureGuess = (string)(\parse_url($rawUri, \PHP_URL_PATH) ?: '/');

        // Special endpoints inspect REQUEST_URI themselves; avoid full UrlParser
        // when maintenance is off and these are not involved.
        if ($this->tryHandleRecoveryCheck($pureGuess)) {
            return;
        }
        if ($this->tryHandleWaitGiftApi($pureGuess)) {
            return;
        }

        if (\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER) {
            $parseEarly = $this->applyParsedRequestUri();
            $pure_uri = (string)($parseEarly['uri'] ?? $pureGuess);
            if (!$this->shouldServeMaintenanceResponse($pure_uri)) {
                return;
            }
            $this->sendMaintenanceResponse();
            return;
        }

        if ((string)($_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] ?? '') === '1'
            || (string)($_SERVER['WLS_INTERNAL_BACKEND_WARMUP'] ?? '') === '1'
            || (string)($_SERVER['WLS_INTERNAL_WARMUP'] ?? '') === '1'
        ) {
            return;
        }

        // CLI 模式不检查维护模式
        if (Runtime::isCli() && !(\defined('WLS_MODE') && WLS_MODE)) {
            return;
        }

        // 检查维护模式配置 — 关闭时尽早返回，不做完整 URI 解析
        if (!Env::system('maintenance')) {
            return;
        }

        $parseEarly = $this->applyParsedRequestUri();

        // 给Request对象设置当前模块名
        $request = ObjectManager::getInstance(Request::class);
        $request->setModuleName('Weline_Maintenance');
        $request->setRouter([
            'name' => 'Weline_Maintenance',
            'module' => 'Weline_Maintenance',
            'controller' => 'Maintenance',
            'action' => 'index',
        ]);
        // 直接使用 $_SERVER 获取请求 URI
        $parse = $parseEarly;
        $uri = (string)($parse['server']['ORIGIN_REQUEST_URI'] ?? \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', ''));
        $pure_uri = $parse['uri'];
        if (!$this->shouldServeMaintenanceResponse($pure_uri, $uri, $parse)) {
            return;
        }

        // 返回维护页面响应
        $this->sendMaintenanceResponse();
    }

    /**
     * Lightweight recovery probe: never render business HTML for maintenance
     * recovery HEAD/GET (header, dedicated path, or _maintenance_recovery_probe).
     */
    private function tryHandleRecoveryCheck(string $uri): bool
    {
        if (!$this->isRecoveryCheckRequest($uri)) {
            return false;
        }

        $inMaintenance = (\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER)
            || (bool)Env::system('maintenance');
        $status = $inMaintenance ? 503 : 200;

        throw new ResponseTerminateException(
            $status,
            '',
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Weline-Maintenance-Recovery' => $inMaintenance ? '1' : '0',
            ],
        );
    }

    private function isRecoveryCheckRequest(string $uri): bool
    {
        $rawUri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        $originUri = (string)\Weline\Framework\Env\WelineEnv::server('ORIGIN_REQUEST_URI', '');
        $haystack = $uri . "\n" . $rawUri . "\n" . $originUri;
        if (\str_contains($haystack, '/maintenance/frontend/recovery-check')) {
            return true;
        }

        $header = \trim((string)\Weline\Framework\Env\WelineEnv::server('HTTP_X_MAINTENANCE_RECOVERY_CHECK', ''));
        if ($header === '1' || \strcasecmp($header, 'true') === 0) {
            return true;
        }

        foreach ([$uri, $rawUri, $originUri] as $candidate) {
            $query = \parse_url((string)$candidate, \PHP_URL_QUERY);
            if (!\is_string($query) || $query === '') {
                continue;
            }
            \parse_str($query, $params);
            if (isset($params['_maintenance_recovery_probe']) && (string)$params['_maintenance_recovery_probe'] !== '') {
                return true;
            }
        }

        $qs = (string)\Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
        if ($qs !== '') {
            \parse_str($qs, $params);
            if (isset($params['_maintenance_recovery_probe']) && (string)$params['_maintenance_recovery_probe'] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Serve wait-gift JSON APIs even on the WLS maintenance worker (no full controller routing).
     */
    private function tryHandleWaitGiftApi(string $uri): bool
    {
        $rawUri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        $originUri = (string)\Weline\Framework\Env\WelineEnv::server('ORIGIN_REQUEST_URI', '');
        $haystack = $uri . "\n" . $rawUri . "\n" . $originUri;
        if (!\str_contains($haystack, '/maintenance/frontend/wait-gift')) {
            return false;
        }

        $candidate = $uri;
        foreach ([$rawUri, $originUri] as $probe) {
            if (\is_string($probe) && \str_contains($probe, '/maintenance/frontend/wait-gift')) {
                $candidate = $probe;
                break;
            }
        }

        $path = \parse_url($candidate, \PHP_URL_PATH);
        $path = \is_string($path) ? $path : $candidate;
        $action = \trim((string)\basename($path));
        $service = new WaitGiftService();
        $body = $this->readJsonBody();

        $result = match ($action) {
            'wave' => $this->waitGiftWavePayload(),
            'issue' => $service->issue([
                'gate' => $this->readWaitGiftCookie(WaitGiftService::COOKIE_GATE, (string)($body['gate'] ?? '')),
                'opaque_token' => $this->readWaitGiftCookie(WaitGiftService::COOKIE_WAIT, (string)($body['token'] ?? '')),
                'browser_key' => $this->readWaitGiftCookie(WaitGiftService::COOKIE_BROWSER, (string)($body['browser_key'] ?? '')),
                'guest_token' => (string)($body['guest_token'] ?? ''),
                'customer_id' => (string)($body['customer_id'] ?? ''),
                'selling_mode' => (string)($body['selling_mode'] ?? $body['cart_type'] ?? ''),
                'cookies' => $_COOKIE ?? [],
                'ip' => (string)\Weline\Framework\Env\WelineEnv::server('REMOTE_ADDR', ''),
                'user_agent' => (string)\Weline\Framework\Env\WelineEnv::server('HTTP_USER_AGENT', ''),
            ]),
            'heartbeat' => $service->heartbeat($this->readWaitGiftCookie(WaitGiftService::COOKIE_WAIT, (string)($body['token'] ?? ''))),
            'abandon' => $service->abandon($this->readWaitGiftCookie(WaitGiftService::COOKIE_WAIT, (string)($body['token'] ?? ''))),
            'redeem' => $service->redeem(
                $this->readWaitGiftCookie(WaitGiftService::COOKIE_WAIT, (string)($body['token'] ?? '')),
                [
                    'selling_mode' => (string)($body['selling_mode'] ?? $body['cart_type'] ?? ''),
                    'cookies' => $_COOKIE ?? [],
                ]
            ),
            default => [
                'success' => false,
                'error' => 'unknown_action',
                'message' => (string)\__('未知操作'),
                'debug_path' => $path,
                'debug_uri' => $uri,
                'debug_raw' => $rawUri,
            ],
        };

        if ($action === 'issue' && !empty($result['success']) && !empty($result['token'])) {
            Cookie::set(WaitGiftService::COOKIE_WAIT, (string)$result['token'], 86400, [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if (!empty($result['browser_key'])) {
                Cookie::set(WaitGiftService::COOKIE_BROWSER, (string)$result['browser_key'], 86400 * 30, [
                    'path' => '/',
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
        }

        $result['handler'] = 'maintenance_interceptor_wait_gift';
        $status = 200;
        if ($action === 'redeem') {
            $status = WaitGiftService::redeemHttpStatus($result);
        } elseif ($action === 'issue' || $action === 'heartbeat') {
            $status = !empty($result['success']) ? 200 : 400;
        } elseif ($action !== 'wave' && $action !== 'abandon' && empty($result['success'])) {
            $status = 400;
        }
        throw new ResponseTerminateException(
            $status,
            (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function waitGiftWavePayload(): array
    {
        $waves = new UpgradeWaveService();
        $wave = $waves->readWave();
        $service = new WaitGiftService($waves);
        $maint = (\defined('WLS_MAINTENANCE_WORKER') && WLS_MAINTENANCE_WORKER)
            || (bool)Env::system('maintenance');
        $enabled = (bool)($wave['wait_gift_enabled'] ?? false);
        $sellingMode = $service->resolveSellingMode(['cookies' => $_COOKIE ?? []]);
        $toc = $sellingMode !== 'tob';

        return [
            'success' => true,
            'maintenance' => $maint,
            'wait_gift_enabled' => $enabled,
            'wait_gift_eligible' => $enabled && $toc,
            'selling_mode' => $sellingMode,
            'audience' => $toc ? 'toc' : 'tob',
            'wave_id' => (string)($wave['wave_id'] ?? ''),
            'system_version' => (string)($wave['system_version_to'] ?? ''),
            'theme_version' => (string)($wave['theme_version_to'] ?? ''),
            'redeem_deadline_at' => $wave['redeem_deadline_at'] ?? null,
            'redeem_window_open' => $waves->isRedeemWindowOpen($wave),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        // WLS: php://input is empty; body lives in request context.
        $raw = (string)(\Weline\Framework\Env\WelineEnv::get('request.body', '') ?: '');
        if ($raw === '') {
            $raw = (string)(\file_get_contents('php://input') ?: '');
        }
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Read wait-gift cookie across CookieScope qualification and raw Cookie header
     * (WLS policy 503 may mint an unscoped gate before PHP Cookie jar is warm).
     */
    private function readWaitGiftCookie(string $name, string $fallback = ''): string
    {
        $scoped = (string)(Cookie::get($name, '') ?: '');
        if ($scoped !== '') {
            return $scoped;
        }
        if ($fallback !== '') {
            return $fallback;
        }

        $header = (string)\Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '');
        if ($header === '') {
            return '';
        }
        foreach (\explode(';', $header) as $part) {
            $part = \trim($part);
            if ($part === '' || !\str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = \explode('=', $part, 2);
            $k = \trim($k);
            if ($k === $name || \str_starts_with($k, $name)) {
                return \rawurldecode(\trim($v));
            }
        }

        return '';
    }

    private function applyParsedRequestUri(): array
    {
        $uri = (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        // Parse only for maintenance / wait-gift decisions.
        // Never replaceServer() with the stripped path: UrlParser removes the
        // backend/API area key from REQUEST_URI, and a later Url::parser() would
        // then treat /admin/... or /framework/query-bin as frontend → HTML 404
        // (and "Invalid Weline binary magic" on query-bin).
        return UrlParser::parse($uri);
    }

    /**
     * 判断是否应返回维护页（false = 放行当前请求继续走正常静态/业务链路）。
     *
     * @param array<string, mixed>|null $parse
     */
    private function shouldServeMaintenanceResponse(string $pure_uri, string $original_uri = '', ?array $parse = null): bool
    {
        $original_uri = $original_uri !== '' ? $original_uri : $pure_uri;

        if ($this->isWhitelisted($pure_uri)) {
            return false;
        }

        if (MaintenanceDevPreview::matchesRequestUri($pure_uri)) {
            return false;
        }

        $currentLang = $this->getCurrentLang();

        /** @var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $data = new DataObject([
            'white_urls' => self::DEFAULT_WHITE_URLS,
            'original_uri' => $original_uri,
            'uri' => $pure_uri,
            'parse' => $parse ?? [],
            'handled' => false,
            'language' => $currentLang,
        ]);
        $eventManager->dispatch('Weline_Maintenance::maintenance', $data);
        if ($data->getData('handled')) {
            return false;
        }

        $white_urls = $data->getData('white_urls') ?? [];
        foreach ($white_urls as $white_url_string) {
            if (!empty($white_url_string) && str_contains($original_uri, $white_url_string)) {
                return false;
            }
        }

        if ($this->checkBackendPath($pure_uri)) {
            $this->logBypass('backend_path', $pure_uri);
            return false;
        }

        if ($this->checkIpWhitelist()) {
            $this->logBypass('ip_whitelist', $pure_uri);
            return false;
        }

        if ($this->checkBypassKey()) {
            $this->logBypass('bypass_key', $pure_uri);
            return false;
        }

        return true;
    }

    /**
     * 检查 URI 是否在默认白名单中
     */
    private function isWhitelisted(string $uri): bool
    {
        foreach (self::DEFAULT_WHITE_URLS as $pattern) {
            if (str_contains($uri, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否为后端路径（维护模式下自动放行）
     * 
     * @param string $uri
     * @return bool
     */
    private function checkBackendPath(string $uri): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $backendPaths = $bypassConfig['backend_paths'] ?? [];
        
        if (empty($backendPaths) || !is_array($backendPaths)) {
            return false;
        }

        foreach ($backendPaths as $path) {
            if (!empty($path) && str_starts_with($uri, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查IP是否在白名单中
     * 
     * @return bool
     */
    private function checkIpWhitelist(): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $ipWhitelist = $bypassConfig['ip_whitelist'] ?? [];
        
        if (empty($ipWhitelist) || !is_array($ipWhitelist)) {
            return false;
        }

        $clientIp = IpMatcher::getClientIp();
        
        return IpMatcher::isIpInWhitelist($clientIp, $ipWhitelist);
    }

    /**
     * 检查Bypass Key验证
     * 支持URL参数、Header和Cookie三种方式
     * 
     * @return bool
     */
    private function checkBypassKey(): bool
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        $keyConfig = $bypassConfig['bypass_key'] ?? [];
        
        // 检查是否启用
        if (empty($keyConfig['enabled']) || empty($keyConfig['name']) || empty($keyConfig['value'])) {
            return false;
        }

        $keyName = $keyConfig['name'];
        $keyValue = $keyConfig['value'];
        $methods = $keyConfig['methods'] ?? ['url', 'header', 'cookie'];

        // 从URL参数检查
        if (in_array('url', $methods)) {
            $urlKey = $_GET[$keyName] ?? null;
            if ($urlKey === $keyValue) {
                return true;
            }
        }

        // 从Header检查
        if (in_array('header', $methods)) {
            $headerKey = w_env('server.' . strtolower('HTTP_' . strtoupper(str_replace('-', '_', $keyName)))) ?? null;
            if ($headerKey === $keyValue) {
                return true;
            }
        }

        // 从Cookie检查
        if (in_array('cookie', $methods)) {
            $cookieKey = \w_env_cookie($keyName);
            if ($cookieKey === $keyValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录绕过日志
     * 
     * @param string $bypassType 绕过类型：backend_path/ip_whitelist/bypass_key
     * @param string $uri 请求URI
     * @return void
     */
    private function logBypass(string $bypassType, string $uri): void
    {
        $bypassConfig = Env::getInstance()->getConfig('maintenance.bypass', []);
        
        // 如果未启用日志记录，直接返回
        if (empty($bypassConfig['log_bypass'])) {
            return;
        }

        try {
            $clientIp = IpMatcher::getClientIp();
            $userAgent = \w_env('server.http_user_agent') ?? '';
            $timestamp = date('Y-m-d H:i:s');
            
            $logDir = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . 'maintenance_bypass.log';
            $logMessage = sprintf(
                "[%s] Type: %s | IP: %s | URI: %s | UA: %s\n",
                $timestamp,
                $bypassType,
                $clientIp,
                $uri,
                substr($userAgent, 0, 200)
            );
            
            @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
        }
    }

    /**
     * 获取当前语言
     * 优先级：
     * 1. URL 查询参数中明确指定的语言（维护页语言切换器使用）
     * 2. URL 路径中明确指定的语言
     * 3. 默认语言（默认语言路由不带语言段，不能被 Cookie 或浏览器语言覆盖）
     */
    private function getCurrentLang(): string
    {
        $uri = (string)(
            \Weline\Framework\Env\WelineEnv::server('ORIGIN_REQUEST_URI', '')
            ?: \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '')
        );
        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: '/');
        $queryString = (string)(\parse_url($uri, \PHP_URL_QUERY) ?: '');
        if ($queryString === '') {
            $queryString = (string)\Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
        }
        $cookieHeader = (string)\Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '');

        return MaintenanceStaticPage::resolveLang($path, $queryString, $cookieHeader);
    }

    /**
     * 转换语言代码格式
     * 从 i18n/lang_mapping.json 读取映射关系
     */
    private function convertLangCode(string $code): string
    {
        $mapping = $this->getLangMapping();
        return $mapping[$code] ?? self::DEFAULT_LANG;
    }

    /**
     * 获取语言映射表
     */
    private function getLangMapping(): array
    {
        if (self::$langMapping !== null) {
            return self::$langMapping;
        }

        $mappingFile = __DIR__ . '/../i18n/lang_mapping.json';
        
        if (is_file($mappingFile)) {
            $content = @file_get_contents($mappingFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['mapping'])) {
                    self::$langMapping = $data['mapping'];
                    return self::$langMapping;
                }
            }
        }

        // 默认基础映射
        self::$langMapping = [
            'zh-CN' => 'zh_Hans_CN',
            'zh' => 'zh_Hans_CN',
            'en-US' => 'en_US',
            'en' => 'en_US',
        ];
        return self::$langMapping;
    }

    private function getStaticGenerator(): MaintenanceStaticGenerator
    {
        return $this->staticGenerator ??= new MaintenanceStaticGenerator();
    }

    /**
     * 发送维护模式响应
     */
    private function sendMaintenanceResponse(): void
    {
        $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
        $lang = $this->getCurrentLang();
        
        // 检查是否是 API 请求
        $acceptHeader = \Weline\Framework\Env\WelineEnv::server('HTTP_ACCEPT', '');
        $uri = \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
        $isApiRequest = str_contains($acceptHeader, 'application/json') 
                     || str_contains($uri, '/api/') 
                     || str_contains($uri, '/rest/');
        
        // 开发环境下：通过查询参数 ?api=1 可以测试 API 维护模式响应
        if (!$isApiRequest && defined('DEV') && DEV) {
            $queryString = \Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
            parse_str($queryString, $queryParams);
            if (isset($queryParams['api']) && ($queryParams['api'] === '1' || $queryParams['api'] === 'true')) {
                $isApiRequest = true;
            }
        }

        $contentType = $isApiRequest
            ? 'application/json; charset=utf-8'
            : 'text/html; charset=utf-8';
        $body = $isApiRequest
            ? $this->renderApiResponse($lang, $retryAfter)
            : $this->renderHtmlResponse($lang);

        // Gate cookie proves the visitor hit a real maintenance response (anti-abuse for wait-gift).
        $gate = (new WaitGiftService())->mintGateToken();
        Cookie::set(WaitGiftService::COOKIE_GATE, $gate, 86400, [
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $setCookie = WaitGiftService::COOKIE_GATE . '=' . \rawurlencode($gate)
            . '; Path=/; Max-Age=86400; SameSite=Lax; HttpOnly';

        throw new ResponseTerminateException(
            503,
            $body,
            [
                'Content-Type' => $contentType,
                'Retry-After' => (string)$retryAfter,
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Set-Cookie' => $setCookie,
            ]
        );
    }

    /**
     * 发送 API JSON 响应
     */
    private function renderApiResponse(string $lang, int $retryAfter): string
    {
        return $this->getStaticGenerator()->renderJson($lang, $retryAfter);
    }

    /**
     * 发送 HTML 响应
     */
    private function renderHtmlResponse(string $lang): string
    {
        return $this->getStaticGenerator()->renderHtml($lang);
    }
}
