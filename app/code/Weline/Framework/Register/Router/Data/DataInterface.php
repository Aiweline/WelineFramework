<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Register\Router\Data;

interface DataInterface
{
    public const register_file = 'register.php';

    public const register_param = [
        'type'           => null,
        'area'           => null,
        'module'         => null,
        'router'         => null,
        'class'          => null,
        'method'         => null,
        'request_method' => null,
    ];

    public const type_API = 'api';

    public const type_PC = 'pc';
}
