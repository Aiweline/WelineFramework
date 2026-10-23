<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 获客洲目录：客户与五大洲（及南极洲兜底）的归属关系。
 */
#[Table(comment: 'AI获客洲目录')]
#[Index(name: 'idx_ai_acquisition_continent_code', columns: ['code'], type: 'UNIQUE')]
class Continent extends Model
{
    public const schema_table = 'ai_acquisition_continent';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 8, nullable: false, comment: '洲代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 64, nullable: false, comment: '中文名')]
    public const schema_fields_NAME_ZH = 'name_zh';
    #[Col('varchar', 64, nullable: false, comment: '英文名')]
    public const schema_fields_NAME_EN = 'name_en';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT = 'sort';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'code', 'sort'];

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
