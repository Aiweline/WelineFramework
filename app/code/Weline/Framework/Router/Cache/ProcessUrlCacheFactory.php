<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/XX
 */

namespace Weline\Framework\Router\Cache;

use Weline\Framework\Cache\CacheFactory;

/**
 * URL处理缓存工厂类
 * 
 * 用于缓存清理命令识别和清理
 */
class ProcessUrlCacheFactory extends CacheFactory
{
    public function __construct()
    {
        parent::__construct('process_url_cache', 'URL处理缓存', true);
    }
}
