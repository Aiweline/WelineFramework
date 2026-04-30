<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI custom skill')]
#[Index(name: 'idx_ai_site_skill_code', columns: ['code'], type: 'UNIQUE', comment: 'Unique skill code')]
#[Index(name: 'idx_ai_site_skill_status', columns: ['status'], comment: 'Skill status')]
class AiSiteSkill extends Model
{
    public const schema_table = 'guolairen_page_builder_ai_site_skill';
    public const schema_primary_key = 'ai_site_skill_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const SOURCE_CUSTOM_DB = 'custom_db';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Skill ID')]
    public const schema_fields_ID = 'ai_site_skill_id';

    #[Col(type: 'varchar', length: 64, nullable: false, unique: true, comment: 'Skill code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Skill name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: 'Skill description')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'longtext', nullable: true, comment: 'Skill body')]
    public const schema_fields_BODY = 'body';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_ACTIVE, comment: 'Skill status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::SOURCE_CUSTOM_DB, comment: 'Skill source')]
    public const schema_fields_SOURCE = 'source';

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
