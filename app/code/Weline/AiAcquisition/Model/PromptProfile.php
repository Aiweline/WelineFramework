<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 获客提示词档案：站点/品类专属的自动化获客提示词，按运行选择。
 */
#[Table(comment: 'AI获客提示词档案')]
#[Index(name: 'idx_ai_acquisition_prompt_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_acquisition_prompt_default', columns: ['is_default'])]
class PromptProfile extends Model
{
    public const schema_table = 'ai_acquisition_prompt_profile';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 64, nullable: false, comment: '档案代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 128, nullable: false, comment: '档案名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', nullable: false, comment: '提示词正文')]
    public const schema_fields_BODY = 'body';
    #[Col('smallint', 1, default: 0, comment: '是否默认')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'is_default', 'updated_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
