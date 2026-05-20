<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI design direction template')]
#[Index(name: 'idx_ai_site_design_direction_owner_code', columns: ['admin_user_id', 'code'], type: 'UNIQUE', comment: 'Unique design direction code per owner')]
#[Index(name: 'idx_ai_site_design_direction_status', columns: ['status'], comment: 'Design direction status')]
#[Index(name: 'idx_ai_site_design_direction_source', columns: ['source_type'], comment: 'Design direction source type')]
class AiSiteDesignDirection extends Model
{
    public const schema_table = 'guolairen_page_builder_ai_site_design_direction';
    public const schema_primary_key = 'ai_site_design_direction_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const SOURCE_BUILTIN = 'builtin';
    public const SOURCE_CUSTOM = 'custom';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Design direction ID')]
    public const schema_fields_ID = 'ai_site_design_direction_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Owner admin user ID, 0 for system')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'varchar', length: 96, nullable: false, comment: 'Design direction code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Design direction name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: 'Design direction description')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::SOURCE_CUSTOM, comment: 'Source type')]
    public const schema_fields_SOURCE_TYPE = 'source_type';

    #[Col(type: 'longtext', nullable: true, comment: 'Industry tags JSON')]
    public const schema_fields_INDUSTRY_TAGS = 'industry_tags';

    #[Col(type: 'longtext', nullable: true, comment: 'Match keywords JSON')]
    public const schema_fields_MATCH_KEYWORDS = 'match_keywords';

    #[Col(type: 'longtext', nullable: true, comment: 'Visual keywords JSON')]
    public const schema_fields_VISUAL_KEYWORDS = 'visual_keywords';

    #[Col(type: 'longtext', nullable: true, comment: 'Color system JSON')]
    public const schema_fields_COLOR_SYSTEM = 'color_system';

    #[Col(type: 'longtext', nullable: true, comment: 'Layout patterns JSON')]
    public const schema_fields_LAYOUT_PATTERNS = 'layout_patterns';

    #[Col(type: 'longtext', nullable: true, comment: 'Image strategy JSON')]
    public const schema_fields_IMAGE_STRATEGY = 'image_strategy';

    #[Col(type: 'text', nullable: true, comment: 'CTA style')]
    public const schema_fields_CTA_STYLE = 'cta_style';

    #[Col(type: 'longtext', nullable: true, comment: 'Forbidden patterns JSON')]
    public const schema_fields_FORBIDDEN_PATTERNS = 'forbidden_patterns';

    #[Col(type: 'longtext', nullable: true, comment: 'Block rules JSON')]
    public const schema_fields_BLOCK_RULES = 'block_rules';

    #[Col(type: 'longtext', nullable: true, comment: 'QA rules JSON')]
    public const schema_fields_QA_RULES = 'qa_rules';

    #[Col(type: 'longtext', nullable: true, comment: 'Example references JSON')]
    public const schema_fields_EXAMPLE_REFS = 'example_refs';

    #[Col(type: 'text', nullable: true, comment: 'Supplemental structured note')]
    public const schema_fields_SUPPLEMENTAL_PROMPT = 'supplemental_prompt';

    #[Col(type: 'int', nullable: false, default: 1, comment: 'Template version')]
    public const schema_fields_VERSION = 'version';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_ACTIVE, comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getCode(): string
    {
        return (string)($this->getData(self::schema_fields_CODE) ?: '');
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_ACTIVE);
    }
}
