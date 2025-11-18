<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Api\Data;

interface InterceptorInterface
{
    public const LISTENER_BEFORE = 'before';

    public const LISTENER_AROUND = 'around';

    public const LISTENER_AFTER = 'after';
}
