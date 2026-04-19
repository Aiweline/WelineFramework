<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Helper;
use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\DevToolMemoryLimitBootstrap;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\TelemetryBroadcaster;
use Weline\Framework\Runtime\System;
use Weline\Framework\Router\Core as Router;
use Weline\Framework\Session\SessionFactory;

class App
{
    /**
     * @var Env
     */

    private static Env $_env;

    private Context $context;

    /**
     * App 不再负责创建请求上下文。
     * 上下文必须由 Runtime 在构造 App 之前完成 Context::enter()。
     */
    public function __construct()
    {
        $current = Context::getCurrent();
        if ($current === null) {
            throw new \LogicException('Runtime must enter Context before constructing App');
        }
        $this->context = $current;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function dispatch(): Response
    {
        return Response::normalize(
            $this->runPipeline(),
            ObjectManager::getInstance(Response::class)
        );
    }

    public function runResponse(): Response
    {
        return $this->dispatch();
    }

    public function runRaw(): string
    {
        return $this->runPipeline();
    }

    private static function isRequestRuntime(): bool
    {
        if (!\defined('CLI') || !CLI) {
            return true;
        }

        $context = Context::getCurrent();
        return $context !== null && $context->get('meta.type') === 'request';
    }

    public function bootstrapRequestCycle(): void
    {
        DevToolMemoryLimitBootstrap::captureProcessMemoryBaselineIfUnset();
        self::init();
        self::syncCurrentContextFromGlobals();

        WelineEnv::set('parser_url', true, 'App bootstrapRequestCycle');
        WelineEnv::set('is_media', false, 'App bootstrapRequestCycle');
        WelineEnv::set('response.from_cache', false, 'App bootstrapRequestCycle');

        if (self::isRequestRuntime() && WelineEnv::get('is_static_file', null) === null) {
            $reqPath = \parse_url($this->getCurrentRequestUri(), \PHP_URL_PATH) ?: '/';
            WelineEnv::set('is_static_file', weline_is_static_file_path($reqPath), 'App bootstrapRequestCycle');
        }

        $this->primeRequestRouteHints($this->getCurrentRequestUri());
        RequestContext::init();
        DevToolMemoryLimitBootstrap::applyIfDevToolSessionActive();
    }

    public function dispatchRunBefore(): void
    {
        $this->resolveEventManager()->dispatch('Weline_Framework::App::run_before');
    }

    public function parseUrl(): ?array
    {
        if (!self::isRequestRuntime() || !WelineEnv::get('parser_url', false)) {
            return null;
        }

        $parse = Url::parser();
        return \is_array($parse) ? $parse : null;
    }

    public function applyParsedUrl(array $parse): void
    {
        if (!isset($parse['server']) || !\is_array($parse['server'])) {
            $parse['server'] = [];
        }

        $server = Context::current()->server();
        if (!\is_array($server)) {
            $server = [];
        }

        $area = (string)($parse['area'] ?? $parse['server']['WELINE_AREA'] ?? $server['WELINE_AREA'] ?? '');
        $isBackendArea = $area === 'backend' || $area === 'rest_backend';
        WelineEnv::set('route.is_backend', $isBackendArea, 'App applyParsedUrl');
        if (isset($parse['uri'])) {
            $uri = Url::decode_url((string)$parse['uri']);
            // 必须始终把 parser 产出的 uri 写回 REQUEST_URI。
            // 否则 backend 场景会保留旧 URI（含 backend key 前缀），
            // 导致后续 w_env('request.uri') / Router 读取到错误路径并 404。
            $parse['server']['REQUEST_URI'] = $uri;
            $parse['server']['QUERY_STRING'] = Url::parse_url($uri, 'query');
        }

        if (!isset($parse['server']['REQUEST_URI']) || $parse['server']['REQUEST_URI'] === '') {
            $parse['server']['REQUEST_URI'] = isset($parse['uri'])
                ? Url::decode_url((string)$parse['uri'])
                : $this->getCurrentRequestUri();
        }

        foreach ($parse['server'] as $key => $value) {
            Context::current()->set('input.server.' . $key, $value);
        }

        if ($area !== '') {
            WelineEnv::set('area', $area, 'App applyParsedUrl');
        }

        if (!empty($parse['currency'])) {
            WelineEnv::set('user.currency', (string)$parse['currency'], 'App applyParsedUrl');
        }
        if (!empty($parse['language'])) {
            WelineEnv::set('user.lang', (string)$parse['language'], 'App applyParsedUrl');
        }

        $welineArea = (string)WelineEnv::get('area', 'frontend');
        $isBackend = $welineArea === 'backend' || $welineArea === 'rest_backend';
        // 关键：syncCurrentContextFromGlobals() 会基于 input.server 重建 Context，
        // 若 parse['server'] 缺少 WELINE_IS_BACKEND，会把 route.is_backend 回写为 false。
        // 这里在回写前显式补齐该标记，确保后续 Router::__init() 读取一致。
        $parse['server']['WELINE_IS_BACKEND'] = $isBackend;
        Context::current()->set('input.server.WELINE_IS_BACKEND', $isBackend);
        WelineEnv::set('is_backend', $isBackend, 'App applyParsedUrl');
        WelineEnv::set('url_parsed', true, 'App applyParsedUrl');

        // 必须用 parser 已合并进 input.server 的 REQUEST_URI，不能读旧的 request.uri（WlsRuntime 预写会残留）。
        $serverMerged = Context::current()->server();
        $currentUri = Url::decode_url((string)($serverMerged['REQUEST_URI'] ?? '/'));
        if ($currentUri === '') {
            $currentUri = '/';
        }
        if (!\str_starts_with($currentUri, '/')) {
            $currentUri = '/' . $currentUri;
        }
        WelineEnv::set('request.uri', $currentUri, 'App applyParsedUrl');

        $scheme = (string)WelineEnv::get('request.scheme', Context::current()->get('input.scheme', 'http'));
        $host = (string)WelineEnv::get('server.http_host', Context::current()->get('input.host', 'localhost'));
        WelineEnv::set('origin_request_uri', $currentUri, 'App applyParsedUrl');
        WelineEnv::set('full_request_uri', $scheme . '://' . $host . $currentUri, 'App applyParsedUrl');

        $this->syncCookieRouteStateFromServer();
        self::syncCurrentContextFromGlobals();
        RequestContext::syncFromContext();
        $this->invalidateCurrentRequestUriCache();

        if (PROD && !WelineEnv::get('is_backend', false)) {
            $this->resolveEventManager()->dispatch('Weline_Framework::App::url_parsed_after');
        }
    }

    public function initializeRouter(): Router
    {
        $router = ObjectManager::getInstance(Router::class);
        $router->__init();
        return $router;
    }

    public function startSessionIfNeeded(): void
    {
        if (WelineEnv::get('is_static_file', false)) {
            return;
        }

        SessionFactory::getInstance()->createSession()->start('');
    }

    public function runRouter(?Router $router = null): mixed
    {
        $router ??= $this->initializeRouter();

        if (PROD) {
            try {
                return $router->start();
            } catch (\ReflectionException|App\Exception $e) {
                throw new Exception(__('系统错误：%{1}', $e->getMessage()));
            }
        }

        return $router->start();
    }

    public function dispatchRunAfter(mixed $result): mixed
    {
        $data = new DataObject(['result' => $result]);
        $this->resolveEventManager()->dispatch('Weline_Framework::App::run_after', $data);
        return $data->getData('result');
    }

    public function normalizeOutput(mixed $result): string
    {
        if ($result instanceof Response) {
            $result = self::absorbResponseObject($result);
        }

        if (\is_array($result)) {
            $result = \json_encode($result);
            throw new ResponseTerminateException(200, $result, ['Content-Type' => 'application/json']);
        }

        return (string)$result;
    }

    public function broadcastTelemetry(string $result, ?Request $request = null): string
    {
        return TelemetryBroadcaster::broadcast($result, $request);
    }

    /**
     * @DESC         |环境变量操作
     *
     * 参数区：
     *
     * @param string|null $key
     * @param null $value
     *
     * @return mixed
     */
    public static function Env(string $key = '', mixed $value = null): mixed
    {
        if (!isset(self::$_env)) {
            self::$_env = Env::getInstance();
        }
        if ($key && empty($value)) {
            return self::$_env->getConfig($key);
        }
        if ($key && $value) {
            return self::$_env->setConfig($key, $value);
        }

        return self::$_env;
    }

    /**
     * @DESC         |初始化
     *
     * 参数区：
     */
    public static function init()
    {
        # 系统变量
        #--1 目录分隔符
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        // ############################# 系统配置 #####################
        // 执行时间
        if (!defined('START_TIME')) {
            define('START_TIME', microtime(true));
        }
        // 单元测试环境
        if (!defined('ENV_TEST')) {
            // 检查是否通过参数启用了测试模式
            $enableTest = false;
            if (PHP_SAPI === 'cli') {
                global $argv;
                if (isset($argv) && is_array($argv)) {
                    foreach ($argv as $arg) {
                        if ($arg === '--test' || $arg === '-t' || strpos($arg, '--test=') === 0) {
                            $enableTest = true;
                            break;
                        }
                    }
                }
                // 检查环境变量
                if (!$enableTest && (getenv('WELINE_ENABLE_TEST') === '1' || getenv('WELINE_ENABLE_TEST') === 'true')) {
                    $enableTest = true;
                }
            }
            // Web 环境下不允许启用测试模式
            // 注释掉以下代码，确保 Web 请求中不会启用测试
            // if (!$enableTest && Context::current()->get('input.server.WELINE_ENABLE_TEST') === '1') {
            //     $enableTest = true;
            // }
            define('ENV_TEST', $enableTest);
        }
        // 运行模式
        if (!defined('CLI')) {
            define('CLI', PHP_SAPI === 'cli');
        }
        // 系统是否WIN
        if (!defined('IS_WIN')) {
            define('IS_WIN', strtolower(substr(PHP_OS, 0, 3)) === 'win');
        }
        // 检测项目根目录
        if (!defined('BP')) {
            echo('请告知根目录BP(常量)的位置。');
            System::exit(0);
        }
        // 静态文件路径
        if (!defined('PUB')) {
            define('PUB', BP . 'pub' . DS);
        }
        // SERVER 整理
        if (self::isRequestRuntime()) {
            $context = Context::current();
            $requestUri = (string)$context->get('input.server.REQUEST_URI', $context->get('input.uri', '/'));
            if ($requestUri === '') {
                $requestUri = '/';
            }
            $context->set('input.server.WELINE_ORIGIN_REQUEST_URI', $requestUri);
            // 完整的地址拼接（包含端口）
            $scheme = (string)$context->get('input.server.REQUEST_SCHEME', $context->get('input.scheme', 'http'));
            $host = (string)(
                $context->get('input.server.HTTP_HOST')
                ?? $context->get('input.server.SERVER_NAME')
                ?? $context->get('input.host', '')
                ?? ''
            );
            $port = (string)$context->get('input.server.SERVER_PORT', '80');
            // 如果 HTTP_HOST 不包含端口，且端口不是默认端口，则添加端口
            if ($host !== '' && !str_contains($host, ':') && $port != '80' && $port != '443') {
                $host .= ':' . $port;
            }
            if ($host !== '') {
                $context->set('input.server.WELINE_FULL_REQUEST_URI', $scheme . '://' . $host . $requestUri);
            } else {
                // WLS：GlobalsEmulator / processUrlParse 可能已写入完整 URI；避免 host 暂不可见时清空导致 FPC 键回退到 http://localhost/
                $prior = (string)$context->get('input.server.WELINE_FULL_REQUEST_URI', '');
                if ($prior !== '' && str_contains($prior, '://')) {
                    // 保留
                } else {
                    $context->set('input.server.WELINE_FULL_REQUEST_URI', '');
                }
            }
        } else {
            Context::current()->set('input.server.WELINE_FULL_REQUEST_URI', '');
        }

        // ############################# 应用相关配置 #####################
        // 应用 目录 (默认访问 web)
        if (!defined('APP_PATH')) {
            define('APP_PATH', BP . 'app' . DS);
        }
        if (!defined('APP_CODE_PATH')) {
            define('APP_CODE_PATH', BP . 'app' . DS . 'code' . DS);
        }
        // 应用配置文件
        if (is_file(APP_CODE_PATH . 'config.php')) {
            require APP_CODE_PATH . 'config.php';
        }
        // 开发 目录
        if (!defined('DEV_PATH')) {
            define('DEV_PATH', BP . 'dev' . DS);
        }
        // 主题 目录
        if (!defined('APP_DESIGN_PATH')) {
            define('APP_DESIGN_PATH', APP_CODE_PATH . 'design' . DS);
        }
        // 静态 目录
        if (!defined('APP_STATIC_PATH')) {
            define('APP_STATIC_PATH', PUB . 'static' . DS);
        }
        // 应用 配置 目录 (默认访问 etc)
        if (!defined('APP_ETC_PATH')) {
            define('APP_ETC_PATH', BP . 'app' . DS . 'etc' . DS);
        }

        // 系统UMASK
        if (!defined('SYSTEM_UMASK')) {
            define('SYSTEM_UMASK', 0022);
        }
        umask(SYSTEM_UMASK);
        // ############################# 环境配置 #####################
        // 先加载环境配置，以便判断是否为开发者模式
        // 环境
        $config = [];
        $env_filename = APP_PATH . 'etc/env.php';
        if (is_file($env_filename)) {
            $config = require $env_filename;
        }
        
        // 提前加载辅助函数，以便使用 w_array_get 点号语法访问配置
        require_once __DIR__ . '/Common/functions.php';
        
        // 开发者模式下的 OpCache 处理
        // 性能优化：不再每次请求都调用 opcache_reset()，改为按需失效
        // opcache_reset() 会清除所有缓存，严重影响性能
        // 建议：在开发环境中配置 opcache.revalidate_freq=0 和 opcache.validate_timestamps=1
        if (w_array_get($config, 'system.deploy') === 'dev') {
            // 仅在需要时禁用 OpCache（通过配置控制）
            if (isset($config['opcache_disable']) && $config['opcache_disable'] && function_exists('opcache_get_status')) {
                if (ini_get('opcache.enable')) {
                    ini_set('opcache.enable', '0');
                }
            }
            // 注意：如果需要强制刷新 OpCache，请运行 CLI 命令: php bin/w cache:flush
            // 或在配置中设置 opcache_reset_on_request=true（不推荐，影响性能）
            if (isset($config['opcache_reset_on_request']) && $config['opcache_reset_on_request']) {
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
            }
        }
        
        // 调试模式
        if (!defined('DEBUG')) {
            if (isset($config['debug']) and $config['debug']) {
                define('DEBUG', true);
            } else {
                if (!defined('DEBUG') and isset($config['debug_key'])) {
                    if ((\w_env_get('debug') !== null && (\w_env_get('debug') === $config['debug_key'])) || (Cookie::get('w_debug') === '1')) {
                        define('DEBUG', true);
                    } else {
                        define('DEBUG', false);
                    }
                } else {
                    define('DEBUG', false);
                }
            }
        }
        if (\w_env_get('debug') !== null && isset($config['debug_key'])) {
            if (\w_env_get('debug') === $config['debug_key']) {
                setcookie('w_debug', '1', 0, '/', '', false, false);
                setcookie('w_debug', '1', 0, '/' . $config['admin'], '', false, false);
            } elseif (\w_env_get('debug') === '0') {
                setcookie('w_debug', '', 0, '/', '', false, false);
                setcookie('w_debug', '', 0, '/' . $config['admin'], '', false, false);
            }
        }
        // 沙盒模式
        if (!defined('SANDBOX')) {
            if (isset($config['sandbox_key'])) {
                if ((\w_env_get('sandbox') !== null && (\w_env_get('sandbox') === $config['sandbox_key'])) || (Cookie::get('w_sandbox') === '1')) {
                    define('SANDBOX', true);
                } else {
                    define('SANDBOX', false);
                }
            } else {
                define('SANDBOX', false);
            }
        }
        if (isset($config['sandbox_key']) && \w_env_get('sandbox') !== null) {
            if (\w_env_get('sandbox') === $config['sandbox_key']) {
                setcookie('w_sandbox', '1', 0, '/', '', false, false);
                setcookie('w_sandbox', '1', 0, '/' . $config['admin'], '', false, false);
            } elseif (\w_env_get('sandbox') === '0') {
                setcookie('w_sandbox', '', 0, '/', '', false, false);
                setcookie('w_sandbox', '', 0, '/' . $config['admin'], '', false, false);
            }
        }

        // 通用加载（在关闭 OpCache 之后加载，确保代码不会被缓存）
        \Weline\Framework\Common\Loader::load();
        
        // 如果启用了测试模式，尝试加载 Pest 测试框架
        // 重要：只在 CLI 模式下加载，Web 请求生命周期中不允许运行测试框架
        if (CLI && defined('ENV_TEST') && ENV_TEST === true) {
            try {
                \Weline\Framework\UnitTest\Pest\Boot::boot();
            } catch (\Exception $e) {
                // 如果 Pest 未安装，静默失败（不影响正常应用运行）
                if (DEBUG) {
                    w_log_error('Pest 测试框架加载失败: ' . $e->getMessage(), [], 'framework_pest');
                }
            }
        }
        
        // 助手函数
        $handle_functions = APP_ETC_PATH . 'functions.php';
        if (is_file($handle_functions)) {
            require $handle_functions;
        }

        // 调试模式
        if (!defined('DEV')) {
            define('DEV', w_array_get($config, 'system.deploy') === 'dev');
        };
        if (!defined('PROD')) {
            define('PROD', w_array_get($config, 'system.deploy') === 'prod');
        };
        
        // 代码美化模式
        if (!defined('PHP_CS')) {
            define('PHP_CS', w_array_get($config, 'dev.php_cs', false));
        };
        //报告错误
        DEBUG ? error_reporting(E_ALL) : error_reporting(0);

        // 根据调试模式设置PHP错误显示
        if (DEBUG) {
            // 调试模式：显示所有错误
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            ini_set('log_errors', '1');
        } else {
            // 生产模式：关闭错误显示，但记录到日志
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        }
        
        // 设置 PHP 错误日志路径到 var/log/php_error.log
        $phpErrorLogFile = Env::VAR_DIR . 'log' . DS . 'php_error.log';
        $phpErrorLogDir = dirname($phpErrorLogFile);
        if (!is_dir($phpErrorLogDir)) {
            @mkdir($phpErrorLogDir, 0755, true);
        }
        ini_set('error_log', $phpErrorLogFile);

        // 错误报告（致命错误由 Framework\Exception\Handler\ShutdownHandler 或 Server\Log\Error 层统一输出 [E_ERROR] 格式，此处不再重复输出「致命错误」）
        if (DEV || CLI) {
            ini_set('error_reporting', E_ALL);
        }
    }

    /**
     * 运行时抽象层入口：Runtime::createRuntime() 按当前进程检测选用 WlsRuntime 或 FpmRuntime，再 bootstrap → handle → terminate。
     *
     * @return string 响应内容
     * @throws Exception
     */
    public static function runWithRuntime(): string
    {
        $runtime = Runtime::createRuntime();
        $runtime->bootstrap();
        try {
            return $runtime->handle();
        } finally {
            $runtime->terminate();
        }
    }

    private function runPipeline(): mixed
    {
        $this->bootstrapRequestCycle();

        $runBeforeStart = microtime(true);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('run_before');
        }
        
        $this->dispatchRunBefore();

        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('run_before', (microtime(true) - $runBeforeStart) * 1000, 'framework');
        }
        $result = '';
        
