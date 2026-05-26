<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Database\Model\DatabaseAdminAuditLog;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;

class AuditLogService
{
    private bool $auditTableReady = false;
    private ?ModelSetup $modelSetup = null;

    public function __construct(
        private readonly DatabaseAdminAuditLog $auditLog
    ) {
    }

    public function log(
        string $action,
        string $database,
        string $table,
        string $sql,
        array $payload,
        int $affectedRows,
        string $status,
        string $message,
        int|string|null $userId,
        ?string $username,
        string $clientIp
    ): void {
        $this->ensureAuditTable();
        $this->auditLog->reset()->setData([
            DatabaseAdminAuditLog::schema_fields_ACTION => $action,
            DatabaseAdminAuditLog::schema_fields_DATABASE => $database,
            DatabaseAdminAuditLog::schema_fields_TABLE => $table,
            DatabaseAdminAuditLog::schema_fields_SQL => $sql,
            DatabaseAdminAuditLog::schema_fields_PAYLOAD => json_encode($payload, JSON_UNESCAPED_UNICODE),
            DatabaseAdminAuditLog::schema_fields_AFFECTED_ROWS => $affectedRows,
            DatabaseAdminAuditLog::schema_fields_STATUS => $status,
            DatabaseAdminAuditLog::schema_fields_MESSAGE => $message,
            DatabaseAdminAuditLog::schema_fields_USER_ID => (string) ($userId ?? ''),
            DatabaseAdminAuditLog::schema_fields_USERNAME => (string) ($username ?? ''),
            DatabaseAdminAuditLog::schema_fields_CLIENT_IP => $clientIp,
            DatabaseAdminAuditLog::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    public function latest(int $limit = 100): array
    {
        $this->ensureAuditTable();
        $limit = max(1, min(500, $limit));
        return $this->auditLog->reset()
            ->order(DatabaseAdminAuditLog::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    private function ensureAuditTable(): void
    {
        if ($this->auditTableReady) {
            return;
        }

        $modelSetup = $this->getModelSetup();
        $modelSetup->putModel($this->auditLog);
        if (!$modelSetup->tableExist()) {
            $modelSetup->createTable('Database Admin Audit Logs')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Log ID')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_ACTION, TableInterface::column_type_VARCHAR, 64, 'not null', 'Action')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_DATABASE, TableInterface::column_type_VARCHAR, 128, 'default null', 'Database Name')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_TABLE, TableInterface::column_type_VARCHAR, 128, 'default null', 'Table Name')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_SQL, TableInterface::column_type_TEXT, null, 'default null', 'SQL Text')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_PAYLOAD, TableInterface::column_type_TEXT, null, 'default null', 'Payload JSON')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_AFFECTED_ROWS, TableInterface::column_type_INTEGER, null, 'default 0', 'Affected Rows')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 32, 'not null default \'success\'', 'Status')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_MESSAGE, TableInterface::column_type_TEXT, null, 'default null', 'Message')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_USER_ID, TableInterface::column_type_VARCHAR, 64, 'default null', 'User ID')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_USERNAME, TableInterface::column_type_VARCHAR, 128, 'default null', 'Username')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_CLIENT_IP, TableInterface::column_type_VARCHAR, 64, 'default null', 'Client IP')
                ->addColumn(DatabaseAdminAuditLog::schema_fields_CREATED_AT, TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
                ->addIndex(TableInterface::index_type_KEY, 'idx_database_admin_audit_action', DatabaseAdminAuditLog::schema_fields_ACTION, 'Action lookup')
                ->addIndex(TableInterface::index_type_KEY, 'idx_database_admin_audit_created_at', DatabaseAdminAuditLog::schema_fields_CREATED_AT, 'Created time lookup')
                ->create();
        }

        $this->auditTableReady = true;
    }

    private function getModelSetup(): ModelSetup
    {
        if ($this->modelSetup === null) {
            $this->modelSetup = ObjectManager::getInstance(ModelSetup::class);
        }
        return $this->modelSetup;
    }
}
