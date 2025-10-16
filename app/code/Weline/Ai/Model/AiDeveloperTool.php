<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Developer Tool Entity
 * 
 * Manages SDKs, documentation, and other developer tools.
 * 
 * @package Weline_Ai
 */
class AiDeveloperTool extends Model
{
    // 框架自动推导表名：AiDeveloperTool → ai_developer_tool
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'tool_type', 'language'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_TOOL_NAME = 'tool_name';
    public const fields_TOOL_TYPE = 'tool_type';
    public const fields_LANGUAGE = 'language';
    public const fields_VERSION = 'version';
    public const fields_DOWNLOAD_URL = 'download_url';
    public const fields_DOCUMENTATION_URL = 'documentation_url';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Tool type constants
     */
    public const TOOL_TYPE_SDK = 'sdk';
    public const TOOL_TYPE_CLI = 'cli';
    public const TOOL_TYPE_DOC = 'doc';

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
            $setup->createTable('AI Developer Tool')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '工具ID'
            )
            ->addColumn(
                self::fields_TOOL_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '工具名称'
            )
            ->addColumn(
                self::fields_TOOL_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '工具类型'
            )
            ->addColumn(
                self::fields_LANGUAGE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'null',
                '编程语言'
            )
            ->addColumn(
                self::fields_VERSION,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '版本号'
            )
            ->addColumn(
                self::fields_DOWNLOAD_URL,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                500,
                'null',
                '下载URL'
            )
            ->addColumn(
                self::fields_DOCUMENTATION_URL,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                500,
                'null',
                '文档URL'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_tool_type', self::fields_TOOL_TYPE)
            ->addIndex('INDEX', 'idx_language', self::fields_LANGUAGE)
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
