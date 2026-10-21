<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Model\SchemaIndexState;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaIndexChangeSinkInterface;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Manager\ObjectManager;

/**
 * Records non-constraint index changes instead of executing their DDL during setup.
 *
 * Deferred rules (P1):
 * - only ADD_INDEX / DROP_INDEX on an existing table;
 * - no UNIQUE (PK/constraint correctness stays synchronous);
 * - no column/table/foreign-key op for the same table in the same diff;
 * - add+drop of the same index is coalesced into one rebuild job.
 */
final class SchemaIndexChangeSink implements SchemaIndexChangeSinkInterface
{
    private bool $tablesEnsured = false;

    public function __construct(
        private readonly SchemaParser $schemaParser,
        private readonly SchemaMigrationExecutor $schemaMigrationExecutor,
        private readonly SchemaIndexConfig $config,
    ) {
    }

    public function handleIndexOp(
        ConnectorInterface $connector,
        SchemaDiffOp $op,
        array $tableOps,
        array $context,
    ): bool {
        $index = $op->payload;
        if (!$index instanceof IndexDefinition) {
            return false;
        }

        $tableExists = $connector->tableExist($op->tableName);
        if (!$this->shouldDefer($tableOps, $op, $tableExists)) {
            return false;
        }

        $this->ensureTables($connector);

        [$action, $newDefinition, $oldDefinition] = $this->resolveChange($tableOps, $op, $index);
        if ($action === null) {
            return false;
        }

        $definition = $newDefinition ?? $oldDefinition;
        if ($definition === null) {
            return false;
        }

        $definitionJson = $this->definitionJson($definition);
        $oldDefinitionJson = $oldDefinition !== null ? $this->definitionJson($oldDefinition) : null;
        $definitionHash = hash('sha256', $definitionJson);
        $oldDefinitionHash = $oldDefinitionJson !== null ? hash('sha256', $oldDefinitionJson) : null;
        $module = $this->moduleName($op->modelClass);
        $changeSetId = $this->changeSetId($context, $op->tableName);
        $physicalName = IndexDefinitionContract::physicalIdentity($connector, $op->tableName, $definition->name);
        $jobId = $this->recordJob(
            $module,
            $op->tableName,
            $definition->name,
            $action,
            $definitionJson,
            $definitionHash,
            $oldDefinitionJson,
            $oldDefinitionHash,
            $physicalName,
            $changeSetId,
        );
        $this->upsertState(
            $module,
            $op->tableName,
            $definition->name,
            $definitionJson,
            $definitionHash,
            $physicalName,
            $jobId,
        );

        return true;
    }

