<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Marketing Campaign Entity
 * 
 * Manages marketing and promotional campaigns.
 * 
 * @package Weline_Ai
 */
class AiMarketingCampaign extends Model
{
    // 框架自动推导表名：AiMarketingCampaign → ai_marketing_campaign
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'campaign_type', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_CAMPAIGN_NAME = 'campaign_name';
    public const fields_CAMPAIGN_TYPE = 'campaign_type';
    public const fields_DESCRIPTION = 'description';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_BUDGET = 'budget';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Campaign type constants
     */
    public const CAMPAIGN_TYPE_PROMOTION = 'promotion';
    public const CAMPAIGN_TYPE_REFERRAL = 'referral';
    public const CAMPAIGN_TYPE_DISCOUNT = 'discount';

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

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
            $setup->createTable('AI Marketing Campaign')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '活动ID'
            )
            ->addColumn(
                self::fields_CAMPAIGN_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '活动名称'
            )
            ->addColumn(
                self::fields_CAMPAIGN_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '活动类型'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '活动描述'
            )
            ->addColumn(
                self::fields_START_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATE,
                0,
                'not null',
                '开始日期'
            )
            ->addColumn(
                self::fields_END_DATE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATE,
                0,
                'not null',
                '结束日期'
            )
            ->addColumn(
                self::fields_BUDGET,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,2',
                'null',
                '预算'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'draft\'',
                '状态'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_campaign_type', self::fields_CAMPAIGN_TYPE)
            ->addIndex('INDEX', 'idx_status', self::fields_STATUS)
            ->addIndex('INDEX', 'idx_dates', [self::fields_START_DATE, self::fields_END_DATE])
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
}
