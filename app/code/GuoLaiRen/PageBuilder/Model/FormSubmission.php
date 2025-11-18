<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 表单提交记录模型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class FormSubmission extends Model
{
    public const table = 'guolairen_page_builder_form_submission';
    
    // 字段定义
    public const fields_ID = 'submission_id';
    public const fields_PAGE_ID = 'page_id';
    public const fields_EMAIL = 'email';
    public const fields_PHONE = 'phone';
    public const fields_EXTRA_FIELDS = 'extra_fields';
    public const fields_IP_ADDRESS = 'ip_address';
    public const fields_USER_AGENT = 'user_agent';
    public const fields_REFERER = 'referer';
    public const fields_STATUS = 'status';
    public const fields_SUBMITTED_AT = 'submitted_at';
    public const fields_CREATE_TIME = 'create_time';
    
    // 状态常量
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_SPAM = 'spam';
    
    /**
     * 获取额外字段
     */
    public function getExtraFields(): array
    {
        $extra = $this->getData(self::fields_EXTRA_FIELDS);
        return $extra ? json_decode($extra ?? '', true) : [];
    }
    
    /**
     * 设置额外字段
     */
    public function setExtraFields(array $fields): self
    {
        $this->setData(self::fields_EXTRA_FIELDS, json_encode($fields));
        return $this;
    }
    
    /**
     * 获取所有唯一的额外字段键
     */
    public static function getUniqueExtraFieldKeys(): array
    {
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $submissions = $model->select()->fetch()->getItems();
        
        $keys = [];
        foreach ($submissions as $submission) {
            $extraFields = $submission->getExtraFields();
            foreach (array_keys($extraFields) as $key) {
                if (!in_array($key, $keys)) {
                    $keys[] = $key;
                }
            }
        }
        
        return $keys;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 删除旧表（如果存在）- 仅在重建表结构时临时启用
        // $setup->dropTable();
        
        // 检查表是否已存在
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('页面构建器-表单提交记录表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '提交记录ID'
            )
            ->addColumn(
                self::fields_PAGE_ID,
                TableInterface::column_type_INTEGER,
                0,
                '',
                '关联页面ID'
            )
            ->addColumn(
                self::fields_EMAIL,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '邮箱'
            )
            ->addColumn(
                self::fields_PHONE,
                TableInterface::column_type_VARCHAR,
                50,
                '',
                '电话'
            )
            ->addColumn(
                self::fields_EXTRA_FIELDS,
                TableInterface::column_type_TEXT,
                0,
                '',
                '额外字段(JSON)'
            )
            ->addColumn(
                self::fields_IP_ADDRESS,
                TableInterface::column_type_VARCHAR,
                45,
                '',
                'IP地址'
            )
            ->addColumn(
                self::fields_USER_AGENT,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '用户代理'
            )
            ->addColumn(
                self::fields_REFERER,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '来源页面'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "not null default 'new'",
                '状态'
            )
            ->addColumn(
                self::fields_SUBMITTED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '提交时间'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_page_id', [self::fields_PAGE_ID], '页面ID索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_email', [self::fields_EMAIL], '邮箱索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_phone', [self::fields_PHONE], '电话索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', [self::fields_STATUS], '状态索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_submitted_at', [self::fields_SUBMITTED_AT], '提交时间索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加status字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_STATUS)) {
            // 新增字段（插入到 SUBMITTED_AT 之后，可按需调整顺序）
            $setup->alterTable()->addColumn(
                self::fields_STATUS,
                self::fields_SUBMITTED_AT,
                TableInterface::column_type_VARCHAR,
                20,
                "not null default 'new'",
                '状态'
            )->alter();

            // 添加索引
            $setup->alterTable()->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                [self::fields_STATUS]
            )->alter();
        }
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

