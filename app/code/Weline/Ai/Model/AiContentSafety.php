<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Content Safety Entity
 * 
 * Records content safety detection results.
 * 
 * @package Weline_Ai
 */
class AiContentSafety extends Model
{
    // 框架自动推导表名：AiContentSafety → ai_content_safety
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'content_type', 'risk_level'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_CONTENT_TYPE = 'content_type';
    public const fields_CONTENT_TEXT = 'content_text';
    public const fields_SAFETY_SCORE = 'safety_score';
    public const fields_RISK_LEVEL = 'risk_level';
    public const fields_DETECTION_RESULT = 'detection_result';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Content type constants
     */
    public const CONTENT_TYPE_INPUT = 'input';
    public const CONTENT_TYPE_OUTPUT = 'output';

    /**
     * Risk level constants
     */
    public const RISK_LEVEL_LOW = 'low';
    public const RISK_LEVEL_MEDIUM = 'medium';
    public const RISK_LEVEL_HIGH = 'high';

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
            $setup->createTable('AI Content Safety')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '记录ID'
            )
            ->addColumn(
                self::fields_CONTENT_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '内容类型'
            )
            ->addColumn(
                self::fields_CONTENT_TEXT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'not null',
                '内容文本'
            )
            ->addColumn(
                self::fields_SAFETY_SCORE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '5,4',
                'not null',
                '安全得分（0-1）'
            )
            ->addColumn(
                self::fields_RISK_LEVEL,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '风险等级'
            )
            ->addColumn(
                self::fields_DETECTION_RESULT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '检测详细结果（JSON）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_content_type', self::fields_CONTENT_TYPE)
            ->addIndex('INDEX', 'idx_risk_level', self::fields_RISK_LEVEL)
            ->addIndex('INDEX', 'idx_created_at', self::fields_CREATED_AT)
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
