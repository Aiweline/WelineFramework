<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Cache;

class ThemeCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'weline_theme')
    {
        parent::__construct($identity);
    }
}
