<?php

declare(strict_types=1);

namespace Weline\Websites\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;

/**
 * 为 weline_websites_domain 增加 dns_zone_external_id（Cloudflare 等 Zone 外部 ID 持久化）
 */
class AddDomainDnsZoneExternalId20260320V102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domain 表增加 dns_zone_external_id（DNS Zone 外部 ID，减少反复查询 /zones）';
    }

    public function getVersion(): string
    {
        return '1.0.2';
    }

    public function getDate(): string
    {
        return '2026-03-20';
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

        if ($hasField($table, Domain::schema_fields_DNS_ZONE_EXTERNAL_ID)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, Domain::schema_primary_key, '');
        $alter->addColumn(
            Domain::schema_fields_DNS_ZONE_EXTERNAL_ID,
            '',
            TableInterface::column_type_VARCHAR,
            64,
            "NOT NULL DEFAULT ''",
            'DNS 托管 Zone 外部 ID（如 CF zone_id）'
        );
        $alter->alter();

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(Domain::class)->getTable();
        $hasField = $this->columnExistsFn($connection);

        if (!$hasField($table, Domain::schema_fields_DNS_ZONE_EXTERNAL_ID)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, Domain::schema_primary_key, '');
        $alter->deleteColumn(Domain::schema_fields_DNS_ZONE_EXTERNAL_ID);
        $alter->alter();

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
