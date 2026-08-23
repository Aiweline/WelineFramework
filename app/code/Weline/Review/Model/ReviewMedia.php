<?php

declare(strict_types=1);

namespace Weline\Review\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '通用评论图片与视频')]
#[Index(name: 'uk_review_media_token', columns: ['upload_token'], type: 'UNIQUE')]
#[Index(name: 'idx_review_media_review_status', columns: ['review_id', 'status'])]
#[Index(name: 'idx_review_media_entity_status', columns: ['type_code', 'entity_uuid', 'status'])]
class ReviewMedia extends Model
{
    public const schema_table = 'weline_review_media';
    public const schema_primary_key = 'media_id';

    public const STATUS_STAGED = 'staged';
    public const STATUS_ATTACHED = 'attached';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '媒体 ID')]
    public const schema_fields_ID = 'media_id';
    #[Col('int', nullable: true, comment: '评论 ID')]
    public const schema_fields_REVIEW_ID = 'review_id';
    #[Col('varchar', 64, nullable: false, comment: '一次性上传票据')]
    public const schema_fields_UPLOAD_TOKEN = 'upload_token';
    #[Col('varchar', 64, nullable: false, comment: '评论类型')]
    public const schema_fields_TYPE_CODE = 'type_code';
    #[Col('varchar', 36, nullable: false, comment: '被评论实体 UUID')]
    public const schema_fields_ENTITY_UUID = 'entity_uuid';
    #[Col('varchar', 16, nullable: false, comment: 'image 或 video')]
    public const schema_fields_MEDIA_KIND = 'media_kind';
    #[Col('varchar', 500, nullable: false, comment: '相对 pub/media 路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 127, nullable: false, comment: '服务端识别的 MIME')]
    public const schema_fields_MIME_TYPE = 'mime_type';
    #[Col('varchar', 255, nullable: true, comment: '原始文件名')]
    public const schema_fields_ORIGINAL_NAME = 'original_name';
    #[Col('int', nullable: false, default: 0, comment: '文件字节数')]
    public const schema_fields_SIZE = 'size';
    #[Col('varchar', 20, nullable: false, default: 'staged', comment: 'staged/attached')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_UPLOAD_TOKEN, self::schema_fields_REVIEW_ID, self::schema_fields_ENTITY_UUID, self::schema_fields_STATUS];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_id_field_name = self::schema_fields_ID;
    }
}
