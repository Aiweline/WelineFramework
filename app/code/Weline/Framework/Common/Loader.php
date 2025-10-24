<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Common;

use Weline\Framework\App\Cache\AppCache;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;

class Loader
{
    public static function load()
    {
        require __DIR__ . '/functions.php';
        require __DIR__ . '/func_debug.php';
        # 加载模块自定义助手函数
        if (is_file(Env::path_FUNCTIONS_FILE)) {
            require Env::path_FUNCTIONS_FILE;
        }
    }
}
