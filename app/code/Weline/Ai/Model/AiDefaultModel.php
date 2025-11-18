<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Default Model Configuration Entity
 * 
 * Manages default model configurations for different service types.
 * 
 * @package Weline_Ai
 */
class AiDefaultModel extends Model
{
    // 框架自动推导表名：AiDefaultModel → ai_default_model
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'service_type', 'priority'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_SERVICE_TYPE = 'service_type';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_PRIORITY = 'priority';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Service type constants
     */
    public const SERVICE_TYPE_CHAT = 'chat';
    public const SERVICE_TYPE_TRANSLATION = 'translation';
    public const SERVICE_TYPE_CODE_GENERATION = 'code_generation';
    public const SERVICE_TYPE_IMAGE_GENERATION = 'image_generation';
    public const SERVICE_TYPE_AUDIO_TRANSCRIPTION = 'audio_transcription';

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
            $setup->createTable('AI Default Model Configuration')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '配置ID'
            )
            ->addColumn(
                self::fields_MODEL_CODE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '模型代码'
            )
            ->addColumn(
                self::fields_SERVICE_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '服务类型'
            )
            ->addColumn(
                self::fields_IS_DEFAULT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                1,
                'not null default 0',
                '是否默认'
            )
            ->addColumn(
                self::fields_PRIORITY,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '优先级（数字越大优先级越高）'
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
                'not null default current_timestamp',
                '更新时间'
            )
            ->addIndex('UNIQUE', 'uk_service_type_priority', [self::fields_SERVICE_TYPE, self::fields_PRIORITY])
            ->addIndex('INDEX', 'idx_model_code', self::fields_MODEL_CODE)
            ->addIndex('INDEX', 'idx_is_default', self::fields_IS_DEFAULT)
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
     * Get model code
     * 
     * @return string
     */
    public function getModelCode(): string
    {
        return (string)$this->getData(self::fields_MODEL_CODE);
    }

    /**
     * Get service type
     * 
     * @return string
     */
    public function getServiceType(): string
    {
        return (string)$this->getData(self::fields_SERVICE_TYPE);
    }

    /**
     * Get priority
     * 
     * @return int
     */
    public function getPriority(): int
    {
        return (int)$this->getData(self::fields_PRIORITY);
    }

    /**
     * Check if this is default model
     * 
     * @return bool
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
    }
}
