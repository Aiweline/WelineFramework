<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Campaign;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 促销活动模型
 * 
 * @package Weline_Marketing
 */
class Campaign extends Model
{
    // 框架自动推导表名：Campaign → weline_marketing_campaign
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'rule_id', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_RULE_ID = 'rule_id';
    public const fields_STATUS = 'status';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_BUDGET = 'budget';
    public const fields_SPENT = 'spent';
    public const fields_TARGET_AUDIENCE = 'target_audience';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->_table = 'weline_marketing_campaign';
        $this->_id_field_name = 'id';
    }

    /**
     * Install database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->useMainDbMaster();
        
        if ($setup->tableExist() === false) {
            $setup->createTable('促销活动表')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '活动ID'
            )
            ->addColumn(
                self::fields_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '活动名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '活动描述'
            )
            ->addColumn(
                self::fields_RULE_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '关联规则ID'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'draft\'',
                '状态：draft草稿, active激活, paused暂停, completed已完成, cancelled已取消'
            )
            ->addColumn(
                self::fields_START_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'not null',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'not null',
                '结束时间'
            )
            ->addColumn(
                self::fields_BUDGET,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,2',
                'null',
                '预算'
            )
            ->addColumn(
                self::fields_SPENT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,2',
                'default 0',
                '已花费'
            )
            ->addColumn(
                self::fields_TARGET_AUDIENCE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '目标受众（JSON）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp on update current_timestamp',
                '更新时间'
            )
            ->addIndex('INDEX', 'idx_status', [self::fields_STATUS, self::fields_START_DATE, self::fields_END_DATE])
            ->addIndex('INDEX', 'idx_rule', [self::fields_RULE_ID])
            ->create();
        }
    }

    /**
     * Setup database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * Upgrade database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // Future upgrades will be added here
    }

    /**
     * Get target audience as array
     *
     * @return array|null
     */
    public function getTargetAudience(): ?array
    {
        $audience = $this->getData(self::fields_TARGET_AUDIENCE);
        if (empty($audience)) {
            return null;
        }
        return json_decode($audience, true);
    }

    /**
     * Set target audience from array
     *
     * @param array|null $audience
     * @return $this
     */
    public function setTargetAudience(?array $audience): self
    {
        $this->setData(
            self::fields_TARGET_AUDIENCE,
            $audience ? json_encode($audience, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }
}
