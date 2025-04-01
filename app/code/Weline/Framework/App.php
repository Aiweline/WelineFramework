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
use Weline\Framework\Manager\ObjectManager;
use const _PHPStan_4afa27bf8\__;

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
            define('ENV_TEST', false);
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
            exit(0);
        }
        // 静态文件路径
        if (!defined('PUB')) {
            define('PUB', BP . 'pub' . DS);
        }
        // SERVER 整理
        if (!CLI) {
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
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
        // 通用加载
        \Weline\Framework\Common\Loader::load();
        // ############################# 环境配置 #####################
        // 环境
        $config = [];
        $env_filename = APP_PATH . 'etc/env.php';
        if (is_file($env_filename)) {
            $config = require $env_filename;
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

        // 助手函数
        $handle_functions = APP_ETC_PATH . 'functions.php';
        if (is_file($handle_functions)) {
            require $handle_functions;
        }

        // 调试模式
        if (!defined('DEV')) {
            define('DEV', isset($config['deploy']) && $config['deploy'] === 'dev');
        };
        if (!defined('PROD')) {
            define('PROD', isset($config['deploy']) && $config['deploy'] === 'prod');
        };
        // 代码美化模式
        if (!defined('PHP_CS')) {
            define('PHP_CS', $config['php-cs'] ?? false);
        };
        //报告错误
        DEBUG ? error_reporting(E_ALL) : error_reporting(0);

        // 错误报告
        if (DEV || CLI) {
            ini_set('error_reporting', E_ALL);
            register_shutdown_function(function () {
                $_error = error_get_last();
                if ($_error && in_array($_error['type'], [1, 4, 16, 64, 256, 4096, E_ALL], true)) {
                    if (CLI) {
                        echo __('致命错误：') . PHP_EOL;
                        echo __('文件：') . $_error['file'] . PHP_EOL;
                        echo __('行数：') . $_error['line'] . PHP_EOL;
                        echo __('消息：') . $_error['message'] . PHP_EOL;
                    } else {
                        echo '<b style="color: red">致命错误：</b></br>';
                        echo '<pre>';
                        echo __('文件：') . $_error['file'] . '</br>';
                        echo __('行数：') . $_error['line'] . '</br>';
                        echo __('消息：') . $_error['message'] . '</br>';
                        echo '</pre>';
                    }
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 100);
                }
            });
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
     */
    public static function run(): string
    {
        # ----------事件：run之前 开始------------
        self::init();
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventManager->dispatch('App::run_before');
        $result = '';
        # URL结构：[网站前缀]/[货币前缀]/[语言前缀]/[路由]，没有网站
        if (!CLI) {
            # 静态文件不用再分析店铺
            $is_static = false;
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|css|js|ico|txt|pdf|doc|docx|xls|xlsx|ppt|pptx)$/', $_SERVER['REQUEST_URI'])) {
                $is_static = true;
            }
            # 处理第一级语言代码
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
            self::detectWebsite($eventManager);
            $uri = $_SERVER['REQUEST_URI'];
            if (!$is_static and $uri and '/' !== $uri) {
                # 获取路由前缀，可能是货币码或者语言码  剩余URL结构：[货币前缀]/[语言前缀]/[路由]，没有网站
                $uri_arr = explode('/', ltrim($uri, '/'));
                if ($uri_arr) {
                    # 如果还有路由
                    $pre_path_1 = $uri_arr[0] ?? '';
                    switch ($pre_path_1) {
                        case Env::get('api'):
                            $_SERVER['WELINE_AREA'] = 'api';
                            $_SERVER['WELINE_AREA_ROUTE'] = Env::get('api');
                            array_shift($uri_arr);
                            $uri = '/' . implode('/', $uri_arr);
                            break;
                        case Env::get('api_admin'):
                            $_SERVER['WELINE_AREA'] = 'api_admin';
                            $_SERVER['WELINE_AREA_ROUTE'] = Env::get('api_admin');
                            array_shift($uri_arr);
                            $uri = '/' . implode('/', $uri_arr);
                            break;
                        case Env::get('admin'):
                            $_SERVER['WELINE_AREA'] = 'admin';
                            $_SERVER['WELINE_AREA_ROUTE'] = Env::get('admin');
                            array_shift($uri_arr);
                            $uri = '/' . implode('/', $uri_arr);
                            break;
                        default:
                            $_SERVER['WELINE_AREA'] = 'frontend';
                    }

                    $pre_path_1 = $uri_arr[0] ?? '';
                    if ($pre_path_1) {
                        $has_currency = false;
                        $has_language = false;
                        # 检查头路径$pre_path_1是否是货币
                        if (strlen($pre_path_1) === 3) {
                            $has_currency = self::detectCurrency($pre_path_1, $uri, $eventManager);
                        }
                        if (!$has_currency) {
                            if (strlen($pre_path_1) > 3 and ctype_lower(substr($pre_path_1, 0, 2)) and $pre_path_1[2] === '_') {
                                # 必须有前两个字符是否都是小写字母,且第三个字符必须是_
                                $has_language = self::detectLanguage($pre_path_1, $uri, $eventManager);
                            }
                        }
                        $pre_path_2 = $uri_arr[1] ?? '';
                        if ($pre_path_2) {
                            # 第一次未能探测到语言包，并且存在第二个路由时，必须有前两个字符是否都是小写字母,且第三个字符必须是_
                            if (!$has_language and $pre_path_2 and strlen($pre_path_2) > 3 and ctype_lower(substr($pre_path_2, 0, 2)) and $pre_path_2[2] === '_') {
                                # 如果查询得到属于语言包，则删除此路由
                                $has_language = self::detectLanguage($pre_path_2, $uri, $eventManager);
                            }
                            if (!$has_language and Cookie::get('WELINE_USER_LANG')) {
                                self::detectLanguage(Cookie::get('WELINE_USER_LANG'), $uri, $eventManager);
                            }
                            if (!$has_currency and strlen($pre_path_2) === 3) {
                                $has_currency = self::detectCurrency($pre_path_2, $uri, $eventManager);
                            }
                            if (!$has_currency and Cookie::get('WELINE_USER_CURRENCY')) {
                                self::detectCurrency(Cookie::get('WELINE_USER_CURRENCY'), $uri, $eventManager);
                            }
                        }
                        $_SERVER['REQUEST_URI'] = $uri;
                    } else {
                        $_SERVER['REQUEST_URI'] = implode('/', $uri_arr);
                    }
                } else {
                    $_SERVER['WELINE_AREA'] = 'frontend';
                }
            } else {
                $_SERVER['WELINE_AREA'] = 'frontend';
            }
            if (PROD) {
                try {
                    $result = ObjectManager::getInstance(\Weline\Framework\Router\Core::class)->start();
                } catch (\ReflectionException|App\Exception $e) {
                    throw new Exception(__('系统错误：%1', $e->getMessage()));
                }
            } else {
                $result = ObjectManager::getInstance(\Weline\Framework\Router\Core::class)->start();
            }
        }
        $data = new DataObject(['result' => $result]);
        $eventManager->dispatch('App::run_after', $data);
        $result = $data->getData('result');
        if (!CLI) {
            echo($result);
            exit(0);
        }
        return $result;
    }

    /**
     * @param EventsManager $eventManager
     * @return void
     * @throws Exception
     */
    public static function detectWebsite(EventsManager &$eventManager): void
    {
        # 如果当前请求的链接前缀和cookie中的前缀一致，则无需再判断 减少数据库回源判断
//        if ((isset($_COOKIE['WELINE_WEBSITE_ID']) and $_COOKIE['WELINE_WEBSITE_CODE'] and isset($_SERVER['WELINE_WEBSITE_URL']))
//            and str_starts_with($_SERVER['REQUEST_URI'], $_COOKIE['WELINE_WEBSITE_URL'])) {
//            $_SERVER['WELINE_WEBSITE_ID'] = $_COOKIE['WELINE_WEBSITE_ID'];
//            $_SERVER['WELINE_WEBSITE_CODE'] = $_COOKIE['WELINE_WEBSITE_CODE'];
//            $_SERVER['WELINE_WEBSITE_URL'] = $_COOKIE['WELINE_WEBSITE_URL'];
//            return;
//        }
        # 如果查询得到店铺，则处理店铺URI
        $data = new DataObject([
            'website_url' => '',
            'website_id' => '',
            'code' => '',
            'default_currency' => '',
            'default_language' => '',
            'default_timezone' => '',
        ]);
        $_SERVER['ORIGIN_TIMEZONE'] = date_default_timezone_get();
        $eventManager->dispatch('App::detect_website', $data);
        if ($website_url = $data->getData('website_url')
            and $website_id = $data->getData('website_id')
            and $code = $data->getData('code')
        ) {
            # 截取非店铺路径
            $website_url_pre = parse_url($website_url)['path'];
            if ('/' !== $website_url_pre and str_starts_with($_SERVER['REQUEST_URI'], $website_url_pre)) {
                $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($website_url_pre));
            }
            $pre_code = '/' . $code;
            if (str_starts_with($_SERVER['REQUEST_URI'], $pre_code)) {
                $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($pre_code));
            }
            $_SERVER['WELINE_WEBSITE_ID'] = $website_id;
            $_SERVER['WELINE_WEBSITE_URL'] = $website_url;
            $_SERVER['WELINE_WEBSITE_CODE'] = $data->getData('code');
        } else {
            $_SERVER['WELINE_WEBSITE_ID'] = 0;
            $_SERVER['WELINE_WEBSITE_CODE'] = 'default';
            $_SERVER['WELINE_WEBSITE_URL'] = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'];
        }
        if (empty(Cookie::get('WELINE_USER_CURRENCY'))) {
            $_SERVER['WELINE_USER_CURRENCY'] = $data->getData('default_currency');
        }
        if (empty(Cookie::get('WELINE_USER_LANG'))) {
            $_SERVER['WELINE_USER_LANG'] = $data->getData('default_language');
        }
        Cookie::set('WELINE_WEBSITE_ID', $_SERVER['WELINE_WEBSITE_ID'], 3600 * 24 * 30);
        Cookie::set('WELINE_WEBSITE_CODE', $_SERVER['WELINE_WEBSITE_CODE'], 3600 * 24 * 30);
        Cookie::set('WELINE_WEBSITE_URL', $_SERVER['WELINE_WEBSITE_URL'], 3600 * 24 * 30);
    }

    /**
     * @param string $code
     * @param string $uri
     * @param EventsManager $eventManager
     * @return bool
     */
    public static function detectCurrency(string $code, string &$uri, EventsManager &$eventManager): bool
    {
        if (!$code) return false;
        if (isset($_COOKIE['WELINE_USER_CURRENCY']) and $_COOKIE['WELINE_USER_CURRENCY'] == $code) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE_USER_CURRENCY', $code, 3600 * 24 * 30);
            $_SERVER['WELINE_USER_CURRENCY'] = $code;
            return true;
        }
        if (strtolower($code) === strtolower($_SERVER['WELINE_USER_CURRENCY'] ?? "")) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE_USER_CURRENCY', $code, 3600 * 24 * 30);
            $_SERVER['WELINE_USER_CURRENCY'] = $code;
            return true;
        }
        # 如果查询得到属于货币，则删除此路由$code
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        $eventManager->dispatch('App::detect_currency', $data);
        if ($data->getData('result')) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE_USER_CURRENCY', $code, 3600 * 24 * 30);
            $_SERVER['WELINE_USER_CURRENCY'] = $code;
            return true;
        }
        return false;
    }

    public static function detectLanguage(string $code, string &$uri, EventsManager &$eventManager): bool
    {
        if (!$code) return false;
        if (isset($_COOKIE['WELINE_USER_LANG']) and $_COOKIE['WELINE_USER_LANG'] == $code) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE_USER_LANG', $code, 3600 * 24 * 30);
            $_SERVER['WELINE_USER_LANG'] = $code;
            return true;
        }
        if ($default_lang = Env::get('lang')) {
            if (strtolower($code) === strtolower($default_lang)) {
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                Cookie::set('WELINE_USER_LANG', $code, 3600 * 24 * 30);
                $_SERVER['WELINE_USER_LANG'] = $code;
                return true;
            }
        }
        # 如果查询得到属于货币，则删除此路由
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        $eventManager->dispatch('App::detect_language', $data);
        if ($data->getData('result')) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE_USER_LANG', $code, 3600 * 24 * 30);
            $_SERVER['WELINE_USER_LANG'] = $code;
            return true;
        }
        return false;
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
