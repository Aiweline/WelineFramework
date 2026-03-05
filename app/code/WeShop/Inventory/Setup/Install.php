<?php

declare(strict_types=1);

namespace WeShop\Inventory\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use WeShop\Inventory\Model\Source;

class Install implements InstallInterface
{
    /**
     * 安装模块：创建默认库存源（幂等）
     */
    public function setup(Setup $setup, Context $context): void
    {
        try {
            /** @var Source $source */
            $source = ObjectManager::getInstance(Source::class);
            $existing = $source->reset()->where(Source::schema_fields_CODE, 'default')->find()->fetch();
            if (!$existing->getId()) {
                $source->setData([
                    Source::schema_fields_CODE => 'default',
                    Source::schema_fields_NAME => '默认仓库',
                    Source::schema_fields_DESCRIPTION => '系统默认库存源',
                    Source::schema_fields_IS_ENABLED => 1,
                    Source::schema_fields_PRIORITY => 0,
                ])->save();
            }
        } catch (\Throwable $e) {
            w_log_error('库存默认源安装失败: ' . $e->getMessage(), [], 'inventory');
        }
    }
}
