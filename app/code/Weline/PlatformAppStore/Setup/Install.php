<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时执行：添加模块分类种子数据
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->seedCategories();
    }

    /**
     * 添加默认模块分类
     */
    private function seedCategories(): void
    {
        // 分类数据将在模型创建后添加
        // 这里预留扩展点
    }
}
