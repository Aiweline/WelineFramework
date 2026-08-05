<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\UsageAuditLegacyBackfill;
use Weline\Framework\Database\Migration\AbstractMigration;

final class ProviderUsageAuditIdentity20260730V111 extends AbstractMigration
{
    public function __construct(
        private readonly UsageAuditLegacyBackfill $legacyBackfill,
    ) {
    }

    public function getDescription(): string
    {
        return 'Backfill provider usage audit request identity and historical balance markers';
    }

    public function getVersion(): string
    {
        return '1.1.1';
    }

    public function getDate(): string
    {
        return '2026-07-30';
    }

    public function getType(): string
    {
        return 'data_migration';
    }

    public function getAffectedTables(): array
    {
        return [UsageRecord::schema_table];
    }

    public function requiresBackup(): bool
    {
        return true;
    }

    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'column',
            'tables' => [UsageRecord::schema_table],
            'columns' => [
                UsageRecord::schema_fields_REQUEST_KEY,
                UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS,
                UsageRecord::schema_fields_BALANCE_APPLIED,
            ],
            'reason' => 'The migration classifies historical request identities and marks prior debits.',
        ];
    }

    public function install(): bool
    {
        $this->legacyBackfill->backfill();

        return true;
    }

    public function uninstall(): bool
    {
        // Migration rollback is owned by the framework backup/restore plan.
        return true;
    }
}
