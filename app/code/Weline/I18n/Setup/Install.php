<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Setup;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Service\Catalog\DisplayNameCartesianSeeder;

class Install implements InstallInterface
{
    /**
     * 安装模块：地区语言名表 DDL + 从仓内种子包灌国家/locale 库存与基线展示名。
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->installLocaleNameTable($setup);
        try {
            /** @var DisplayNameCartesianSeeder $seeder */
            $seeder = ObjectManager::getInstance(DisplayNameCartesianSeeder::class);
            $seeder->seedModuleInstallInventory(true);
            w_log_info('I18n: locale catalog seeded from data/locale-catalog pack', [], 'i18n');
        } catch (\Throwable $e) {
            w_log_error('I18n catalog seed failed: ' . $e->getMessage(), [], 'i18n');
            w_log_error('I18n installation trace: ' . $e->getTraceAsString(), [], 'i18n');
        }
    }

    /**
     * 创建 i18n_locale_name 表（原 Model\Locale\Name install DDL）
     */
    private function installLocaleNameTable(Setup $setup): void
    {
        /** @var ModelSetup $modelSetup */
        $modelSetup = ObjectManager::getInstance(ModelSetup::class, ['printing' => $setup->getPrinter()]);
        $model = ObjectManager::getInstance(LocaleName::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
        $modelSetup->createTable('地区语言名表')
            ->addColumn(LocaleName::schema_fields_ID, TableInterface::column_type_VARCHAR, 12, 'not null', '地区码')
            ->addColumn(LocaleName::schema_fields_DISPLAY_LOCALE_CODE, TableInterface::column_type_VARCHAR, 12, 'not null', '展示地区码')
            ->addColumn(LocaleName::schema_fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '地区名')
            ->addIndex(TableInterface::index_type_KEY, 'idx_locale_code', LocaleName::schema_fields_LOCALE_CODE, '区码索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_display_locale_code', LocaleName::schema_fields_DISPLAY_LOCALE_CODE, '展示区码索引')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_locale_display_locale', LocaleName::schema_fields_LOCALE_CODE . ',' . LocaleName::schema_fields_DISPLAY_LOCALE_CODE, '区域语言唯一索引')
            ->create();
    }
}
