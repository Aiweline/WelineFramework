<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Data;

interface DataInterface
{
    public const dir = 'Setup';

    public const type_INSTALL = 'Install';

    public const type_UPGRADE = 'Upgrade';

    public const type_REMOVE = 'Remove';

    public const upgrade_FILES = [
        self::type_UPGRADE,
    ];

    public const install_FILES = [
        self::type_INSTALL,
    ];

    public const remove_FILES = [
        self::type_REMOVE,
    ];
}
