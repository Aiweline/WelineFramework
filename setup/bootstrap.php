<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\App\Exception;

require 'check.php';

//// 项目根目录
if (!defined('BP')) {
    define("BP", dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
// 第三方代码目录
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', BP . 'vendor' . DIRECTORY_SEPARATOR);
}

// 检测Composer自动加载代理
try {
    $autoloader = VENDOR_PATH . 'autoload.php';
    if (is_file($autoloader)) {
        require $autoloader;
    } else {
        exit('Composer自动加载异常!尝试执行：php composer.phar install');
    }
} catch (Exception $exception) {
    exit('自动加载异常：' . $exception->getMessage());
}
Weline\Framework\App::init();

/**
 * php bin/m system:install ^
 * --db-type=mysql ^
 * --db-hostname=127.0.0.1 ^
 * --db-database=weline ^
 * --db-username=weline ^
 * --db-password=weline
 */
