<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Setup\Db\Migration;

use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AddBackendActivityBusinessContext20260702V111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add business context columns to backend activity logs.';
    }

    public function getVersion(): string
    {
        return '1.1.1';
    }

    public function getDate(): string
    {
        return '2026-07-02';
    }

    /**
     * @return array<int,string>
     */
    public function getAffectedTables(): array
    {
        return [BackendActivityLog::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $activityLog = ObjectManager::getInstance(BackendActivityLog::class);
        $table = $activityLog->getTable();

        foreach ($this->columns() as $column) {
            if (!$this->columnExists($connection, $table, (string)$column['name'])) {
                $connection->query($connection->buildAlterAddColumnSql($table, $column))->fetch();
            }
        }

        foreach ($this->indexes() as $name => $columns) {
            if (!$connection->hasIndex($table, $name)) {
                $connection->query($connection->buildAddIndexSql($table, [
                    'name' => $name,
                    'columns' => $columns,
                    'type' => TableInterface::index_type_KEY,
                ]))->fetch();
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function columns(): array
    {
        return [
            [
                'name' => BackendActivityLog::schema_fields_business_module,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 100,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business module',
            ],
            [
                'name' => BackendActivityLog::schema_fields_business_entity_type,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 100,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business entity type',
            ],
            [
                'name' => BackendActivityLog::schema_fields_business_entity_id,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 64,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business entity ID',
            ],
            [
                'name' => BackendActivityLog::schema_fields_business_action,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 80,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business action',
            ],
            [
                'name' => BackendActivityLog::schema_fields_business_title,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 255,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business title',
            ],
            [
                'name' => BackendActivityLog::schema_fields_business_payload,
                'type' => TableInterface::column_type_TEXT,
                'nullable' => true,
                'default' => null,
                'comment' => 'Business payload JSON',
            ],
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    private function indexes(): array
    {
        return [
            'idx_business_entity' => [
                BackendActivityLog::schema_fields_business_module,
                BackendActivityLog::schema_fields_business_entity_type,
                BackendActivityLog::schema_fields_business_entity_id,
            ],
            'idx_business_action' => [
                BackendActivityLog::schema_fields_business_module,
                BackendActivityLog::schema_fields_business_action,
            ],
        ];
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return $connection->hasField($table, $field);
        }

        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? $column['name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
