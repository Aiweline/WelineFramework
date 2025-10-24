<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Config\Cache;

class ConfigCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'config')
    {
        parent::__construct($identity, '配置缓存');
    }
}
