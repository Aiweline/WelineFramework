<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'System config version batch table')]
#[Index(name: 'idx_system_config_version_scope', columns: ['module', 'area', 'scope', 'locale'])]
#[Index(name: 'idx_system_config_version_created', columns: ['created_at'])]
#[Index(name: 'idx_system_config_version_parent', columns: ['parent_version_id'])]
class SystemConfigVersion extends Model
{
    public const schema_table = 'system_config_version';
    public const schema_primary_key = 'version_id';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Version ID')]
    public const schema_fields_ID = 'version_id';

    #[Col('varchar', 120, nullable: false, comment: 'Module')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 120, nullable: false, default: 'frontend', comment: 'Area')]
    public const schema_fields_AREA = 'area';

    #[Col('varchar', 191, nullable: false, default: SystemConfig::SCOPE_GLOBAL, comment: 'Config scope')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('varchar', 32, nullable: false, default: SystemConfig::LOCALE_DEFAULT, comment: 'Locale code or default')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('varchar', 32, nullable: false, default: 'save', comment: 'save, rollback, import, ai')]
    public const schema_fields_OPERATION = 'operation';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_APPLIED, comment: 'Version status')]
    public const schema_fields_STATUS = 'status';

    #[Col('longtext', nullable: true, comment: 'Change records JSON')]
    public const schema_fields_CHANGES_JSON = 'changes_json';

    #[Col('text', nullable: true, comment: 'Inherited keys JSON')]
    public const schema_fields_INHERIT_KEYS_JSON = 'inherit_keys_json';

    #[Col('text', nullable: true, comment: 'Optimistic base versions JSON')]
    public const schema_fields_BASE_VERSIONS_JSON = 'base_versions_json';

    #[Col('varchar', 96, nullable: true, comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';

    #[Col('varchar', 120, nullable: true, comment: 'Actor display name')]
    public const schema_fields_ACTOR_NAME = 'actor_name';

    #[Col('text', nullable: true, comment: 'Save reason')]
    public const schema_fields_REASON = 'reason';

    #[Col('text', nullable: true, comment: 'Version metadata JSON')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('int', 11, nullable: true, comment: 'Parent version ID')]
    public const schema_fields_PARENT_VERSION_ID = 'parent_version_id';

    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_index_sort_keys = ['module', 'area', 'scope', 'locale', 'created_at'];

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChanges(): array
    {
        $changes = $this->decodeJson((string)$this->getData(self::schema_fields_CHANGES_JSON));

        return is_array($changes) ? $changes : [];
    }

    /**
     * @return list<string>
     */
    public function getInheritedKeys(): array
    {
        $keys = $this->decodeJson((string)$this->getData(self::schema_fields_INHERIT_KEYS_JSON));

        return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
    }

    /**
     * @return array<string, int>
     */
    public function getBaseVersions(): array
    {
        $versions = $this->decodeJson((string)$this->getData(self::schema_fields_BASE_VERSIONS_JSON));
        if (!is_array($versions)) {
            return [];
        }

        $result = [];
        foreach ($versions as $key => $version) {
            $result[(string)$key] = (int)$version;
        }

        return $result;
    }

    public function getMetadataData(): array
    {
        $metadata = $this->decodeJson((string)$this->getData(self::schema_fields_METADATA));

        return is_array($metadata) ? $metadata : [];
    }

    private function decodeJson(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
