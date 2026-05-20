<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI governed skill')]
#[Index(name: 'idx_ai_skill_code', columns: ['code'], type: 'UNIQUE', comment: 'Unique skill code')]
#[Index(name: 'idx_ai_skill_status', columns: ['status'], comment: 'Skill status')]
#[Index(name: 'idx_ai_skill_source_type', columns: ['source_type'], comment: 'Skill source type')]
class AiSkill extends Model
{
    public const schema_table = 'ai_skill';
    public const schema_primary_key = 'id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISABLED = 'disabled';

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_MODULE = 'module';
    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_IMPORT_URL = 'import_url';
    public const SOURCE_IMPORT_PACKAGE = 'import_package';
    public const SOURCE_PLATFORM = 'platform';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Skill ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 64, nullable: false, unique: true, comment: 'Skill code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Skill name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: 'Skill description')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'longtext', nullable: true, comment: 'Skill body')]
    public const schema_fields_BODY = 'body';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Body hash')]
    public const schema_fields_BODY_HASH = 'body_hash';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::SOURCE_CUSTOM, comment: 'Source type')]
    public const schema_fields_SOURCE_TYPE = 'source_type';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Source module')]
    public const schema_fields_SOURCE_MODULE = 'source_module';

    #[Col(type: 'varchar', length: 1024, nullable: true, comment: 'Source URL')]
    public const schema_fields_SOURCE_URL = 'source_url';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Source platform')]
    public const schema_fields_SOURCE_PLATFORM = 'source_platform';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Skill version')]
    public const schema_fields_VERSION = 'version';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_PENDING, comment: 'Skill status')]
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
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_PENDING);
    }

    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }
}
