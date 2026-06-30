<?php
declare(strict_types=1);

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'API app installation')]
#[Index(name: 'idx_w_api_app_install_app_subject', columns: ['app_id', 'subject_type', 'subject_id'], type: 'UNIQUE', comment: 'App subject')]
#[Index(name: 'idx_w_api_app_install_status', columns: ['status'], comment: 'Status')]
class ApiAppInstallation extends Model
{
    public const schema_table = 'm_api_app_installation';
    public const schema_primary_key = 'installation_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Installation ID')]
    public const schema_fields_ID = 'installation_id';
    #[Col(type: 'int', nullable: false, comment: 'App ID')]
    public const schema_fields_APP_ID = 'app_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Subject type')]
    public const schema_fields_SUBJECT_TYPE = 'subject_type';
    #[Col(type: 'varchar', length: 127, nullable: false, comment: 'Subject ID')]
    public const schema_fields_SUBJECT_ID = 'subject_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'active', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['installation_id', 'app_id', 'subject_type', 'subject_id'];

    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    public function getAppId(): int
    {
        return (int)($this->getData(self::schema_fields_APP_ID) ?? 0);
    }

    public function setAppId(int $appId): self
    {
        return $this->setData(self::schema_fields_APP_ID, $appId);
    }

    public function getSubjectType(): string
    {
        return (string)($this->getData(self::schema_fields_SUBJECT_TYPE) ?? '');
    }

    public function setSubjectType(string $subjectType): self
    {
        return $this->setData(self::schema_fields_SUBJECT_TYPE, trim($subjectType));
    }

    public function getSubjectId(): string
    {
        return (string)($this->getData(self::schema_fields_SUBJECT_ID) ?? '');
    }

    public function setSubjectId(string $subjectId): self
    {
        return $this->setData(self::schema_fields_SUBJECT_ID, trim($subjectId));
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?? self::STATUS_ACTIVE);
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_REVOKED, self::STATUS_DISABLED], true)) {
            $status = self::STATUS_DISABLED;
        }
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function save_before()
    {
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        parent::save_before();
    }
}
