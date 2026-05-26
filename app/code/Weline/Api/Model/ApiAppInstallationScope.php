<?php
declare(strict_types=1);

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'API app installation scope')]
#[Index(name: 'idx_w_api_app_scope_install_source', columns: ['installation_id', 'source_id'], type: 'UNIQUE', comment: 'Installation source')]
#[Index(name: 'idx_w_api_app_scope_source', columns: ['source_id'], comment: 'Source ID')]
class ApiAppInstallationScope extends Model
{
    public const schema_table = 'm_api_app_installation_scope';
    public const schema_primary_key = 'scope_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Scope ID')]
    public const schema_fields_ID = 'scope_id';
    #[Col(type: 'int', nullable: false, comment: 'Installation ID')]
    public const schema_fields_INSTALLATION_ID = 'installation_id';
    #[Col(type: 'varchar', length: 127, nullable: false, comment: 'ACL source ID')]
    public const schema_fields_SOURCE_ID = 'source_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'edit', comment: 'Access mode snapshot')]
    public const schema_fields_ACCESS_MODE = 'access_mode';
    #[Col(type: 'varchar', length: 127, nullable: false, default: '', comment: 'Scope group snapshot')]
    public const schema_fields_SCOPE_GROUP = 'scope_group';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['installation_id', 'source_id'];

    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    public function getInstallationId(): int
    {
        return (int)($this->getData(self::schema_fields_INSTALLATION_ID) ?? 0);
    }

    public function setInstallationId(int $installationId): self
    {
        return $this->setData(self::schema_fields_INSTALLATION_ID, $installationId);
    }

    public function getSourceId(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_ID) ?? '');
    }

    public function setSourceId(string $sourceId): self
    {
        return $this->setData(self::schema_fields_SOURCE_ID, $sourceId);
    }

    public function setAccessMode(string $accessMode): self
    {
        return $this->setData(self::schema_fields_ACCESS_MODE, $accessMode);
    }

    public function setScopeGroup(string $scopeGroup): self
    {
        return $this->setData(self::schema_fields_SCOPE_GROUP, $scopeGroup);
    }

    public function save_before()
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        parent::save_before();
    }
}
