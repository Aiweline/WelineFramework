<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework;

use SebastianBergmann\CodeCoverage\StaticAnalysis\CacheWarmer;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Helper;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Cache\ObjectCache;
use Weline\Framework\Manager\ObjectManager;

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
    public static function Env(string $key = null, $value = null): mixed
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
            $_SERVER['ORIGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
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
            if (isset($config['debug_key'])) {
                if ((!empty($_GET['debug']) && ($_GET['debug'] === $config['debug_key'])) || (Cookie::get('w_debug') === '1')) {
                    define('DEBUG', true);
                } else {
                    define('DEBUG', false);
                }
            } else {
                define('DEBUG', false);
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
        if (!CLI) {
            # 处理第一级语言代码
            $_SERVER['ORIGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
            self::detectStore($eventManager);
            $uri = $_SERVER['REQUEST_URI'];
            if ($uri and '/' !== $uri) {
                # 获取路由前缀，可能是货币码或者语言码
                $uri_arr = explode('/', ltrim($uri,'/'));
                if ($uri_arr) {
                    # 如果还有路由
                    $pre_path_1 = $uri_arr[0] ?? '';
                    $pre_path_2 = $uri_arr[1] ?? '';
                    $has_currency = false;
                    $has_language = false;
                    # 检查是否是货币
                    if (strlen($pre_path_1) === 3) {
                        $has_currency = self::detectCurrency($pre_path_1, $uri, $eventManager);
                    }
                    if (!$has_currency) {
                        if (strlen($pre_path_1) > 3 and ctype_lower(substr($pre_path_1, 0, 2)) and $pre_path_1[2] === '_') {
                            # 必须有前两个字符是否都是小写字母,且第三个字符必须是_
                            $has_language = self::detectLanguage($pre_path_1, $uri, $eventManager);
                        }
                    }
                    # 第一次未能探测到语言包，并且存在第二个路由时，必须有前两个字符是否都是小写字母,且第三个字符必须是_
                    if (!$has_language and $pre_path_2 and strlen($pre_path_2) > 3 and ctype_lower(substr($pre_path_2, 0, 2)) and $pre_path_2[2] === '_') {
                        # 如果查询得到属于语言包，则删除此路由
                        $has_language = self::detectLanguage($pre_path_2, $uri, $eventManager);
                    }
                    if (!$has_language and Cookie::get('WELINE-USER-LANG')) {
                        self::detectLanguage(Cookie::get('WELINE-USER-LANG'), $uri, $eventManager);
                    }
                    if (!$has_currency and Cookie::get('WELINE-USER-CURRENCY')) {
                        self::detectCurrency(Cookie::get('WELINE-USER-CURRENCY'), $uri, $eventManager);
                    }
                    $_SERVER['REQUEST_URI'] = $uri;
                }
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
        $eventManager->dispatch('App::run_after', ['data' => &$data]);
        $result = $data->getData('result');
        if (!CLI) {
            exit($result);
        }
        return $result;
    }

    /**
     * @param EventsManager $eventManager
     * @return void
     */
    public static function detectStore(EventsManager &$eventManager): void
    {
        # 如果查询得到店铺，则处理店铺URI
        $data = new DataObject([
            'store_url' => '',
            'store_id' => '',
        ]);
        $eventManager->dispatch('App::detect_store', ['data' => &$data]);
        if ($store_url = $data->getData('store_url') and $store_id = $data->getData('store_id')) {
            # 截取非店铺路径
            $_SERVER['REQUEST_URI'] = substr(($_SERVER['REQUEST_SCHEME']??'http')  . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], strlen($store_url));
            $_SERVER['WELINE-WEBSITE-ID'] = $store_id;
            $_SERVER['WELINE-WEBSITE-URL'] = $store_url;
        } else {
            $_SERVER['WELINE-WEBSITE-ID'] = 0;
            $_SERVER['WELINE-WEBSITE-URL'] = ($_SERVER['REQUEST_SCHEME']??'http') . '://' . $_SERVER['HTTP_HOST'];
        }
    }

    /**
     * @param array $uri_arr
     * @param EventsManager $eventManager
     * @return array
     */
    public static function detectCurrency(string $code, string &$uri, EventsManager &$eventManager): bool
    {
        if(!$code) return false;
        if(isset($_COOKIE['WELINE-USER-CURRENCY']) and $_COOKIE['WELINE-USER-CURRENCY'] == $code) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE-USER-CURRENCY', $code, 3600 * 24 * 30);
            $_SERVER['WELINE-USER-CURRENCY'] = $code;
            return true;
        }
        if($default_currency = Env::get('currency')){
            if (strtolower($code) === strtolower($default_currency)) {
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                Cookie::set('WELINE-USER-CURRENCY', $code, 3600 * 24 * 30);
                $_SERVER['WELINE-USER-CURRENCY'] = $code;
                return true;
            }
        }
        # 如果查询得到属于货币，则删除此路由
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        $eventManager->dispatch('App::detect_currency', ['data' => &$data]);
        if ($data->getData('result')) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE-USER-CURRENCY', $code, 3600 * 24 * 30);
            $_SERVER['WELINE-USER-CURRENCY'] = $code;
            return true;
        }
        return false;
    }

    public static function detectLanguage(string $code, string &$uri, EventsManager &$eventManager): bool
    {
        if(!$code) return false;
        if(isset($_COOKIE['WELINE-USER-LANG']) and $_COOKIE['WELINE-USER-LANG'] == $code) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE-USER-LANG', $code, 3600 * 24 * 30);
            $_SERVER['WELINE-USER-LANG'] = $code;
            return true;
        }
        if($default_lang = Env::get('lang')){
            if (strtolower($code) === strtolower($default_lang)) {
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                Cookie::set('WELINE-USER-LANG', $code, 3600 * 24 * 30);
                $_SERVER['WELINE-USER-LANG'] = $code;
                return true;
            }
        }
        # 如果查询得到属于货币，则删除此路由
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        $eventManager->dispatch('App::detect_language', ['data' => &$data]);
        if ($data->getData('result')) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            Cookie::set('WELINE-USER-LANG', $code, 3600 * 24 * 30);
            $_SERVER['WELINE-USER-LANG'] = $code;
            return true;
        }
        return false;
    }

    /**
     * @DESC         |安装
     *
     * 参数区：
     */
    public function install()
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
