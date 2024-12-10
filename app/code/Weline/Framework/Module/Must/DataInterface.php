<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Must;

interface DataInterface
{
    public const file_Register = 'register.php';

    public const file_etc_Env = 'etc' . DS . 'env.php';

    public const dir_Etc = 'etc';

    public const files = [
        self::file_Register,
        self::file_etc_Env,
    ];

    public const dirs = [
        self::dir_Etc,
    ];
}
