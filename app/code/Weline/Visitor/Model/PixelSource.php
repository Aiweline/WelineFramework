<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '来源映射信息')]
#[Index(name: 'idx_pixel_source_name', columns: ['name'])]
#[Index(name: 'idx_pixel_source_code', columns: ['code'])]
class PixelSource extends Model
{
    public const schema_table = 'pixel_source';
    public const schema_primary_key = 'pixel_source_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '来源映射ID')]
    public const schema_fields_ID = 'pixel_source_id';
    #[Col('varchar', 255, nullable: false, comment: '来源映射名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, comment: '来源映射代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 255, nullable: false, comment: 'referer来源域名包含关键词，使用英语逗号隔开')]
    public const schema_fields_referer_domain_contains = 'referer_domain_contains';
    #[Col('varchar', 255, nullable: false, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
}
