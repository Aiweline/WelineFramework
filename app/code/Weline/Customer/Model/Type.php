<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 客户类型注册表。
 */
#[Table(comment: '客户类型注册表')]
#[Index(name: 'idx_customer_type_code', columns: ['code'], type: 'UNIQUE')]
class Type extends Model
{
    public const schema_table = 'customer_type';
    public const schema_primary_key = 'type_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'type_id';
    #[Col('varchar', 32, nullable: false, comment: '类型代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 100, default: '', comment: '显示名称')]
    public const schema_fields_NAME = 'name';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_SORT = 'sort';
    #[Col('smallint', default: 0, comment: '是否系统内置(禁止删除)')]
    public const schema_fields_IS_SYSTEM = 'is_system';

    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['type_id'];

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
