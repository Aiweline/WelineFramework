<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Cache;

/**
 * 文件信息
 * DESC:   |
 * 作者：   秋枫雁飞
 * 日期：   2021/1/24
 * 时间：   15:16
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 * @DESC    :    此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @since   1.2
 *
 * Class EventCache
 * @package Weline\Framework\Event\Cache
 * @since   100
 */
class EventCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'framework_event')
    {
        parent::__construct($identity, '事件缓存', true);
    }
}