        if (self::isRequestRuntime()) {
            $urlParserStart = microtime(true);
            $parse = $this->parseUrl();
            if (\is_array($parse)) {
                $this->applyParsedUrl($parse);
            }
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('url_parser', (microtime(true) - $urlParserStart) * 1000, 'framework');
            }

            $this->startSessionIfNeeded();
            $routerStartBegin = microtime(true);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('router_start');
            }
            $result = $this->runRouter();
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('router_start', (microtime(true) - $routerStartBegin) * 1000, 'framework');
            }
        }

        $runAfterStart = microtime(true);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('run_after');
        }
        $result = $this->dispatchRunAfter($result);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('run_after', (microtime(true) - $runAfterStart) * 1000, 'framework');
        }

        $resultStr = $this->normalizeOutput($result);
        return $this->broadcastTelemetry($resultStr, $this->resolveRequest());
    }

    /**
     * @DESC         |框架应用运行
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     * @throws Exception
     * @throws \Weline\Framework\Http\ResponseTerminateException 响应终止异常，由 Runtime 层捕获处理
     */
    public static function run(): string
    {
        # ----------事件：run之前 开始------------
        return (new self())->runPipeline();
    }

    /**
     * @DESC         |安装
     *
     * 参数区：
     */
    public function install(): void
    {
        require BP . 'setup/index.php';
    }

    /**
     * @DESC         | 助手函数
     *
     * 参数区：
     *
     * @return Helper
     */
    public static function helper(): Helper
    {
        return new App\Helper();
    }

    private function primeRequestRouteHints(string $uri): void
    {
        if ($uri === '' || $uri === '/') {
            return;
        }

        $segments = \explode('/', \trim($uri, '/'));
        if ($segments === []) {
            return;
        }

        $currency = null;
        $language = null;
        $checkCount = \min(4, \count($segments));
        for ($i = 0; $i < $checkCount; $i++) {
            $segment = $segments[$i];
            if ($segment === '') {
                continue;
            }

            if ($currency === null && \strlen($segment) === 3 && \ctype_upper($segment)) {
                $currency = $segment;
                continue;
            }

            if ($language === null && \strlen($segment) >= 5 && \strlen($segment) <= 11
                && \preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(_[A-Z]{2})?$/', $segment)) {
                $language = $segment;
            }

            if ($currency !== null && $language !== null) {
                break;
            }
        }

        $context = Context::getCurrent();
        if ($currency !== null) {
            WelineEnv::set('user.currency', $currency, 'App primeRequestRouteHints');
            $context?->set('route.currency', $currency);
            $context?->set('input.server.WELINE_USER_CURRENCY', $currency);
        }
        if ($language !== null) {
            WelineEnv::set('user.lang', $language, 'App primeRequestRouteHints');
            $context?->set('route.language', $language);
            $context?->set('input.server.WELINE_USER_LANG', $language);
        }
    }

    private function syncCookieRouteStateFromServer(): void
    {
        if (WelineEnv::get('is_static_file', false)) {
            return;
        }

        $defaultCookies = [
            'WELINE_USER_LANG',
            'WELINE_USER_CURRENCY',
            'WELINE_WEBSITE_ID',
            'WELINE_WEBSITE_CODE',
            'WELINE_WEBSITE_URL',
        ];

        $cookiesToSet = [];
        foreach ($defaultCookies as $key) {
            $value = $this->getContextServerValue($key, null);
            if ($value === null) {
                $value = \in_array($key, ['WELINE_WEBSITE_ID', 'WELINE_WEBSITE_CODE'], true) ? '' : null;
            }
            if ($value === null) {
                throw new Exception(__('系统错误：%{1}', $key));
            }

            $value = (string)$value;
            if (Cookie::get($key) !== $value) {
                $cookiesToSet[$key] = $value;
            }
        }

        foreach ($cookiesToSet as $key => $value) {
            Cookie::set($key, $value, 3600 * 24 * 30, []);
        }
    }

    private function invalidateCurrentRequestUriCache(): void
    {
        $request = $this->resolveRequest();
        if ($request !== null && \method_exists($request, 'invalidateUriCache')) {
            $request->invalidateUriCache();
        }
    }

    private function resolveEventManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    private function resolveRequest(): ?Request
    {
        $resolvedClass = ObjectManager::parserClass(Request::class);
        $resolvedInstance = ObjectManager::_getInstance($resolvedClass);
        if ($resolvedInstance instanceof Request) {
            return $resolvedInstance;
        }

        $requestInstance = ObjectManager::_getInstance(Request::class);
        return $requestInstance instanceof Request ? $requestInstance : null;
    }

    private function getCurrentRequestUri(): string
    {
        return (string)WelineEnv::get('request.uri', Context::current()->get('input.uri', '/'));
    }

    private function getContextServerValue(string $key, mixed $default = null): mixed
    {
        return Context::current()->get('input.server.' . $key, $default);
    }

    private static function syncCurrentContextFromGlobals(): void
    {
        if (!Context::hasCurrent()) {
            return;
        }

        $context = Context::current();
        WelineEnv::getInstance()->initFromSnapshot(
            \is_array($context->get('input.query')) ? $context->get('input.query') : [],
            \is_array($context->get('input.post')) ? $context->get('input.post') : [],
            \is_array($context->get('input.cookie')) ? $context->get('input.cookie') : [],
            \is_array($context->get('input.files')) ? $context->get('input.files') : [],
            \is_array($context->get('input.server')) ? $context->get('input.server') : [],
        );
    }

    private static function absorbResponseObject(Response $response): string
    {
        $requestResponse = ObjectManager::getInstance(Response::class);
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

}
