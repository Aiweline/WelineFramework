<?php
declare(strict_types=1);
namespace WelineTools\FontSubLetter\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '字体字符映射')]
#[Index(name: 'idx_record_id', columns: ['record_id'], comment: '记录ID索引')]
#[Index(name: 'idx_char_code', columns: ['char_code'], comment: '字符编码索引')]
class CharMap extends Model
{
    public const schema_table = 'weline_font_sub_letter_char_maps';
    public const schema_primary_key = 'id';
    #[Col(type: 'bigint', length: 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'bigint', length: 0, nullable: false, comment: '记录ID')]
    public const schema_fields_RECORD_ID = 'record_id';
    #[Col(type: 'integer', length: 0, nullable: false, comment: '字符编码')]
    public const schema_fields_CHAR_CODE = 'char_code';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '字符值')]
    public const schema_fields_CHAR_VALUE = 'char_value';
    #[Col(type: 'integer', length: 1, nullable: true, default: 1, comment: '是否包含')]
    public const schema_fields_IS_INCLUDED = 'is_included';
    #[Col(type: 'integer', length: 0, nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
