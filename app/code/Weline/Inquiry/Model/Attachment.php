<?php

declare(strict_types=1);

namespace Weline\Inquiry\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '询盘附件票据')]
#[Index(name: 'idx_inquiry_attachment_submission', columns: ['submission_id'])]
class Attachment extends Model
{
    public const schema_table = 'weline_inquiry_attachment';
    public const schema_primary_key = 'attachment_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '附件 ID')]
    public const schema_fields_ID = 'attachment_id';
    #[Col('int', nullable: false, comment: '提交 ID')]
    public const schema_fields_SUBMISSION_ID = 'submission_id';
    #[Col('varchar', 128, nullable: false, comment: '上传票据')]
    public const schema_fields_UPLOAD_TICKET = 'upload_ticket';
    #[Col('varchar', 255, nullable: true, comment: '原始名称')]
    public const schema_fields_FILENAME = 'filename';
    #[Col('varchar', 127, nullable: true, comment: 'MIME')]
    public const schema_fields_MIME_TYPE = 'mime_type';
    #[Col('int', nullable: true, comment: '文件字节数')]
    public const schema_fields_SIZE = 'size';
    #[Col('timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_SUBMISSION_ID];
    public function _init(): void { $this->_table = self::schema_table; $this->_id_field_name = self::schema_fields_ID; }
}
