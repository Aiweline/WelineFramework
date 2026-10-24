<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social autopilot plan')]
#[Index(name: 'uniq_social_autopilot_website', columns: ['website_id'], type: 'UNIQUE')]
class SocialAutopilotPlan extends Model
{
    public const schema_table = 'weline_social_autopilot_plan';
    public const schema_primary_key = 'plan_id';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Plan ID')]
    public const schema_fields_ID = 'plan_id';
    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col('varchar', 64, nullable: false, default: 'Asia/Shanghai', comment: 'Timezone')]
    public const schema_fields_TIMEZONE = 'timezone';
    #[Col('text', nullable: true, comment: 'Daily slots JSON')]
    public const schema_fields_SLOTS_JSON = 'slots_json';
    #[Col('text', nullable: true, comment: 'Weekly content mix JSON')]
    public const schema_fields_MIX_JSON = 'mix_json';
    #[Col('text', nullable: true, comment: 'Topic source ratio JSON')]
    public const schema_fields_TOPIC_RATIO_JSON = 'topic_ratio_json';
    #[Col('text', nullable: true, comment: 'Auto publish platform codes JSON')]
    public const schema_fields_AUTO_PLATFORM_CODES_JSON = 'auto_platform_codes_json';
    #[Col('int', 0, nullable: false, default: 1, comment: 'Review video before publish')]
    public const schema_fields_REVIEW_VIDEO = 'review_video';
    #[Col('text', nullable: true, comment: 'Per platform quota JSON')]
    public const schema_fields_QUOTA_JSON = 'quota_json';
    #[Col('varchar', 16, nullable: false, default: 'auto', comment: 'Publish mode')]
    public const schema_fields_MODE = 'mode';
    #[Col('int', 0, nullable: false, default: 6, comment: 'Max posts per day')]
    public const schema_fields_MAX_DAILY = 'max_daily';
    #[Col('int', 0, nullable: false, default: 1, comment: 'Daily generation count')]
    public const schema_fields_DAILY_COUNT = 'daily_count';
    #[Col('text', nullable: true, default: '["text"]', comment: 'Content types JSON')]
    public const schema_fields_CONTENT_TYPES_JSON = 'content_types_json';
    #[Col('varchar', 191, nullable: false, default: '', comment: 'Image model code')]
    public const schema_fields_IMAGE_MODEL_CODE = 'image_model_code';
    #[Col('varchar', 191, nullable: false, default: '', comment: 'Video model code')]
    public const schema_fields_VIDEO_MODEL_CODE = 'video_model_code';
    #[Col('varchar', 24, nullable: false, default: 'image_slideshow', comment: 'Video source')]
    public const schema_fields_VIDEO_SOURCE = 'video_source';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['plan_id'];
    public array $_index_sort_keys = ['website_id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => 0,
            'timezone' => 'Asia/Shanghai',
            'slots' => ['09:30', '19:30'],
            'mix' => ['text' => 2, 'image' => 1, 'video' => 1],
            'topic_ratio' => ['site' => 3, 'web' => 1],
            'auto_platform_codes' => [],
            'review_video' => 1,
            'quota' => ['per_platform_daily' => 1],
            'mode' => 'auto',
            'max_daily' => 6,
            'daily_count' => 1,
            'content_types' => ['text'],
            'image_model_code' => '',
            'video_model_code' => '',
            'video_source' => 'image_slideshow',
        ];
    }
}
