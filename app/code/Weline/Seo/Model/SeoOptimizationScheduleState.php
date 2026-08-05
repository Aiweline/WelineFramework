<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 自动优化站点调度时间投影')]
#[Index(name: 'uniq_website', columns: ['website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_next_analysis', columns: ['next_analysis_at'])]
class SeoOptimizationScheduleState extends Model
{
    public const schema_table = 'weline_seo_optimization_schedule_state';
    public const schema_primary_key = 'schedule_id';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'schedule_id';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, default: 1440)] public const schema_fields_ANALYSIS_INTERVAL_MINUTES = 'analysis_interval_minutes';
    #[Col('datetime')] public const schema_fields_LAST_ANALYSIS_AT = 'last_analysis_at';
    #[Col('datetime')] public const schema_fields_NEXT_ANALYSIS_AT = 'next_analysis_at';
    #[Col('datetime')] public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();
        $this->setData(self::schema_fields_UPDATED_AT, \gmdate('Y-m-d H:i:s'));
    }
}
