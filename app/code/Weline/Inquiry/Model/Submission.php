<?php

declare(strict_types=1);

namespace Weline\Inquiry\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '询盘提交')]
#[Index(name: 'idx_inquiry_submission_idempotency', columns: ['form_id', 'idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_inquiry_submission_form_created', columns: ['form_id', 'created_at'])]
class Submission extends Model
{
    public const schema_table = 'weline_inquiry_submission';
    public const schema_primary_key = 'submission_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '提交 ID')]
    public const schema_fields_ID = 'submission_id';
    #[Col('int', nullable: false, comment: '表单 ID')]
    public const schema_fields_FORM_ID = 'form_id';
    #[Col('int', nullable: false, comment: '版本 ID')]
    public const schema_fields_VERSION_ID = 'version_id';
    #[Col('varchar', 32, nullable: false, comment: '提交语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 128, nullable: false, comment: '幂等键')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('text', nullable: false, comment: '提交 payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('text', nullable: false, comment: 'schema 快照 JSON')]
    public const schema_fields_SCHEMA_SNAPSHOT_JSON = 'schema_snapshot_json';
    #[Col('varchar', 64, nullable: true, comment: '请求来源 IP 摘要')]
    public const schema_fields_SOURCE_FINGERPRINT = 'source_fingerprint';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_FORM_ID, self::schema_fields_CREATED_AT];
    public function _init(): void { $this->_table = self::schema_table; $this->_id_field_name = self::schema_fields_ID; }
}
