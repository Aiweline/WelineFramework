<?php

declare(strict_types=1);

namespace Weline\Theme\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayoutVersion;

class AddThemeLayoutVersionIdentity20260701V105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target-aware identity columns to theme layout versions.';
    }

    public function getVersion(): string
    {
        return '1.0.5';
    }

    public function getDate(): string
    {
        return '2026-07-01';
    }

    /**
     * @return array<int,string>
     */
    public function getAffectedTables(): array
    {
        return [ThemeLayoutVersion::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $version = ObjectManager::getInstance(ThemeLayoutVersion::class);
        $table = $version->getTable();
        $columns = [
            [
                'name' => ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 100,
                'nullable' => false,
                'default' => 'default',
                'comment' => 'Layout option',
            ],
            [
                'name' => ThemeLayoutVersion::schema_fields_SCOPE,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 120,
                'nullable' => false,
                'default' => 'default',
                'comment' => 'Scope path',
            ],
            [
                'name' => ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 50,
                'nullable' => false,
                'default' => 'global',
                'comment' => 'Layout target type',
            ],
            [
                'name' => ThemeLayoutVersion::schema_fields_TARGET_ID,
                'type' => TableInterface::column_type_INTEGER,
                'length' => 11,
                'nullable' => false,
                'default' => 0,
                'comment' => 'Layout target ID',
            ],
        ];

        foreach ($columns as $column) {
            if (!$this->columnExists($connection, $table, (string)$column['name'])) {
                $connection->query($connection->buildAlterAddColumnSql($table, $column))->fetch();
            }
        }

        $indexes = [
            'idx_theme_page_identity' => [
                ThemeLayoutVersion::schema_fields_THEME_ID,
                ThemeLayoutVersion::schema_fields_PAGE_TYPE,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                ThemeLayoutVersion::schema_fields_SCOPE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
            ],
            'idx_version_identity_number' => [
                ThemeLayoutVersion::schema_fields_THEME_ID,
                ThemeLayoutVersion::schema_fields_PAGE_TYPE,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                ThemeLayoutVersion::schema_fields_SCOPE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
                ThemeLayoutVersion::schema_fields_VERSION_NUMBER,
            ],
            'idx_current_identity' => [
                ThemeLayoutVersion::schema_fields_THEME_ID,
                ThemeLayoutVersion::schema_fields_PAGE_TYPE,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                ThemeLayoutVersion::schema_fields_SCOPE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
                ThemeLayoutVersion::schema_fields_IS_CURRENT,
            ],
            'idx_published_identity' => [
                ThemeLayoutVersion::schema_fields_THEME_ID,
                ThemeLayoutVersion::schema_fields_PAGE_TYPE,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                ThemeLayoutVersion::schema_fields_SCOPE,
                ThemeLayoutVersion::schema_fields_TARGET_TYPE,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
                ThemeLayoutVersion::schema_fields_IS_PUBLISHED,
            ],
        ];

        foreach ($indexes as $name => $columns) {
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
