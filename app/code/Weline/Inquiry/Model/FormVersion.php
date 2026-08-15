<?php

declare(strict_types=1);

namespace Weline\Inquiry\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '询盘表单版本')]
#[Index(name: 'idx_inquiry_version_form_no', columns: ['form_id', 'version_no'], type: 'UNIQUE')]
class FormVersion extends Model
{
    public const schema_table = 'weline_inquiry_form_version';
    public const schema_primary_key = 'version_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '版本 ID')]
    public const schema_fields_ID = 'version_id';
    #[Col('int', nullable: false, comment: '表单 ID')]
    public const schema_fields_FORM_ID = 'form_id';
    #[Col('int', nullable: false, comment: '版本号')]
    public const schema_fields_VERSION_NO = 'version_no';
    #[Col('varchar', 20, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', nullable: false, comment: '中性 schema JSON')]
    public const schema_fields_SCHEMA_JSON = 'schema_json';
    #[Col('text', nullable: true, comment: '展示设置 JSON')]
    public const schema_fields_SETTINGS_JSON = 'settings_json';
    #[Col('varchar', 64, nullable: false, comment: 'schema 校验和')]
    public const schema_fields_CHECKSUM = 'checksum';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_FORM_ID, self::schema_fields_VERSION_NO];
    public function _init(): void { $this->_table = self::schema_table; $this->_id_field_name = self::schema_fields_ID; }
}
