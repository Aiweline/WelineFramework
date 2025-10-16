<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI I18n Content Entity
 * 
 * Stores internationalization translation content.
 * 
 * @package Weline_Ai
 */
class AiI18nContent extends Model
{
    // 框架自动推导表名：AiI18nContent → ai_i18n_content
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'content_type', 'locale_code'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_CONTENT_TYPE = 'content_type';
    public const fields_CONTENT_KEY = 'content_key';
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_CONTENT_VALUE = 'content_value';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Content type constants
     */
    public const CONTENT_TYPE_UI = 'ui';
    public const CONTENT_TYPE_MESSAGE = 'message';
    public const CONTENT_TYPE_ERROR = 'error';

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
            $setup->createTable('AI I18n Content')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '内容ID'
            )
            ->addColumn(
                self::fields_CONTENT_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '内容类型'
            )
            ->addColumn(
                self::fields_CONTENT_KEY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '内容键'
            )
            ->addColumn(
                self::fields_LOCALE_CODE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                10,
                'not null',
                '语言代码'
            )
            ->addColumn(
                self::fields_CONTENT_VALUE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'not null',
                '内容值'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('UNIQUE', 'uk_content_locale', [self::fields_CONTENT_TYPE, self::fields_CONTENT_KEY, self::fields_LOCALE_CODE])
            ->addIndex('INDEX', 'idx_locale_code', self::fields_LOCALE_CODE)
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