    /**
     * Pure decision helper (no DB access) so the policy is unit-testable.
     *
     * @param list<SchemaDiffOp> $tableOps
     */
    public function shouldDefer(array $tableOps, SchemaDiffOp $op, bool $tableExists): bool
    {
        if (!$this->config->deferOnUpgrade()) {
            return false;
        }
        if (!in_array($op->kind, [SchemaDiffOp::KIND_ADD_INDEX, SchemaDiffOp::KIND_DROP_INDEX], true)) {
            return false;
        }
        if (!$op->payload instanceof IndexDefinition) {
            return false;
        }
        if (in_array(strtoupper($op->payload->type), ['UNIQUE', 'PRIMARY', 'PRIMARY KEY'], true)) {
            return false;
        }
        if ($op->kind === SchemaDiffOp::KIND_ADD_INDEX && !$tableExists) {
            return false;
        }

        $structuralKinds = [
            SchemaDiffOp::KIND_CREATE_TABLE,
            SchemaDiffOp::KIND_ADD_COLUMN,
            SchemaDiffOp::KIND_DROP_COLUMN,
            SchemaDiffOp::KIND_MODIFY_COLUMN,
            SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
            SchemaDiffOp::KIND_ADD_FOREIGN_KEY,
            SchemaDiffOp::KIND_DROP_FOREIGN_KEY,
        ];
        foreach ($tableOps as $candidate) {
            if (in_array($candidate->kind, $structuralKinds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<SchemaDiffOp> $tableOps
     * @return array{0:?string,1:?IndexDefinition,2:?IndexDefinition}
     */
    private function resolveChange(array $tableOps, SchemaDiffOp $op, IndexDefinition $index): array
    {
        $add = null;
        $drop = null;
        foreach ($tableOps as $candidate) {
            $candidateIndex = $candidate->payload;
            if (!$candidateIndex instanceof IndexDefinition || $candidateIndex->name !== $index->name) {
                continue;
            }
            if ($candidate->kind === SchemaDiffOp::KIND_ADD_INDEX) {
                $add = $candidateIndex;
            } elseif ($candidate->kind === SchemaDiffOp::KIND_DROP_INDEX) {
                $drop = $candidateIndex;
            }
        }

        if ($add !== null && $drop !== null) {
            return [SchemaIndexJob::ACTION_REBUILD, $add, $drop];
        }
        if ($op->kind === SchemaDiffOp::KIND_ADD_INDEX) {
            return [SchemaIndexJob::ACTION_CREATE, $index, null];
        }
        if ($op->kind === SchemaDiffOp::KIND_DROP_INDEX) {
            return [SchemaIndexJob::ACTION_DROP, null, $index];
        }

        return [null, null, null];
    }

    private function ensureTables(ConnectorInterface $connector): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        foreach ([SchemaIndexJob::class, SchemaIndexState::class] as $modelClass) {
            /** @var \Weline\Framework\Database\AbstractModel $model */
            $model = ObjectManager::getInstance($modelClass);
            if ($connector->tableExist($model->getTable())) {
                continue;
            }
            $schema = $this->schemaParser->parse($modelClass);
            if ($schema === null) {
                throw new \RuntimeException((string)__(
                    '异步索引任务表声明不可解析：%{1}',
                    [$modelClass],
                ));
            }
            $this->schemaMigrationExecutor->createBootstrapTable($connector, $schema);
        }

        $this->tablesEnsured = true;
    }

    private function recordJob(
        string $module,
        string $tableName,
        string $indexName,
        string $action,
        string $definitionJson,
        string $definitionHash,
        ?string $oldDefinitionJson,
        ?string $oldDefinitionHash,
        string $physicalName,
        string $changeSetId,
    ): int {
        $model = ObjectManager::getInstance(SchemaIndexJob::class);
        $identity = [
            SchemaIndexJob::schema_fields_TABLE_NAME => $tableName,
            SchemaIndexJob::schema_fields_INDEX_NAME => $indexName,
            SchemaIndexJob::schema_fields_ACTION => $action,
            SchemaIndexJob::schema_fields_DEFINITION_HASH => $definitionHash,
        ];
        $row = $model->clear()->reset();
        foreach ($identity as $field => $value) {
            $row->where($field, $value);
        }
        $existing = $row->find()->fetch();
        $now = date('Y-m-d H:i:s');
        $data = $identity + [
            SchemaIndexJob::schema_fields_CHANGE_SET_ID => $changeSetId,
            SchemaIndexJob::schema_fields_MODULE => $module,
            SchemaIndexJob::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexJob::schema_fields_OLD_DEFINITION_JSON => $oldDefinitionJson,
            SchemaIndexJob::schema_fields_OLD_DEFINITION_HASH => $oldDefinitionHash,
            SchemaIndexJob::schema_fields_PHYSICAL_NAME => $physicalName,
            SchemaIndexJob::schema_fields_STATUS => SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::schema_fields_PRIORITY => 100,
            SchemaIndexJob::schema_fields_ATTEMPTS => 0,
            SchemaIndexJob::schema_fields_LAST_ERROR => null,
            SchemaIndexJob::schema_fields_UPDATE_TIME => $now,
        ];

        if ($existing->getId()) {
            $jobId = (int)$existing->getId();
            $model->clear()->reset()
                ->where(SchemaIndexJob::schema_fields_ID, $jobId)
                ->update($data)
                ->fetch();

            return $jobId;
        }

        $data[SchemaIndexJob::schema_fields_CREATE_TIME] = $now;
        $model->clear()->reset()->insert($data, SchemaIndexJob::schema_fields_ID)->fetch();

        $row = $model->clear()->reset();
        foreach ($identity as $field => $value) {
            $row->where($field, $value);
        }
        $created = $row->find()->fetch();

        return (int)$created->getId();
    }

    private function upsertState(
        string $module,
        string $tableName,
        string $indexName,
        string $definitionJson,
        string $definitionHash,
        string $physicalName,
        int $jobId,
    ): void {
        $model = ObjectManager::getInstance(SchemaIndexState::class);
        $existing = $model->clear()->reset()
            ->where(SchemaIndexState::schema_fields_TABLE_NAME, $tableName)
            ->where(SchemaIndexState::schema_fields_INDEX_NAME, $indexName)
            ->find()
            ->fetch();
        $now = date('Y-m-d H:i:s');
        $data = [
            SchemaIndexState::schema_fields_MODULE => $module,
            SchemaIndexState::schema_fields_TABLE_NAME => $tableName,
            SchemaIndexState::schema_fields_INDEX_NAME => $indexName,
            SchemaIndexState::schema_fields_DEFINITION_JSON => $definitionJson,
            SchemaIndexState::schema_fields_DEFINITION_HASH => $definitionHash,
            SchemaIndexState::schema_fields_PHYSICAL_NAME => $physicalName,
            SchemaIndexState::schema_fields_STATUS => SchemaIndexState::STATUS_PENDING,
            SchemaIndexState::schema_fields_LAST_JOB_ID => $jobId,
            SchemaIndexState::schema_fields_LAST_ERROR => null,
            SchemaIndexState::schema_fields_UPDATE_TIME => $now,
        ];

        if ($existing->getId()) {
            $model->clear()->reset()
                ->where(SchemaIndexState::schema_fields_ID, (int)$existing->getId())
                ->update($data)
                ->fetch();

            return;
        }

        $data[SchemaIndexState::schema_fields_CREATE_TIME] = $now;
        $model->clear()->reset()->insert($data, SchemaIndexState::schema_fields_ID)->fetch();
    }

    private function definitionJson(IndexDefinition $index): string
    {
        $json = json_encode([
            'name' => $index->name,
            'columns' => $index->columns,
            'type' => $index->type,
            'method' => $index->method,
            'comment' => $index->comment,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException((string)__('索引定义 JSON 编码失败：%{1}', [$index->name]));
        }

        return $json;
    }

    private function moduleName(?string $modelClass): string
    {
        $modelClass = trim((string)$modelClass);
        if ($modelClass === '') {
            return '';
        }
        $parts = explode('\\', $modelClass);

        return (string)($parts[0] ?? '') . (isset($parts[1]) && $parts[1] !== '' ? '_' . $parts[1] : '');
    }

    /** @param array<string,mixed> $context */
    private function changeSetId(array $context, string $tableName): string
    {
        return substr(hash('sha256', (string)($context['operation_id'] ?? '') . '|' . $tableName), 0, 32);
    }
}
