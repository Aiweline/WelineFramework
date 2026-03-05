<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        // 表结构由 TrendProfile 的 #[Table]/#[Col] 声明，setup:upgrade 时 SchemaDiff 自动同步
    }
}
