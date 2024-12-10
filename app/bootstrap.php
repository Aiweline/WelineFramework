<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\App\Exception;

if (!defined('BP')) {
    define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
// 检查安装
if ((PHP_SAPI !== 'cli') and !file_exists(BP . 'setup' . DIRECTORY_SEPARATOR . 'install.lock')) {
    require BP . 'setup' . DIRECTORY_SEPARATOR . 'index.php';
    exit();
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
// 加载通用函数

// 尝试加载应用
try {
    /**
     * 初始化应用...
     */
    \Weline\Framework\App::run();
} catch (Exception $exception) {
    if (DEV) {
        echo '<pre>';
        echo '应用启动失败：<b style="color: red">' . $exception->getMessage() . '</b>' . PHP_EOL;
        echo '错误信息：' . PHP_EOL . $exception->getTraceAsString() . PHP_EOL;
        //        var_dump($exception->getTrace());
        if (DEBUG) {
            echo 'DEBUG信息：' . PHP_EOL . $exception->getTraceAsString() . PHP_EOL;
            echo '<pre>';
            var_dump(debug_backtrace());
            echo '</pre>';
            echo 'DEBUG 堆栈追踪信息：' . PHP_EOL;
            echo '<pre>';
            var_dump($exception->getTrace());
        }
    } else {
        echo '<pre>';
        echo '<b style="color: red">系统异常，请联系网站管理员进行修复！</b>';
        exit(0);
    }
}
