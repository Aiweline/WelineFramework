<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

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
if(!defined('ENV_TEST')){
    define('ENV_TEST', true);
}
class TestCore extends TestCase
{
    use Boot;

    public static function getInstance(string $class)
    {
        return ObjectManager::getInstance($class);
    }
}
