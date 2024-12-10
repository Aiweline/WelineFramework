<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/23 21:10:50
 */

namespace Weline\Framework\Http\Cache;

class RequestCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'request_cache', string $tip = '请求缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
