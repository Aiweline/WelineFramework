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

class LeadCandidate extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_lead_candidate';
    
    public const fields_ID = 'candidate_id';
    public const fields_STORE_ID = 'store_id';
    public const fields_PROFILE_DATA = 'profile_data';
    public const fields_SCORE = 'score';
    public const fields_SOURCE_URL = 'source_url';
    public const fields_SOURCE_URLS = 'source_urls'; // 记录所有搜索过的网址（JSON格式）
    public const fields_STATUS = 'status';
    public const fields_EMAIL = 'email';
    public const fields_PHONE = 'phone';
    public const fields_SOCIAL_MEDIA_ACCOUNTS = 'social_media_accounts';
    public const fields_MATCHED_TEXT_SEGMENTS = 'matched_text_segments';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['candidate_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['candidate_id', 'store_id', 'score', 'status'];

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
            $setup->createTable(__('潜在客户表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('候选客户ID')
                )
                ->addColumn(
                    self::fields_STORE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    __('店铺ID')
                )
                ->addColumn(
                    self::fields_PROFILE_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'not null',
                    __('客户画像数据（JSON格式）')
                )
                ->addColumn(
                    self::fields_SCORE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'not null default 0.00',
                    __('匹配分数')
                )
                ->addColumn(
                    self::fields_SOURCE_URL,
                    TableInterface::column_type_VARCHAR,
                    512,
                    'not null',
                    __('来源URL')
                )
                ->addColumn(
                    self::fields_SOURCE_URLS,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('所有搜索过的网址（JSON格式）')
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null default \'pending\'',
                    __('状态（pending/verified/rejected）')
                )
                ->addColumn(
                    self::fields_EMAIL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    __('邮箱地址')
                )
                ->addColumn(
                    self::fields_PHONE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    __('手机号码')
                )
                ->addColumn(
                    self::fields_SOCIAL_MEDIA_ACCOUNTS,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('社媒账户（JSON格式）')
                )
                ->addColumn(
                    self::fields_MATCHED_TEXT_SEGMENTS,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('匹配的文本段（JSON格式）')
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
                    'idx_store_id',
                    self::fields_STORE_ID,
                    __('店铺ID索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_score',
                    self::fields_SCORE,
                    __('分数索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    __('状态索引')
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
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }
        
        // 添加邮箱字段
        if (!$setup->columnExist(self::fields_EMAIL)) {
            $setup->addColumn(
                self::fields_EMAIL,
                TableInterface::column_type_VARCHAR,
                255,
                'null',
                __('邮箱地址')
            )->updateTable();
        }
        
        // 添加手机号字段
        if (!$setup->columnExist(self::fields_PHONE)) {
            $setup->addColumn(
                self::fields_PHONE,
                TableInterface::column_type_VARCHAR,
                50,
                'null',
                __('手机号码')
            )->updateTable();
        }
        
        // 添加社媒账户字段
        if (!$setup->columnExist(self::fields_SOCIAL_MEDIA_ACCOUNTS)) {
            $setup->addColumn(
                self::fields_SOCIAL_MEDIA_ACCOUNTS,
                TableInterface::column_type_TEXT,
                null,
                'null',
                __('社媒账户（JSON格式）')
            )->updateTable();
        }
        
        // 添加匹配文本段字段
        if (!$setup->columnExist(self::fields_MATCHED_TEXT_SEGMENTS)) {
            $setup->addColumn(
                self::fields_MATCHED_TEXT_SEGMENTS,
                TableInterface::column_type_TEXT,
                null,
                'null',
                __('匹配的文本段（JSON格式）')
            )->updateTable();
        }
        
        // 添加来源网址列表字段
        if (!$setup->columnExist(self::fields_SOURCE_URLS)) {
            $setup->addColumn(
                self::fields_SOURCE_URLS,
                TableInterface::column_type_TEXT,
                null,
                'null',
                __('所有搜索过的网址（JSON格式）')
            )->updateTable();
        }
    }
}

