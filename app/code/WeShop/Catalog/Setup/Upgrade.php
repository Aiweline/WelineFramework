<?php

declare(strict_types=1);

namespace WeShop\Catalog\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * 模块升级脚本
 */
class Upgrade implements \Weline\Framework\Setup\UpgradeInterface
{
    /**
     * 升级模块
     * 
     * @param Setup $setup
     * @param Context $context
     * @return string
     */
    public function setup(Setup $setup, Context $context): string
    {
        // 确保 EAV 实体和属性已创建（包括 icon 和 show_icon）
        /** @var UpgradeData $upgradeData */
        $upgradeData = ObjectManager::getInstance(UpgradeData::class);
        try {
            $upgradeData->install();
            return __('分类 EAV 实体和属性已更新');
        } catch (\Exception $e) {
            return __('更新分类 EAV 实体和属性失败: %{1}', [$e->getMessage()]);
        }
    }
}
