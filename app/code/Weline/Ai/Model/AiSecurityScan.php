<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Security Scan Entity
 * 
 * Records security scan results for AI operations.
 * 
 * @package Weline_Ai
 */
class AiSecurityScan extends Model
{
    // 框架自动推导表名：AiSecurityScan → ai_security_scan
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'scan_type', 'scan_status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_SCAN_TYPE = 'scan_type';
    public const fields_SCAN_TARGET = 'scan_target';
    public const fields_SCAN_STATUS = 'scan_status';
    public const fields_SCAN_RESULT = 'scan_result';
    public const fields_VULNERABILITY_COUNT = 'vulnerability_count';
    public const fields_SCANNED_AT = 'scanned_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Scan type constants
     */
    public const SCAN_TYPE_API_KEY = 'api_key';
    public const SCAN_TYPE_MODEL_CONFIG = 'model_config';
    public const SCAN_TYPE_CONTENT = 'content';
    public const SCAN_TYPE_INJECTION = 'injection';

    /**
     * Scan status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

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
            $setup->createTable('AI Security Scan')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '扫描ID'
            )
            ->addColumn(
                self::fields_SCAN_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '扫描类型'
            )
            ->addColumn(
                self::fields_SCAN_TARGET,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '扫描目标'
            )
            ->addColumn(
                self::fields_SCAN_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null default \'pending\'',
                '扫描状态'
            )
            ->addColumn(
                self::fields_SCAN_RESULT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '扫描结果（JSON）'
            )
            ->addColumn(
                self::fields_VULNERABILITY_COUNT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '漏洞数量'
            )
            ->addColumn(
                self::fields_SCANNED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '扫描完成时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_scan_type', self::fields_SCAN_TYPE)
            ->addIndex('INDEX', 'idx_scan_status', self::fields_SCAN_STATUS)
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
