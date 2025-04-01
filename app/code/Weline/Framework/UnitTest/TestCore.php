<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Plugin\Route;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\CoreUnit;
use Weline\Framework\Session\Session;

# 兼容环境
if (!defined('BP')) {
    // vendor下加载测试用例时设置项目目录BP常量
    $composer_file = realpath(dirname(__DIR__, 4)) . '/' . 'composer.json';
    if (file_exists($composer_file)) {
        define('BP', dirname($composer_file) . DIRECTORY_SEPARATOR);
    } else {
        // app目录下加载测试用例时设置项目目录BP常量
        $composer_file = realpath(dirname(__DIR__, 5)) . '/' . 'composer.json';
        if (file_exists($composer_file)) {
            define('BP', dirname($composer_file) . DIRECTORY_SEPARATOR);
        }
    }
    if (!defined('BP')) {
        throw new \Exception('请先安装 composer');
    }
}
require BP . 'index.php';
if (!defined('ENV_TEST')) {
    define('ENV_TEST', true);
}
$_SERVER['REQUEST_URI'] = '/test';
// 初始化session
ObjectManager::getInstance(Session::class);

class TestCore extends TestCase
{
    use Boot;

    public static function getInstance(string $class)
    {
        return ObjectManager::getInstance($class);
    }

    public static function initRequest(string $path = '/dev/tool/index')
    {
        # 初始化一个请求
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = '';
//        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/2';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9';
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-CN,zh;q=0.9,en;q=0.8';
        $_SERVER['HTTP_REFERER'] = 'http://localhost/dev/tool/index';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SCRIPT_FILENAME'] = BP . 'index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'localhost';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_SSL'] = 'off';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.16.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REDIRECT_STATUS'] = '200';
        $_SERVER['REDIRECT_QUERY_STRING'] = '';
        $_SERVER['REDIRECT_URL'] = '/dev/tool/index';
        $_SERVER['REDIRECT_URI'] = '/dev/tool/index';
        $_SERVER['REDIRECT_METHOD'] = 'GET';
        $_SERVER['REDIRECT_HTTPS'] = 'off';
        $_SERVER['REDIRECT_SCHEME'] = 'http';
        $_SERVER['REDIRECT_SCRIPT_FILENAME'] = BP . 'index.php';
        $_SERVER['REDIRECT_SCRIPT_NAME'] = '/index.php';
        $_SERVER['REDIRECT_PHP_SELF'] = '/index.php';
        $_SERVER['REDIRECT_PATH_INFO'] = '/dev/tool/index';
        $_SERVER['REDIRECT_PATH_TRANSLATED'] = BP . 'index.php';
        $_SERVER['REDIRECT_QUERY_STRING'] = '';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/dev/tool/index';
        $_SERVER['PATH_TRANSLATED'] = BP . 'index.php';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['DOCUMENT_ROOT'] = BP;
        $_SERVER['SCRIPT_FILENAME'] = BP . 'index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        # 初始化请求
        /** @var CoreUnit $route */
        $route = ObjectManager::getInstance(CoreUnit::class);
        # 初始化路由
        try {
            $route->start();
        } catch (\ReflectionException|Exception $e) {
            echo $e->getMessage();
        }
    }
}
