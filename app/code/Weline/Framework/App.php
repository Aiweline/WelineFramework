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
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\TelemetryBroadcaster;
use Weline\Framework\Runtime\System;
use Weline\Framework\Session\SessionFactory;

class App
{
    /**
     * @var Env
     */

    private static Env $_env;

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
            // if (!$enableTest && isset($_SERVER['WELINE_ENABLE_TEST']) && $_SERVER['WELINE_ENABLE_TEST'] === '1') {
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
        if (!CLI) {
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
            // 完整的地址拼接（包含端口）
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $port = $_SERVER['SERVER_PORT'] ?? '80';
            // 如果 HTTP_HOST 不包含端口，且端口不是默认端口，则添加端口
            if (!str_contains($host, ':') && $port != '80' && $port != '443') {
                $host .= ':' . $port;
            }
            $_SERVER['WELINE_FULL_REQUEST_URI'] = $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
        }else{
            $_SERVER['WELINE_FULL_REQUEST_URI'] = '';
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
                    if ((!empty($_GET['debug']) && ($_GET['debug'] === $config['debug_key'])) || (Cookie::get('w_debug') === '1')) {
                        define('DEBUG', true);
                    } else {
                        define('DEBUG', false);
                    }
                } else {
                    define('DEBUG', false);
                }
            }
        }
        if (isset($_GET['debug']) && isset($config['debug_key'])) {
            if ($_GET['debug'] === $config['debug_key']) {
                setcookie('w_debug', '1', 0, '/', '', false, false);
                setcookie('w_debug', '1', 0, '/' . $config['admin'], '', false, false);
            } elseif ($_GET['debug'] === '0') {
                setcookie('w_debug', '', 0, '/', '', false, false);
                setcookie('w_debug', '', 0, '/' . $config['admin'], '', false, false);
            }
        }
        // 沙盒模式
        if (!defined('SANDBOX')) {
            if (isset($config['sandbox_key'])) {
                if ((!empty($_GET['sandbox']) && ($_GET['sandbox'] === $config['sandbox_key'])) || (Cookie::get('w_sandbox') === '1')) {
                    define('SANDBOX', true);
                } else {
                    define('SANDBOX', false);
                }
            } else {
                define('SANDBOX', false);
            }
        }
        if (isset($config['sandbox_key']) && isset($_GET['sandbox'])) {
            if ($_GET['sandbox'] === $config['sandbox_key']) {
                setcookie('w_sandbox', '1', 0, '/', '', false, false);
                setcookie('w_sandbox', '1', 0, '/' . $config['admin'], '', false, false);
            } elseif ($_GET['sandbox'] === '0') {
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
     * 使用 FpmRuntime 运行框架（运行时抽象层入口）
     * 
     * 此方法使用 FpmRuntime 统一运行时接口，与 WLS 模式共享相同的抽象层
     * 推荐在新项目中使用，或需要与 WLS 模式保持一致行为时使用
     * 
     * @return string 响应内容
     * @throws Exception
     */
    public static function runWithRuntime(): string
    {
        $runtime = new \Weline\Framework\Runtime\FpmRuntime();
        $runtime->bootstrap();
        
        try {
            $result = $runtime->handle(null);
            return $result;
        } finally {
            $runtime->terminate();
        }
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
        self::init();
        $_SERVER['WELINE_PARSER_URL'] = true;  // 是否解析URL
        $_SERVER['WELINE_IS_MEDIA'] = false;  // 是否媒体资源
        // 唯一判断处：静态文件仅按 path 判断，其他处只读 WELINE_IS_STATIC_FILE
        if (!CLI && !isset($_SERVER['WELINE_IS_STATIC_FILE'])) {
            $reqPath = \parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $_SERVER['WELINE_IS_STATIC_FILE'] = weline_is_static_file_path($reqPath);
        }

        // 性能优化：延迟获取 EventsManager，只在需要时实例化
        static $eventManager = null;
        if ($eventManager === null) {
            $eventManager = ObjectManager::getInstance(EventsManager::class);
        }
        
        $runBeforeStart = microtime(true);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('run_before');
        }
        $eventManager->dispatch('Weline_Framework::App::run_before');
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('run_before', (microtime(true) - $runBeforeStart) * 1000, 'framework');
        }
        $result = '';
        # URL结构：[网站前缀]/{区域前缀}/{货币前缀}/{语言前缀}/[模组前缀]/[路由]，没有网站
        if (!CLI) {
            $urlParserStart = microtime(true);
            $parse = null;
            if ($_SERVER['WELINE_PARSER_URL']) {
                $parse = Url::parser();
            }

            # url 重写 兼容原本携带的参数和当前重写原参数
            if (is_array($parse)) {
                if ($_SERVER['REQUEST_METHOD'] && isset($parse['uri'])) {
                    $uri = Url::decode_url($parse['uri']);
                    $parse['server']['REQUEST_URI'] = $uri;
                    $parse['server']['QUERY_STRING'] = Url::parse_url($uri, 'query');
                }
                $_SERVER = $parse['server'];
                
                // 根据 WELINE_AREA 设置后端标识，方便后续判断
                $welineArea = $_SERVER['WELINE_AREA'] ?? '';
                $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'backend' || $welineArea === 'rest_backend');
                // 标记 URL 解析已完成（用于 CheckFullPageCache 判断）
                $_SERVER['WELINE_URL_PARSED'] = true;
                
                if (empty($_SERVER['WELINE_IS_STATIC_FILE'])) {
                    $default_cookies = [
                        'WELINE_USER_LANG',
                        'WELINE_USER_CURRENCY',
                        'WELINE_WEBSITE_ID',
                        'WELINE_WEBSITE_CODE',
                        'WELINE_WEBSITE_URL',
                    ];
                    if ($parse['currency']) {
                        $_SERVER['WELINE_USER_CURRENCY'] = $parse['currency'];
                    }
                    if ($parse['language']) {
                        $_SERVER['WELINE_USER_LANG'] = $parse['language'];
                    }
                    // 性能优化：批量检查并设置 Cookie，减少 Cookie::set 调用次数
                    $cookiesToSet = [];
                    foreach ($default_cookies as $key) {
                        if (!isset($_SERVER[$key])) {
                            if (in_array($key, ['WELINE_WEBSITE_ID', 'WELINE_WEBSITE_CODE'], true)) {
                                $_SERVER[$key] = '';
                            } else {
                                throw new Exception(__('系统SERVER缺少key：%{1}', $key));
                            }
                        }
                        // 只在值发生变化时设置 Cookie（减少不必要的 Cookie 操作）
                        $currentCookieValue = Cookie::get($key);
                        if ($currentCookieValue !== $_SERVER[$key]) {
                            $cookiesToSet[$key] = $_SERVER[$key];
                        }
                    }
                    // 批量设置 Cookie
                    foreach ($cookiesToSet as $key => $value) {
                        Cookie::set($key, $value, 3600 * 24 * 30, []);
                    }
                }
                
                // URL 解析后，再次检查全页缓存（此时 WELINE_IS_BACKEND 已设置）
                if (PROD && !($_SERVER['WELINE_IS_BACKEND'] ?? false)) {
                    $eventManager->dispatch('Weline_Framework::App::url_parsed_after');
                }
            }
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('url_parser', (microtime(true) - $urlParserStart) * 1000, 'framework');
            }
            // 请求早期统一启动 Session（从 Cookie + 存储加载），供各区域（后台/前台等）复用；静态资源不启动

            if (empty($_SERVER['WELINE_IS_STATIC_FILE'])) {
                SessionFactory::getInstance()->createSession()->start(null);
            }
            $routerStartBegin = microtime(true);
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::pushCurrentParent('router_start');
            }
            if (PROD) {
                try {
                    $result = ObjectManager::getInstance(\Weline\Framework\Router\Core::class)->start();
                } catch (\ReflectionException|App\Exception $e) {
                    throw new Exception(__('系统错误：%{1}', $e->getMessage()));
                }
            } else {
                $result = ObjectManager::getInstance(\Weline\Framework\Router\Core::class)->start();
            }
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan('router_start', (microtime(true) - $routerStartBegin) * 1000, 'framework');
            }
        }
        $runAfterStart = microtime(true);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::pushCurrentParent('run_after');
        }
        $data = new DataObject(['result' => $result]);
        $eventManager->dispatch('Weline_Framework::App::run_after', $data);
        $result = $data->getData('result');
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::popCurrentParent();
            RequestLifecycleTrace::recordSpan('run_after', (microtime(true) - $runAfterStart) * 1000, 'framework');
        }
        if(is_array($result)) {
            $result = json_encode($result);
            // 使用 ResponseTerminateException 替代 die()，由 Runtime 层统一处理
            throw new \Weline\Framework\Http\ResponseTerminateException(200, $result, ['Content-Type' => 'application/json']);
        }
        $resultStr = (string) $result;
        // 仅广播遥测事件，具体注入/展示由监听者模块处理（Framework 与上层模块解耦）
        $resultStr = TelemetryBroadcaster::broadcast($resultStr);
        // 返回结果，由调用方（index.php 或 Runtime）决定如何处理
        return $resultStr;
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
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return Helper
     */
    public static function helper(): Helper
    {
        return new App\Helper();
    }
}
