<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '站点SEO自动优化策略')]
#[Index(name: 'uniq_website', columns: ['website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_mode', columns: ['mode'])]
class SeoOptimizationPolicy extends Model
{
    public const schema_table = 'weline_seo_optimization_policy';
    public const schema_primary_key = 'policy_id';
    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_AUTO_DRAFT = 'auto_draft';
    public const MODE_AUTO_PUBLISH = 'auto_publish';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'policy_id';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 24, nullable: false, default: self::MODE_SHADOW)] public const schema_fields_MODE = 'mode';
    #[Col('smallint', 1, nullable: false, default: 0)] public const schema_fields_STANDING_AUTHORIZED = 'standing_authorized';
    #[Col('int', 0, nullable: false, default: 500)] public const schema_fields_MIN_PAGE_VIEWS = 'min_page_views';
    #[Col('int', 0, nullable: false, default: 30)] public const schema_fields_MIN_CONVERSIONS = 'min_conversions';
    #[Col('int', 0, nullable: false, default: 1000)] public const schema_fields_MIN_SEARCH_IMPRESSIONS = 'min_search_impressions';
    #[Col('decimal', '5,4', nullable: false, default: 0.8000)] public const schema_fields_MIN_CONFIDENCE = 'min_confidence';
    #[Col('int', 0, nullable: false, default: 500)] public const schema_fields_MIN_UPLIFT_BPS = 'min_uplift_bps';
    #[Col('int', 0, nullable: false, default: 300)] public const schema_fields_MAX_GUARDRAIL_REGRESSION_BPS = 'max_guardrail_regression_bps';
    #[Col('int', 0, nullable: false, default: 14)] public const schema_fields_CONTENT_BASELINE_DAYS = 'content_baseline_days';
    #[Col('int', 0, nullable: false, default: 28)] public const schema_fields_SEO_BASELINE_DAYS = 'seo_baseline_days';
    #[Col('int', 0, nullable: false, default: 7)] public const schema_fields_EVALUATION_MIN_DAYS = 'evaluation_min_days';
    #[Col('int', 0, nullable: false, default: 28)] public const schema_fields_EVALUATION_MAX_DAYS = 'evaluation_max_days';
    #[Col('int', 0, nullable: false, default: 14)] public const schema_fields_COOLDOWN_DAYS = 'cooldown_days';
    #[Col('datetime')] public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime')] public const schema_fields_UPDATED_AT = 'updated_at';

    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_SHADOW, self::MODE_AUTO_DRAFT, self::MODE_AUTO_PUBLISH];
    }

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
