<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:42:04
 */

namespace Weline\I18n\Model\Locale;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Name extends \Weline\Framework\Database\Model
{
    public const table = "i18n_locale_name";
    public const fields_ID = 'locale_code';
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_DISPLAY_LOCALE_CODE = 'display_locale_code';
    public const fields_DISPLAY_NAME = 'display_name';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加联合唯一索引，用于支持 PostgreSQL 的 ON CONFLICT 语法
        if ($setup->tableExist()) {
            try {
                // 检查是否已存在唯一索引
                $indexName = 'uk_locale_display_locale';
                $table = $setup->getTable();
                
                // 尝试添加唯一索引
                $setup->query("CREATE UNIQUE INDEX IF NOT EXISTS \"{$indexName}\" ON \"{$table}\" (\"" . self::fields_LOCALE_CODE . "\", \"" . self::fields_DISPLAY_LOCALE_CODE . "\")");
            } catch (\Exception $e) {
                // PostgreSQL 的 CREATE UNIQUE INDEX IF NOT EXISTS 可能不支持，尝试其他方式
                try {
                    // 先检查索引是否存在
                    $checkSql = "SELECT 1 FROM pg_indexes WHERE indexname = 'uk_locale_display_locale' AND tablename = '{$setup->getTable()}'";
                    $result = $setup->query($checkSql);
                    if (empty($result)) {
                        // 索引不存在，创建它
                        $setup->query("CREATE UNIQUE INDEX \"uk_locale_display_locale\" ON \"{$setup->getTable()}\" (\"" . self::fields_LOCALE_CODE . "\", \"" . self::fields_DISPLAY_LOCALE_CODE . "\")");
                    }
                } catch (\Exception $e2) {
                    // 可能是 MySQL，尝试 MySQL 语法
                    try {
                        $setup->query("ALTER TABLE `{$setup->getTable()}` ADD UNIQUE INDEX `uk_locale_display_locale` (`" . self::fields_LOCALE_CODE . "`, `" . self::fields_DISPLAY_LOCALE_CODE . "`)");
                    } catch (\Exception $e3) {
                        // 索引可能已存在，忽略错误
                    }
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
    //    $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 12, 'not null', '地区码')
                ->addColumn(self::fields_DISPLAY_LOCALE_CODE, TableInterface::column_type_VARCHAR, 12, 'not null', '展示地区码')
                ->addColumn(self::fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '地区名')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_locale_code', self::fields_LOCALE_CODE, '区码索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_display_locale_code', self::fields_DISPLAY_LOCALE_CODE, '展示区码索引')
                // 添加联合唯一索引，用于支持 PostgreSQL 的 ON CONFLICT 语法
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_UNIQUE, 'uk_locale_display_locale', self::fields_LOCALE_CODE . ',' . self::fields_DISPLAY_LOCALE_CODE, '区域语言唯一索引')
                ->create();
        }
    }
}
