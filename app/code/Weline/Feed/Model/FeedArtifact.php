<?php

declare(strict_types=1);

namespace Weline\Feed\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '购物目录 Feed 生成物')]
#[Index(name: 'idx_feed_artifact_definition_revision', columns: ['definition_id', 'revision'])]
final class FeedArtifact extends Model
{
    public const schema_table = 'feed_artifact';
    public const schema_primary_key = 'artifact_id';

    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public array $_unit_primary_keys = ['artifact_id'];

    /** @var list<string> */
    public array $_index_sort_keys = ['artifact_id', 'definition_id', 'revision'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '生成物 ID')]
    public const schema_fields_ID = 'artifact_id';

    #[Col('int', nullable: false, default: 0, comment: '定义 ID')]
    public const schema_fields_DEFINITION_ID = 'definition_id';

    #[Col('int', nullable: false, default: 1, comment: '修订')]
    public const schema_fields_REVISION = 'revision';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'Content-Type')]
    public const schema_fields_CONTENT_TYPE = 'content_type';

    #[Col('varchar', 512, nullable: false, default: '', comment: '存储位置')]
    public const schema_fields_STORAGE_PATH = 'storage_path';

    #[Col('int', nullable: false, default: 0, comment: '字节数')]
    public const schema_fields_BYTE_SIZE = 'byte_size';

    #[Col('varchar', 64, nullable: false, default: '', comment: '校验和')]
    public const schema_fields_CHECKSUM = 'checksum';

    #[Col('int', nullable: false, default: 0, comment: '条数')]
    public const schema_fields_ITEM_COUNT = 'item_count';

    #[Col('timestamp', nullable: true, comment: '生成时间')]
    public const schema_fields_GENERATED_AT = 'generated_at';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_FAILED, comment: 'ready 或 failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: false, default: '', comment: '错误码')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col('int', nullable: false, default: 0, comment: '跳过条数')]
    public const schema_fields_SKIPPED_COUNT = 'skipped_count';

    #[Col('text', nullable: true, comment: '生成诊断')]
    public const schema_fields_DIAGNOSTIC_JSON = 'diagnostic_json';
}
