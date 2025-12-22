<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * 营销规则模型
 * 
 * @package Weline_Marketing
 */
class Rule extends Model
{
    // 框架自动推导表名：Rule → weline_marketing_rule
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'priority', 'status', 'rule_type'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_RULE_TYPE = 'rule_type';
    public const fields_STATUS = 'status';
    public const fields_PRIORITY = 'priority';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_CONDITIONS_SERIALIZED = 'conditions_serialized';
    public const fields_ACTIONS_SERIALIZED = 'actions_serialized';
    public const fields_USAGE_LIMIT = 'usage_limit';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_CUSTOMER_LIMIT = 'customer_limit';
    public const fields_IS_STOP_PROCESSING = 'is_stop_processing';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Rule type constants
     */
    public const RULE_TYPE_COUPON = 'coupon';
    public const RULE_TYPE_CAMPAIGN = 'campaign';
    public const RULE_TYPE_AUTOMATIC = 'automatic';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->_table = 'weline_marketing_rule';
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
            $setup->createTable('营销规则表')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '规则ID'
            )
            ->addColumn(
                self::fields_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '规则名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '规则描述'
            )
            ->addColumn(
                self::fields_RULE_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '规则类型：coupon优惠券, campaign活动, automatic自动'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'inactive\'',
                '状态：active激活, inactive未激活, expired已过期'
            )
            ->addColumn(
                self::fields_PRIORITY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '优先级（数字越大优先级越高）'
            )
            ->addColumn(
                self::fields_START_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'null',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME,
                0,
                'null',
                '结束时间'
            )
            ->addColumn(
                self::fields_CONDITIONS_SERIALIZED,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '条件序列化（JSON）'
            )
            ->addColumn(
                self::fields_ACTIONS_SERIALIZED,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '动作序列化（JSON）'
            )
            ->addColumn(
                self::fields_USAGE_LIMIT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '总使用次数限制'
            )
            ->addColumn(
                self::fields_USAGE_COUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '已使用次数'
            )
            ->addColumn(
                self::fields_CUSTOMER_LIMIT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '每个客户使用次数限制'
            )
            ->addColumn(
                self::fields_IS_STOP_PROCESSING,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_SMALLINT,
                0,
                'default 0',
                '是否停止后续规则处理'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '排序'
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
            ->addIndex('INDEX', 'idx_type', [self::fields_RULE_TYPE, self::fields_STATUS])
            ->addIndex('INDEX', 'idx_priority', [self::fields_PRIORITY])
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
     * Get conditions as array
     *
     * @return array|null
     */
    public function getConditions(): ?array
    {
        $conditions = $this->getData(self::fields_CONDITIONS_SERIALIZED);
        if (empty($conditions)) {
            return null;
        }
        return json_decode($conditions, true);
    }

    /**
     * Set conditions from array
     *
     * @param array|null $conditions
     * @return $this
     */
    public function setConditions(?array $conditions): self
    {
        $this->setData(
            self::fields_CONDITIONS_SERIALIZED,
            $conditions ? json_encode($conditions, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }

    /**
     * Get actions as array
     *
     * @return array|null
     */
    public function getActions(): ?array
    {
        $actions = $this->getData(self::fields_ACTIONS_SERIALIZED);
        if (empty($actions)) {
            return null;
        }
        return json_decode($actions, true);
    }

    /**
     * Set actions from array
     *
     * @param array|null $actions
     * @return $this
     */
    public function setActions(?array $actions): self
    {
        $this->setData(
            self::fields_ACTIONS_SERIALIZED,
            $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null
        );
        return $this;
    }

    /**
     * Check if rule is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->getData(self::fields_STATUS) !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $startDate = $this->getData(self::fields_START_DATE);
        $endDate = $this->getData(self::fields_END_DATE);

        if ($startDate && $now < $startDate) {
            return false;
        }

        if ($endDate && $now > $endDate) {
            return false;
        }

        return true;
    }
}
