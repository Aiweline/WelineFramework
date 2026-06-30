<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI adapter manual skill binding')]
#[Index(name: 'idx_ai_adapter_skill_unique', columns: ['adapter_code', 'skill_code'], type: 'UNIQUE', comment: 'Unique adapter skill binding')]
#[Index(name: 'idx_ai_adapter_skill_adapter', columns: ['adapter_code'], comment: 'Adapter code')]
#[Index(name: 'idx_ai_adapter_skill_status', columns: ['status'], comment: 'Binding status')]
class AiAdapterSkill extends Model
{
    public const schema_table = 'ai_adapter_skill';
    public const schema_primary_key = 'id';

    public const BIND_TYPE_MANUAL = 'manual';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Adapter code')]
    public const schema_fields_ADAPTER_CODE = 'adapter_code';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Skill code')]
    public const schema_fields_SKILL_CODE = 'skill_code';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::BIND_TYPE_MANUAL, comment: 'Binding type')]
    public const schema_fields_BIND_TYPE = 'bind_type';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_ACTIVE, comment: 'Binding status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: true, comment: 'Created by admin ID')]
    public const schema_fields_CREATED_BY = 'created_by';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
