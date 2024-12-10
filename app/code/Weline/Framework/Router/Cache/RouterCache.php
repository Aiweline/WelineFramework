<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Cache;

class RouterCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'framework_router')
    {
        parent::__construct($identity, '路由缓存', true);
    }
}
