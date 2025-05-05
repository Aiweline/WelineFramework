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
            $parse = Url::parser();
            # url 重写 兼容原本携带的参数和当前重写原参数
            if (is_array($parse)) {
                if ($_SERVER['REQUEST_METHOD'] && isset($parse['uri'])) {
                    $uri = Url::decode_url($parse['uri']);
                    $parse['server']['REQUEST_URI'] = $uri;
                    $parse['server']['QUERY_STRING'] = Url::parse_url($uri, 'query');
                }
                $_SERVER = $parse['server'];
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
                foreach ($default_cookies as $key) {
                    if (!isset($_SERVER[$key])) {
                        throw new Exception(__('系统SERVER缺少key：%1', $key));
                    }
                    Cookie::set($key, $_SERVER[$key], 3600 * 24 * 30, [
//                    'path' => $cookie_path,
                    ]);
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
        $eventManager->dispatch('App::run_after', $data);
        $result = $data->getData('result');
        if (!CLI) {
            echo($result);
            exit(0);
        }
        return $result;
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
