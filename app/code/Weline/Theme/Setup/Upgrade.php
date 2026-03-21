<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Setup;

use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Upgrade implements InstallInterface
{
    public const VERSION = '1.0.2';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        // preview_image、config、is_active_frontend、is_active_backend 等字段
        // 已在 WelineTheme #[Col] 声明中定义，由 SchemaDiffStage 自动 ALTER 补全，
        // 无需裸 SQL 手动添加。
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }
}
