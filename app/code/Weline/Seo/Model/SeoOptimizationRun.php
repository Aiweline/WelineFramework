<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO自动优化分析与执行审计')]
#[Index(name: 'uniq_run_key', columns: ['run_key'], type: 'UNIQUE')]
#[Index(name: 'idx_owner_status', columns: ['website_id', 'page_type', 'block_key', 'status'])]
class SeoOptimizationRun extends Model
{
    public const schema_table = 'weline_seo_optimization_run';
    public const schema_primary_key = 'run_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'run_id';
    #[Col('varchar', 96, nullable: false)] public const schema_fields_RUN_KEY = 'run_key';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_ADAPTER = 'adapter';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('varchar', 128)] public const schema_fields_BLOCK_KEY = 'block_key';
    #[Col('int', 0, nullable: false)] public const schema_fields_SOURCE_REVISION = 'source_revision';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_SOURCE_FINGERPRINT = 'source_fingerprint';
    #[Col('varchar', 32, nullable: false)] public const schema_fields_STATUS = 'status';
    #[Col('longtext')] public const schema_fields_EVIDENCE_JSON = 'evidence_json';
    #[Col('longtext')] public const schema_fields_RECOMMENDATION_JSON = 'recommendation_json';
    #[Col('varchar', 80)] public const schema_fields_ERROR_CODE = 'error_code';
    #[Col('text')] public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime')] public const schema_fields_WINDOW_START = 'window_start';
    #[Col('datetime')] public const schema_fields_WINDOW_END = 'window_end';
    #[Col('datetime')] public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime')] public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();
        $now = \date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
