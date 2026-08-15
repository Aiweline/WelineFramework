<?php

declare(strict_types=1);

namespace Weline\Inquiry\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '询盘表单')]
#[Index(name: 'idx_inquiry_form_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_inquiry_form_status', columns: ['status'])]
class Form extends Model
{
    public const schema_table = 'weline_inquiry_form';
    public const schema_primary_key = 'form_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '表单 ID')]
    public const schema_fields_ID = 'form_id';
    #[Col('varchar', 96, nullable: false, comment: '稳定表单代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 255, nullable: false, comment: '内部名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 20, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 32, nullable: false, default: 'en_US', comment: '默认语言')]
    public const schema_fields_DEFAULT_LOCALE = 'default_locale';
    #[Col('int', nullable: true, comment: '当前草稿版本')]
    public const schema_fields_DRAFT_VERSION_ID = 'draft_version_id';
    #[Col('int', nullable: true, comment: '当前发布版本')]
    public const schema_fields_PUBLISHED_VERSION_ID = 'published_version_id';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_CODE, self::schema_fields_STATUS];
    public function _init(): void { $this->_table = self::schema_table; $this->_id_field_name = self::schema_fields_ID; }
}
