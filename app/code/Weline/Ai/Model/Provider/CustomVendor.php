<?php
declare(strict_types=1);

namespace Weline\Ai\Model\Provider;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 自定义/本地 AI 供应商定义（OpenAI 兼容）
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI自定义供应商表')]
#[Index(name: 'uniq_ai_custom_vendor_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_custom_vendor_active', columns: ['is_active'])]
class CustomVendor extends Model
{
    public const schema_table = 'ai_custom_vendor';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'code', 'is_active'];

    public const DRIVER_OPENAI_COMPAT = 'openai_compat';
    public const SOURCE_CUSTOM = 'custom';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 50, nullable: false, comment: '供应商代码')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 100, nullable: false, comment: '显示名称')]
    public const schema_fields_NAME = 'name';

    #[Col('varchar', 255, nullable: false, comment: '默认 API 基础 URL')]
    public const schema_fields_BASE_URL = 'base_url';

    #[Col('varchar', 32, nullable: false, default: 'openai_compat', comment: '驱动类型')]
    public const schema_fields_DRIVER = 'driver';

    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('varchar', 255, comment: '说明')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('varchar', 100, comment: '默认测试模型')]
    public const schema_fields_TEST_MODEL = 'test_model';

    #[Col('text', comment: '额外配置 JSON')]
    public const schema_fields_CONFIG = 'config';

    #[Col('int', null, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', null, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($config) ? $config : [];
    }

    public function isActive(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ACTIVE) === 1;
    }
}
