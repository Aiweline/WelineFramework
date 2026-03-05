<?php

declare(strict_types=1);

namespace WeShop\Search\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装模块：注册搜索引擎驱动并安装默认配置
     */
    public function setup(Setup $setup, Context $context): void
    {
        try {
            $installData = ObjectManager::getInstance(InstallData::class);
            $installData->install();
        } catch (\Throwable $e) {
            w_log_error('搜索模块安装数据失败: ' . $e->getMessage(), [], 'search');
        }
    }
}
