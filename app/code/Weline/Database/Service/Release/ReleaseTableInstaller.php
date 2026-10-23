<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\DataTransformProgress;
use Weline\Database\Model\Release\SchemaChange;
use Weline\Database\Model\Release\SchemaCheckpoint;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Model\Release\SchemaPhysicalState;
use Weline\Database\Model\Release\SchemaRelease;
use Weline\Database\Model\Release\SchemaScopeRecord;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Model\Release\TableOwner;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\ForeignKeyDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * Creates the RSSE core tables (weline_schema_*) from declarative model annotations.
 *
 * Only declarative primitives are used: SchemaParser reads #[Table]/#[Col]/#[Index],
 * the connector creates the physical table. No legacy migration domain is involved.
 */
final class ReleaseTableInstaller
{
    /** @var list<class-string> */
    public const MODELS = [
        SchemaRelease::class,
        SchemaCheckpoint::class,
        SchemaOperationEvent::class,
        SchemaChange::class,
        SchemaScopeRecord::class,
        TableOwner::class,
        DataTransformProgress::class,
        BackupSet::class,
        BackupObject::class,
        SchemaSnapshot::class,
        SchemaPhysicalState::class,
    ];

    public function __construct(
        private readonly SchemaParser $schemaParser,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return array{created: list<string>, existing: list<string>}
     */
    public function install(): array
    {
        $connector = $this->connectionFactory->getConnector();
        $created = [];
        $existing = [];
        foreach (self::MODELS as $modelClass) {
            $tableName = $modelClass::schema_table;
            if ($connector->tableExist($tableName)) {
                $existing[] = $tableName;
                continue;
            }
            $schema = $this->schemaParser->parse($modelClass);
            if ($schema === null) {
                throw new \RuntimeException('Release model has no declarative schema: ' . $modelClass);
            }
            $connector->createTableFromSchema($tableName, $this->buildSchemaPayload($schema));
            $created[] = $tableName;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * @return array{comment: string, columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreignKeys: list<array<string, mixed>>}
     */
    private function buildSchemaPayload(TableSchema $schema): array
    {
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[] = $this->buildColumn($column);
        }

        $indexes = [];
        foreach ($schema->indexes as $index) {
            $indexes[] = $this->buildIndex($index);
        }

        $foreignKeys = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            $foreignKeys[] = $this->buildForeignKey($foreignKey);
        }

        return [
            'comment' => $schema->comment,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildColumn(ColumnDefinition $column): array
    {
        return [
            'name' => $column->name,
            'type' => $column->type,
            'length' => $column->length,
            'nullable' => $column->nullable,
            'primaryKey' => $column->primaryKey,
            'autoIncrement' => $column->autoIncrement,
            'default' => $column->default,
            'comment' => $column->comment,
            'unique' => $column->primaryKey ? $column->unique : false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIndex(IndexDefinition $index): array
    {
        return [
            'name' => $index->name,
            'columns' => $index->columns,
            'type' => $index->type,
            'method' => $index->method,
            'comment' => $index->comment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForeignKey(ForeignKeyDefinition $foreignKey): array
    {
        return [
            'name' => $foreignKey->name,
            'columns' => $foreignKey->columns,
            'referencesTable' => $foreignKey->referencesTable,
            'referencesColumns' => $foreignKey->referencesColumns,
            'onDeleteCascade' => $foreignKey->onDeleteCascade,
            'onUpdateCascade' => $foreignKey->onUpdateCascade,
        ];
    }
}
