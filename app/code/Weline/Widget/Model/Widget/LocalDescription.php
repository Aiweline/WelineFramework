<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Model\Widget;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 部件多语言翻译模型
 * 用于存储部件的名称和描述的多语言翻译
 */
class LocalDescription extends AbstractModel
{
    public const table = 'w_widget_local_description';
    
    // 部件唯一标识符（格式：module_type_name）
    public const fields_WIDGET_IDENTIFY = 'widget_identify';
    
    // 语言代码
    public const fields_LOCALE_CODE = 'locale_code';
    
    // 多语言字段
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    
    /**
     * 主键字段（复合主键）
     */
    public array $_unit_primary_keys = [self::fields_WIDGET_IDENTIFY, self::fields_LOCALE_CODE];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = [self::fields_WIDGET_IDENTIFY, self::fields_LOCALE_CODE];
    
    /**
     * 初始化
     */
    public function __init()
    {
        parent::__init();
        $this->_primary_key = self::fields_WIDGET_IDENTIFY;
    }
    
    /**
     * 安装表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }
    
    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable(__('部件多语言翻译表'))
            ->addColumn(
                self::fields_WIDGET_IDENTIFY,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                __('部件唯一标识符（格式：module_type_name）')
            )
            ->addColumn(
                self::fields_LOCALE_CODE,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                __('语言代码')
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                __('部件名称（翻译）')
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                __('部件描述（翻译）')
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'null',
                __('创建时间')
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'null',
                __('更新时间')
            )
            // 复合主键本身已经保证唯一性，这里只需要定义 PRIMARY KEY 即可，避免在部分数据库
            // （如 PostgreSQL）中对 "unique key" 语法的兼容性问题
            ->addConstraints('primary key (' . self::fields_WIDGET_IDENTIFY . ',' . self::fields_LOCALE_CODE . ')')
            ->create();
    }
    
    /**
     * 获取部件标识符
     * 
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @return string
     */
    public static function getWidgetIdentify(string $module, string $type, string $name): string
    {
        return $module . '_' . $type . '_' . $name;
    }
    
    /**
     * 获取部件的翻译名称
     * 
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string|null $locale 语言代码，默认使用当前语言
     * @param string|null $default 默认值
     * @return string
     */
    public static function getTranslatedName(
        string $module,
        string $type,
        string $name,
        ?string $locale = null,
        ?string $default = null
    ): string {
        $identify = self::getWidgetIdentify($module, $type, $name);
        
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        /** @var self $model */
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $model->reset()
            ->where(self::fields_WIDGET_IDENTIFY, $identify)
            ->where(self::fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();
        
        // 检查是否有翻译数据（通过检查 widget_identify 字段）
        if ($model->getData(self::fields_WIDGET_IDENTIFY)) {
            $translatedName = $model->getData(self::fields_NAME);
            if (!empty($translatedName)) {
                return $translatedName;
            }
        }
        
        return $default ?? '';
    }
    
    /**
     * 获取部件的翻译描述
     * 
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string|null $locale 语言代码，默认使用当前语言
     * @param string|null $default 默认值
     * @return string
     */
    public static function getTranslatedDescription(
        string $module,
        string $type,
        string $name,
        ?string $locale = null,
        ?string $default = null
    ): string {
        $identify = self::getWidgetIdentify($module, $type, $name);
        
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }
        
        /** @var self $model */
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $model->reset()
            ->where(self::fields_WIDGET_IDENTIFY, $identify)
            ->where(self::fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();
        
        // 检查是否有翻译数据（通过检查 widget_identify 字段）
        if ($model->getData(self::fields_WIDGET_IDENTIFY)) {
            $translatedDesc = $model->getData(self::fields_DESCRIPTION);
            if (!empty($translatedDesc)) {
                return $translatedDesc;
            }
        }
        
        return $default ?? '';
    }
}
