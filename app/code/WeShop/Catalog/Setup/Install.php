<?php

declare(strict_types=1);

namespace WeShop\Catalog\Setup;

use WeShop\Catalog\Model\Category;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * 模块安装脚本
 */
class Install implements \Weline\Framework\Setup\InstallInterface
{
    /**
     * 安装模块
     * 
     * @param Setup $setup
     * @param Context $context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 1. 注册 EAV 实体和属性
        /** @var UpgradeData $upgradeData */
        $upgradeData = ObjectManager::getInstance(\WeShop\Catalog\Setup\UpgradeData::class);
        try {
            $upgradeData->install();
        } catch (\Exception $e) {
            throw new \Exception(__('安装分类 EAV 实体和属性失败: %{1}', [$e->getMessage()]), 0, $e);
        }

        // 2. 安装默认分类数据
        /** @var InstallData $installData */
        $installData = ObjectManager::getInstance(InstallData::class);
        try {
            $installData->install();
            
            // 验证数据是否插入成功
            $verifyCategory = ObjectManager::getInstance(Category::class);
            $count = $verifyCategory->clear()
                ->select()
                ->count();
            
            if ($count === 0) {
                throw new \Exception(__('安装后验证失败：数据库中没有找到任何分类数据'));
            }
        } catch (\Exception $e) {
            throw new \Exception(__('安装默认分类数据失败: %{1}', [$e->getMessage()]), 0, $e);
        }
    }
}
