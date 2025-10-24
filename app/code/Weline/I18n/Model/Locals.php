<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Locals extends \Weline\Framework\Database\Model
{
    public const table = "i18n_locals";
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
    public const fields_TARGET_CODE = 'target_code';
    public const fields_NAME = 'name';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_INSTALL = 'is_install';
    public const fields_FLAG = 'flag';

    public array $_unit_primary_keys = ['code', 'target_code'];

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
    //    $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 10, 'not null', '地方代码')
                ->addColumn(self::fields_TARGET_CODE, TableInterface::column_type_VARCHAR, 10, 'not null', '展示的地方代码')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 128, 'not null', '展示的地方代码对应地方代码名称')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 20000, '', 'svg国旗')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_CODE, '区码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_target_code', self::fields_TARGET_CODE, '目标区码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_NAME, '名字索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装索引')
                ->create();
        }
        
        // 安装时一次性安装全球所有语言包
        $this->installAllGlobalLocales();
    }
    
    /**
     * 安装全球所有语言包
     */
    private function installAllGlobalLocales(): void
    {
        try {
            // 获取所有可用的语言代码
            $allLocales = \Symfony\Component\Intl\Locales::getLocales();
            $insertData = [];
            
            foreach ($allLocales as $locale) {
                // 获取语言名称（使用英语作为显示语言）
                $localeName = \Symfony\Component\Intl\Locales::getName($locale, 'en');
                
                // 获取对应的国家代码
                $countryCode = substr($locale, -2);
                
                // 获取国旗SVG
                $flagSvg = '';
                try {
                    $country = country($countryCode);
                    if ($country) {
                        $flagSvg = $country->getFlag();
                    }
                } catch (\Exception $e) {
                    // 如果获取国旗失败，使用默认值
                    $flagSvg = '';
                }
                
                $insertData[] = [
                    self::fields_CODE => $locale,
                    self::fields_TARGET_CODE => $locale,
                    self::fields_NAME => $localeName,
                    self::fields_IS_ACTIVE => 0, // 默认未激活
                    self::fields_IS_INSTALL => 1, // 默认已安装
                    self::fields_FLAG => $flagSvg
                ];
            }
            
            // 批量插入数据
            if (!empty($insertData)) {
                $this->clearQuery();
                $this->insert($insertData, [self::fields_CODE, self::fields_TARGET_CODE])->fetch();
            }
            
        } catch (\Exception $e) {
            // 记录错误但不中断安装过程
            error_log('I18n global locales installation failed: ' . $e->getMessage());
        }
    }
}
