<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/10 22:12:09
 */

namespace Weline\Acl\Cache;

class AclCache extends \Weline\Framework\Cache\CacheFactory
{
    function __construct(
        string $identity = 'acl_cache', string $tip = '权限缓存', bool $permanently = true
    )
    { parent::__construct($identity, $tip, $permanently); }
}