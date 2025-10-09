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

 require __DIR__ . '/bootstrap.php';