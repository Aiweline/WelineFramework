<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 20:45:50
 */

namespace Weline\Eav\Cache;

class EavCache extends \Weline\Framework\Cache\CacheFactory
{
    function __construct(string $identity = 'eav_cache', string $tip = 'Eav实体属性值模型缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}