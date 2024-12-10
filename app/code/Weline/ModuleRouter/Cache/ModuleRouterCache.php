<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Cache;

class ModuleRouterCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'routers_rules_cache', string $tip = '路由规则缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
