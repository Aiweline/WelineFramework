<?php
declare(strict_types=1);

namespace Weline\Multipass\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: 'Multipass account binding')]
#[Index(name: 'idx_multipass_binding_app_customer', columns: ['app_id', 'local_customer_id'], type: 'UNIQUE', comment: 'App customer binding')]
#[Index(name: 'idx_multipass_binding_external', columns: ['app_id', 'external_subject_id'], comment: 'External subject')]
#[Index(name: 'idx_multipass_binding_status', columns: ['status'], comment: 'Status')]
class AccountBinding extends Model
{
    public const schema_table = 'multipass_account_binding';
    public const schema_primary_key = 'binding_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'binding_id';
    #[Col(type: 'int', nullable: false, comment: 'Trusted app ID')]
    public const schema_fields_APP_ID = 'app_id';
    #[Col(type: 'int', nullable: false, comment: 'Local customer ID')]
    public const schema_fields_LOCAL_CUSTOMER_ID = 'local_customer_id';
    #[Col(type: 'varchar', length: 127, nullable: false, default: '', comment: 'External subject ID')]
    public const schema_fields_EXTERNAL_SUBJECT_ID = 'external_subject_id';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'External display name')]
    public const schema_fields_EXTERNAL_DISPLAY_NAME = 'external_display_name';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'active', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_METADATA = 'metadata';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Last authorized at')]
    public const schema_fields_LAST_AUTHORIZED_AT = 'last_authorized_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['app_id', 'local_customer_id', 'external_subject_id', 'status'];

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Multipass account binding')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Binding ID')
            ->addColumn(self::schema_fields_APP_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Trusted app ID')
            ->addColumn(self::schema_fields_LOCAL_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Local customer ID')
            ->addColumn(self::schema_fields_EXTERNAL_SUBJECT_ID, TableInterface::column_type_VARCHAR, 127, "not null default ''", 'External subject ID')
            ->addColumn(self::schema_fields_EXTERNAL_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'External display name')
            ->addColumn(self::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 32, "not null default 'active'", 'Status')
            ->addColumn(self::schema_fields_METADATA, TableInterface::column_type_TEXT, null, 'default null', 'Metadata JSON')
            ->addColumn(self::schema_fields_LAST_AUTHORIZED_AT, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Last authorized at')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addColumn(self::schema_fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Updated at')
            ->addIndex(TableInterface::index_type_UNIQUE, 'idx_multipass_binding_app_customer', [self::schema_fields_APP_ID, self::schema_fields_LOCAL_CUSTOMER_ID], 'App customer binding')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_binding_external', [self::schema_fields_APP_ID, self::schema_fields_EXTERNAL_SUBJECT_ID], 'External subject')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_binding_status', [self::schema_fields_STATUS], 'Status')
            ->create();
    }

    public function getAppId(): int
    {
        return (int) ($this->getData(self::schema_fields_APP_ID) ?? 0);
    }

    public function setAppId(int $appId): self
    {
        return $this->setData(self::schema_fields_APP_ID, $appId);
    }

    public function getLocalCustomerId(): int
    {
        return (int) ($this->getData(self::schema_fields_LOCAL_CUSTOMER_ID) ?? 0);
    }

    public function setLocalCustomerId(int $customerId): self
    {
        return $this->setData(self::schema_fields_LOCAL_CUSTOMER_ID, $customerId);
    }

    public function getExternalSubjectId(): string
    {
        return (string) ($this->getData(self::schema_fields_EXTERNAL_SUBJECT_ID) ?? '');
    }

    public function setExternalSubjectId(string $externalSubjectId): self
    {
        return $this->setData(self::schema_fields_EXTERNAL_SUBJECT_ID, trim($externalSubjectId));
    }

    public function setExternalDisplayName(string $displayName): self
    {
        return $this->setData(self::schema_fields_EXTERNAL_DISPLAY_NAME, trim($displayName));
    }

    public function getExternalDisplayName(): string
    {
        return (string) ($this->getData(self::schema_fields_EXTERNAL_DISPLAY_NAME) ?? '');
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_STATUS) ?? self::STATUS_ACTIVE);
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

    public function setMetadata(array $metadata): self
    {
        return $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE));
    }

    public function getMetadata(): array
    {
        $raw = (string) ($this->getData(self::schema_fields_METADATA) ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    public function getLastAuthorizedAt(): int
    {
        return (int) ($this->getData(self::schema_fields_LAST_AUTHORIZED_AT) ?? 0);
    }

    public function setLastAuthorizedAt(int $timestamp): self
    {
        return $this->setData(self::schema_fields_LAST_AUTHORIZED_AT, $timestamp);
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
