<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Default Model Configuration Entity
 *
 * Manages default model configurations for different service types.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI Default Model Configuration')]
#[Index(name: 'uk_service_type_priority', columns: ['service_type', 'priority'], type: 'UNIQUE')]
#[Index(name: 'idx_model_code', columns: ['model_code'])]
#[Index(name: 'idx_is_default', columns: ['is_default'])]
class AiDefaultModel extends Model
{
    public const schema_table = 'ai_default_model';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'service_type', 'priority'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '服务类型')]
    public const schema_fields_SERVICE_TYPE = 'service_type';
    #[Col(type: 'int', length: 1, nullable: false, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col(type: 'int', length: 1, nullable: false, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', nullable: false, default: 0, comment: '优先级（数字越大优先级越高）')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'timestamp', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const SERVICE_TYPE_CHAT = 'chat';
    public const SERVICE_TYPE_TRANSLATION = 'translation';
    public const SERVICE_TYPE_CODE_GENERATION = 'code_generation';
    public const SERVICE_TYPE_IMAGE_GENERATION = 'image_generation';
    public const SERVICE_TYPE_AUDIO_TRANSCRIPTION = 'audio_transcription';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function getModelCode(): string
    {
        return (string)$this->getData(self::schema_fields_MODEL_CODE);
    }

    public function getServiceType(): string
    {
        return (string)$this->getData(self::schema_fields_SERVICE_TYPE);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function isDefault(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_DEFAULT);
    }

    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }
}

