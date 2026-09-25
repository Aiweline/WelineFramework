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
 // 必须与 DEV 成对固定：否则 App::init() 会按 env.php 的 system.deploy 求值 PROD，
 // 当本机 system.deploy=prod 时会出现 DEV=true 且 PROD=true 的自相矛盾，
 // 使测试同时命中 dev 分支与 prod 分支（如 TraitTemplate 把 view/statics 解析到真实
 // pub/static、QueryProviderRegistry 强制编译注册表）。各模块 Test/Unit/bootstrap.php
 // 均已按此成对固定，此处对齐同一约定。
 if (!defined('PROD')) {
     define('PROD', false);
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
