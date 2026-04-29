<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Database\Model\DatabaseAdminAuditLog;

class AuditLogService
{
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
        $limit = max(1, min(500, $limit));
        return $this->auditLog->reset()
            ->order(DatabaseAdminAuditLog::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
    }
}
