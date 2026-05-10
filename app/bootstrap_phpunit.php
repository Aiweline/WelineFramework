<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

 // 单元测试参数 - 只在常量未定义时定义
 if (!defined('BP')) {
     define('BP', realpath(dirname(__DIR__)).DIRECTORY_SEPARATOR);
 }
 if (!defined('SANDBOX')) {
     define('SANDBOX', true);
 }
 if (!defined('DEBUG')) {
     define('DEBUG', true);
 }
 if (!defined('DEV')) {
     define('DEV', true);
 }
 // PHPUnit / CLI 测试请求：与 Observer 中不重抛 layout 异常等逻辑对齐（勿与非测试入口混淆）
 if (!defined('ENV_TEST')) {
     define('ENV_TEST', true);
 }
 // Keep PHPUnit translations stable regardless of local default language.
 $_SERVER['WELINE_USER_LANG'] = $_SERVER['WELINE_USER_LANG'] ?? 'en_US';
 $_COOKIE['WELINE_USER_LANG'] = $_COOKIE['WELINE_USER_LANG'] ?? 'en_US';
 $_COOKIE['WELINE-WEBSITE-LANG'] = $_COOKIE['WELINE-WEBSITE-LANG'] ?? 'en_US';

 // 临时抑制 PHP 8.1+ 的弃用警告（Pest 1.x 兼容性问题）
 // 这些警告来自 Pest 1.x 和 Collision 库，不影响功能
 $originalErrorReporting = error_reporting();
 error_reporting($originalErrorReporting & ~E_DEPRECATED);

 require __DIR__ . '/bootstrap.php';
 $_SERVER['WELINE_USER_LANG'] = 'en_US';
 $_COOKIE['WELINE_USER_LANG'] = 'en_US';
 $_COOKIE['WELINE-WEBSITE-LANG'] = 'en_US';

 // 恢复原始错误报告级别
 error_reporting($originalErrorReporting);
