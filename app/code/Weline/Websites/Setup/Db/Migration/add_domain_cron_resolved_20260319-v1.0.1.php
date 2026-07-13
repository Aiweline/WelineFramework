<?php

declare(strict_types=1);

namespace Weline\Websites\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;

/**
 * 为 weline_websites_domain 补全 cron_resolved / cron_resolved_at（旧库未跑模型同步时缺失会导致健康检查 SQL 报错）
 */
class AddDomainCronResolved20260319V101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domain 表增加 cron_resolved、cron_resolved_at 字段（建站锁定）';
    }

    public function getVersion(): string
    {
        return '1.0.1';
    }

    public function getDate(): string
    {
        return '2026-03-19';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [Domain::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(Domain::class)->getTable();
        $hasField = $this->columnExistsFn($connection);

        $alter = $connection->alterTable()->forTable($table, Domain::schema_primary_key, '');
        $changed = false;

        if (!$hasField($table, Domain::schema_fields_CRON_RESOLVED)) {
            $alter->addColumn(
                Domain::schema_fields_CRON_RESOLVED,
                '',
                TableInterface::column_type_SMALLINT,
                1,
                'NOT NULL DEFAULT 0',
                '1=默认可建站子域已全部就绪，非证书类定时任务不再处理该根域'
            );
            $changed = true;
        }
        if (!$hasField($table, Domain::schema_fields_CRON_RESOLVED_AT)) {
            $alter->addColumn(
                Domain::schema_fields_CRON_RESOLVED_AT,
                '',
                TableInterface::column_type_DATETIME,
                0,
                'NULL',
                'cron_resolved 置位时间'
            );
            $changed = true;
        }

        if ($changed) {
            $alter->alter();
        }

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(Domain::class)->getTable();
        $hasField = $this->columnExistsFn($connection);

        $alter = $connection->alterTable()->forTable($table, Domain::schema_primary_key, '');
        $drop = false;
        if ($hasField($table, Domain::schema_fields_CRON_RESOLVED_AT)) {
            $alter->deleteColumn(Domain::schema_fields_CRON_RESOLVED_AT);
            $drop = true;
        }
        if ($hasField($table, Domain::schema_fields_CRON_RESOLVED)) {
            $alter->deleteColumn(Domain::schema_fields_CRON_RESOLVED);
            $drop = true;
        }
        if ($drop) {
            $alter->alter();
        }

        return true;
    }

    /** @return callable(string,string):bool */
    private function columnExistsFn(object $connection): callable
    {
        return function (string $t, string $f) use ($connection): bool {
            if (\method_exists($connection, 'hasField')) {
                return $connection->hasField($t, $f);
            }
            foreach ($connection->getTableColumns($t) as $col) {
                $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                if (\strcasecmp((string) $name, $f) === 0) {
                    return true;
                }
            }

            return false;
        };
    }
}
