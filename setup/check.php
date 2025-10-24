<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

ini_set('error_reporting', 1);

if (!function_exists('register_shutdown_function')) {
    function cache_shutdown_error()
    {
        $_error = error_get_last();
        if ($_error && in_array($_error['type'], [1, 4, 16, 64, 256, 4096, E_ALL], true)) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<b style="color: #ff0000">致命错误：</b></br>';
            echo '<pre>';
            echo $_error['message'];
            echo '</pre>';
            echo '<b style="color: red">提示：尝试到项目目录' . dirname(__DIR__) . DIRECTORY_SEPARATOR . '下执行 composer update 更新本地依赖包。</b></br></br>';
            echo '<b style="color: red">如果没有安装composer请执行php composer.phar update更新本地依赖包。</b></br></br>';
        }
    }
    register_shutdown_function('cache_shutdown_error');
}
// 安装检测
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'install.lock')) {
    http_response_code(404);
    exit(0);
}
