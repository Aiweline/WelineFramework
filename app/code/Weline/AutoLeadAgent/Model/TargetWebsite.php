<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 搜索目标网站模型
 * 
 * 存储目标网站配置，包括搜索语法模板
 */
class TargetWebsite extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_target_website';
    
    public const fields_ID = 'target_website_id';
    public const fields_NAME = 'name';
    public const fields_DOMAIN = 'domain';
    public const fields_SEARCH_SYNTAX_TEMPLATE = 'search_syntax_template';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_DESCRIPTION = 'description';
    public const fields_ICON_URL = 'icon_url';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['target_website_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['target_website_id', 'is_active', 'sort_order'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('搜索目标网站表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('目标网站ID')
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    __('网站名称')
                )
                ->addColumn(
                    self::fields_DOMAIN,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    __('域名')
                )
                ->addColumn(
                    self::fields_SEARCH_SYNTAX_TEMPLATE,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('搜索语法模板（支持占位符：{domain}, {keyword1}, {keyword2}, {keyword3}, {industry}, {region}）')
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    __('是否启用')
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    __('排序')
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('描述')
                )
                ->addColumn(
                    self::fields_ICON_URL,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'null',
                    __('图标URL')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp on update current_timestamp',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_active',
                    self::fields_IS_ACTIVE,
                    __('是否启用索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_sort_order',
                    self::fields_SORT_ORDER,
                    __('排序索引')
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_domain',
                    self::fields_DOMAIN,
                    __('域名唯一索引')
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
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
        // 未来版本升级逻辑
    }

    /**
     * 获取启用的目标网站列表
     * 
     * @return array
     */
    public function getActiveWebsites(): array
    {
        return $this->clear()
            ->where(self::fields_IS_ACTIVE, 1)
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->order(self::fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 根据域名获取目标网站
     * 
     * @param string $domain
     * @return self|null
     */
    public function getByDomain(string $domain): ?self
    {
        return $this->clear()
            ->where(self::fields_DOMAIN, $domain)
            ->find()
            ->fetch();
    }
}

