<?php

declare(strict_types=1);

namespace Weline\DataTable\Service;

use Weline\DataTable\Helper\TransactionManager;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;

class DemoTableService
{
    private const CONFIG_CACHE_TTL = 86400;

    private const ALLOWED_MODELS = [
        'Weline\DataTable\Model\TestUser',
        'Weline\DataTable\Model\TestProduct',
        'Weline\DataTable\Model\TestOrder',
    ];

    /**
     * @return array{users:int,products:int,orders:int}
     * @throws \Throwable
     */
    public function initDemoData(): array
    {
        $this->clearDemoData();

        $users = $this->seedModel('Weline\DataTable\Model\TestUser');
        $products = $this->seedModel('Weline\DataTable\Model\TestProduct');
        $orders = $this->seedModel('Weline\DataTable\Model\TestOrder');

        return [
            'users' => $users,
            'products' => $products,
            'orders' => $orders,
        ];
    }

    /**
     * @return array{users:int,products:int,orders:int}
     */
    public function clearDemoData(): array
    {
        return [
            'orders' => $this->truncateModel('Weline\DataTable\Model\TestOrder'),
            'products' => $this->truncateModel('Weline\DataTable\Model\TestProduct'),
            'users' => $this->truncateModel('Weline\DataTable\Model\TestUser'),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function getTableData(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['pageSize'] ?? $params['limit'] ?? 20)));
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $sorts = is_array($params['sorts'] ?? null) ? $params['sorts'] : (is_array($params['sort'] ?? null) ? $params['sort'] : []);
        $search = trim((string)($params['search'] ?? ''));
        $modelConfig = $this->normalizeModelConfig($model, $params['model_config'] ?? []);
        $joinConfig = $this->parseJoinConfig((string)($params['join'] ?? ''));
        $sorts = $this->normalizeSorts($sorts, $modelConfig);

        $rows = count($modelConfig['models']) > 1
            ? $this->loadJoinedRows($modelConfig, $joinConfig)
            : $this->loadSingleModelRows($modelConfig['main_model']);

        $rows = $this->applySearch($rows, $search);
        $rows = $this->applyFilters($rows, $filters);
        $rows = $this->applySorts($rows, $sorts);

        return $this->paginateRows($rows, $page, $pageSize);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function getTableFields(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $scope = (string)($params['scope'] ?? '');
        $tableId = (string)($params['table_id'] ?? '');
        $modelConfig = $this->normalizeModelConfig($model, $params['model_config'] ?? []);

        $allFields = count($modelConfig['models']) > 1
            ? $this->buildMultiModelFields($modelConfig)
            : $this->buildModelFields($modelConfig['main_model']);

        $displayFields = $this->defaultDisplayFields($allFields);
        $filterFields = $this->defaultFilterFields($allFields);

        $cacheConfig = $this->getConfigCache($scope, $tableId);

        return [
            'all_fields' => array_values($allFields),
            'display_fields' => array_values($displayFields),
            'filter_fields' => array_values($filterFields),
            'cached_display_fields' => $this->mergeCachedFields($allFields, $cacheConfig['display_fields'] ?? []),
            'cached_filter_fields' => $this->mergeCachedFields($allFields, $cacheConfig['filter_fields'] ?? []),
            'scope' => $scope,
            'table_id' => $tableId,
            'primary_key' => 'id',
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function getFormFields(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $excludeFields = $this->normalizeFieldList($params['exclude_fields'] ?? []);
        $includeFields = $this->normalizeFieldList($params['include_fields'] ?? []);
        $manualFields = $this->normalizeFieldList($params['manual_fields'] ?? []);
        $modelConfig = $this->normalizeModelConfig($model, $params['model_config'] ?? []);

        $fields = count($modelConfig['models']) > 1
            ? $this->buildMultiModelFields($modelConfig)
            : $this->buildModelFields($modelConfig['main_model']);

        $result = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '' || in_array($name, $manualFields, true)) {
                continue;
            }
            if ($excludeFields && in_array($name, $excludeFields, true)) {
                continue;
            }
            if ($includeFields && !in_array($name, $includeFields, true)) {
                continue;
            }
            $result[] = $field;
        }

        return [
            'fields' => array_values($result),
            'model' => $model,
            'scope' => (string)($params['scope'] ?? ''),
            'form_id' => (string)($params['form_id'] ?? ''),
            'total' => count($result),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function getRecord(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $recordId = $params['record_id'] ?? null;
        $modelConfig = $this->normalizeModelConfig($model, $params['model_config'] ?? []);

        if (count($modelConfig['models']) > 1) {
            throw new Exception(__('Demo form record loading only supports single-model records.'));
        }

        $record = $this->loadRecord($modelConfig['main_model'], $recordId);
        if (!$record) {
            throw new Exception(__('Record does not exist.'));
        }

        return ['record' => $record];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function createRecord(array $params): array
    {
        return $this->saveRecord($params, false);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function updateRecord(array $params): array
    {
        return $this->saveRecord($params, true);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function saveData(array $params): array
    {
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        if (!empty($data['id']) && empty($params['id'])) {
            $params['id'] = $data['id'];
        }

        return empty($params['id'])
            ? $this->createRecord($params)
            : $this->updateRecord($params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function deleteRecords(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $ids = $params['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $modelConfig = $this->normalizeModelConfig($model, []);
        if (count($modelConfig['models']) > 1) {
            throw new Exception(__('Joined demo tables do not support delete.'));
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $deleted += $this->deleteSingleRecord($modelConfig['main_model'], $id) ? 1 : 0;
        }

        return [
            'deleted' => $deleted,
            'ids' => array_values(array_filter($ids, static fn ($id) => $id !== null && $id !== '')),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    public function exportData(array $params): array
    {
        $model = (string)($params['model'] ?? '');
        $format = strtolower((string)($params['format'] ?? 'csv'));
        $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
        $fields = is_array($params['fields'] ?? null) ? $params['fields'] : [];
        $modelConfig = $this->normalizeModelConfig($model, []);

        if (count($modelConfig['models']) > 1) {
            throw new Exception(__('Demo export only supports single-model tables.'));
        }

        $rows = $this->loadSingleModelRows($modelConfig['main_model']);
        if ($ids) {
            $idLookup = array_map('strval', $ids);
            $rows = array_values(array_filter($rows, static function (array $row) use ($idLookup) {
                return in_array((string)($row['id'] ?? ''), $idLookup, true);
            }));
        }

        $selectedFields = $this->normalizeExportFields($fields, $modelConfig['main_model']);
        if ($format === 'json') {
            return [
                'content_type' => 'application/json; charset=utf-8',
                'filename' => 'datatable-demo-export.json',
                'body' => json_encode($this->mapRowsForExport($rows, $selectedFields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return [
            'content_type' => $format === 'excel'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=utf-8',
            'filename' => $format === 'excel' ? 'datatable-demo-export.xlsx' : 'datatable-demo-export.csv',
            'body' => $this->buildCsv($this->mapRowsForExport($rows, $selectedFields), $selectedFields),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function saveFieldConfig(array $params): array
    {
        $scope = (string)($params['scope'] ?? '');
        $tableId = (string)($params['table_id'] ?? '');
        $cacheKey = $this->getConfigCacheKey($scope, $tableId);
        w_cache('default')->set($cacheKey, [
            'display_fields' => is_array($params['display_fields'] ?? null) ? $params['display_fields'] : [],
            'filter_fields' => is_array($params['filter_fields'] ?? null) ? $params['filter_fields'] : [],
            'config' => is_array($params['config'] ?? null) ? $params['config'] : [],
            'updated_at' => date('Y-m-d H:i:s'),
        ], self::CONFIG_CACHE_TTL);

        return [
            'scope' => $scope,
            'table_id' => $tableId,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function clearFieldConfig(array $params): array
    {
        $scope = (string)($params['scope'] ?? '');
        $tableId = (string)($params['table_id'] ?? '');
        w_cache('default')->delete($this->getConfigCacheKey($scope, $tableId));

        return [
            'scope' => $scope,
            'table_id' => $tableId,
        ];
    }

    private function seedModel(string $modelClass): int
    {
        $this->assertAllowedModel($modelClass);

        /** @var Model $model */
        $model = ObjectManager::make($modelClass);
        $dataRows = method_exists($model, 'getTestData') ? (array)$model->getTestData() : [];
        $count = 0;

        foreach ($dataRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var Model $record */
            $record = ObjectManager::make($modelClass);
            $record->setData($this->normalizeRowForPersistence($row))->save();
            $count++;
        }

        $this->syncModelSequence($modelClass);

        return $count;
    }

    private function truncateModel(string $modelClass): int
    {
        $this->assertAllowedModel($modelClass);

        $rows = $this->loadSingleModelRows($modelClass);
        $deleted = 0;
        foreach ($rows as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $deleted += $this->deleteSingleRecord($modelClass, $row['id']) ? 1 : 0;
        }

        $this->syncModelSequence($modelClass);

        return $deleted;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws \Throwable
     */
    private function saveRecord(array $params, bool $isUpdate): array
    {
        $model = (string)($params['model'] ?? '');
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        $modelConfig = $this->normalizeModelConfig($model, $params['model_config'] ?? []);
        $dependencies = $this->parseDependencies((string)($params['dependencies'] ?? ''));
        $transaction = $this->toBool($params['transaction'] ?? false);

        $callback = function () use ($isUpdate, $modelConfig, $data, $params, $dependencies): array {
            if (count($modelConfig['models']) > 1) {
                return $this->saveMultiModelRecord($modelConfig, $data, $params, $dependencies, $isUpdate);
            }

            $id = $isUpdate ? ($params['id'] ?? $data['id'] ?? null) : null;
            $record = $this->persistSingleRecord($modelConfig['main_model'], $data, $id);
            return [
                'id' => $record['id'] ?? null,
                'record' => $record,
            ];
        };

        return $transaction
            ? TransactionManager::executeInTransaction($callback, 'datatable_demo_form')
            : $callback();
    }

    /**
     * @param array<string,mixed> $modelConfig
     * @param array<string,mixed> $data
     * @param array<string,mixed> $params
     * @param array<int,array<string,string>> $dependencies
     * @return array<string,mixed>
     * @throws \Throwable
     */
    private function saveMultiModelRecord(
        array $modelConfig,
        array $data,
        array $params,
        array $dependencies,
        bool $isUpdate
    ): array {
        $aliasData = [];
        foreach ($modelConfig['models'] as $alias => $modelClass) {
            $aliasData[$alias] = is_array($data[$alias] ?? null) ? $data[$alias] : [];
        }

        $order = $this->resolveDependencyOrder(array_keys($modelConfig['models']), $dependencies);
        $saved = [];

        foreach ($dependencies as $dependency) {
            $sourceAlias = $dependency['source_alias'];
            $targetAlias = $dependency['target_alias'];
            if (!empty($saved[$sourceAlias][$dependency['source_field']])) {
                $aliasData[$targetAlias][$dependency['target_field']] = $saved[$sourceAlias][$dependency['source_field']];
            }
        }

        foreach ($order as $alias) {
            $modelClass = $modelConfig['models'][$alias];
            $id = $isUpdate ? ($aliasData[$alias]['id'] ?? null) : ($aliasData[$alias]['id'] ?? null);
            $saved[$alias] = $this->persistSingleRecord($modelClass, $aliasData[$alias], $id);

            foreach ($dependencies as $dependency) {
                if ($dependency['source_alias'] !== $alias) {
                    continue;
                }

                $sourceField = $dependency['source_field'];
                $targetAlias = $dependency['target_alias'];
                $targetField = $dependency['target_field'];
                $aliasData[$targetAlias][$targetField] = $saved[$alias][$sourceField] ?? $saved[$alias]['id'] ?? null;
            }
        }

        return [
            'id' => $saved[$order[0]]['id'] ?? null,
            'record' => $saved,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws \Throwable
     */
    private function persistSingleRecord(string $modelClass, array $data, mixed $id = null): array
    {
        $this->assertAllowedModel($modelClass);

        /** @var Model $record */
        $record = $this->loadModelInstance($modelClass);
        if ($id === null || $id === '') {
            $this->syncModelSequence($modelClass);
        }
        if ($id !== null && $id !== '') {
            $record->load($id);
        }

        $normalized = $this->normalizeRowForPersistence($data);
        unset($normalized['id']);
        $record->setData($normalized)->save();

        $this->syncModelSequence($modelClass);

        return $this->loadRecord($modelClass, $record->getId()) ?? ['id' => $record->getId()];
    }

    private function deleteSingleRecord(string $modelClass, mixed $id): bool
    {
        $record = $this->loadModelInstance($modelClass);
        $record->load($id);
        if (!$record->getId()) {
            return false;
        }

        if ($modelClass === 'Weline\DataTable\Model\TestUser') {
            $orders = $this->loadSingleModelRows('Weline\DataTable\Model\TestOrder');
            foreach ($orders as $orderRow) {
                if ((string)($orderRow['user_id'] ?? '') !== (string)$id) {
                    continue;
                }
                $this->deleteSingleRecord('Weline\DataTable\Model\TestOrder', $orderRow['id'] ?? null);
            }
        }

        $record->delete();
        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws \Throwable
     */
    private function loadSingleModelRows(string $modelClass): array
    {
        $this->assertAllowedModel($modelClass);
        return array_values($this->loadModelInstance($modelClass)->reset()->select()->fetchArray());
    }

    /**
     * @return array<string,mixed>|null
     * @throws \Throwable
     */
    private function loadRecord(string $modelClass, mixed $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        $record = $this->loadModelInstance($modelClass);
        $record->load($id);
        if (!$record->getId()) {
            return null;
        }

        $data = $record->getData();
        return is_array($data) ? $data : null;
    }

    private function syncModelSequence(string $modelClass): void
    {
        /** @var Model $model */
        $model = ObjectManager::make($modelClass);
        /** @var ConnectionFactory $connectionFactory */
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $connectionFactory->getConnector();

        if (!$connector instanceof PgsqlConnector) {
            return;
        }

        $tableIdentifier = str_replace('"', '', (string) $model->getTable());
        $tableParts = array_values(array_filter(explode('.', $tableIdentifier), static fn (string $part): bool => $part !== ''));
        $schema = count($tableParts) > 1 ? $tableParts[0] : 'public';
        $table = (string) end($tableParts);
        if ($table === '') {
            return;
        }

        $primaryKey = method_exists($model, 'getPrimaryKey')
            ? (string) $model->getPrimaryKey()
            : 'id';
        $pdo = $connector->getLink();

        $sequenceStatement = $pdo->prepare(
            'SELECT pg_get_serial_sequence(:table_name, :column_name) AS sequence_name'
        );
        $sequenceStatement->execute([
            'table_name' => sprintf('%s.%s', $schema, $table),
            'column_name' => $primaryKey,
        ]);
        $sequenceName = (string) ($sequenceStatement->fetch(\PDO::FETCH_ASSOC)['sequence_name'] ?? '');
        if ($sequenceName === '') {
            return;
        }

        $maxIdStatement = $pdo->query(sprintf(
            'SELECT COALESCE(MAX("%s"), 0) AS max_id FROM "%s"."%s"',
            $primaryKey,
            $schema,
            $table
        ));
        $maxId = (int) ($maxIdStatement->fetch(\PDO::FETCH_ASSOC)['max_id'] ?? 0);
        $seedValue = max(1, $maxId);
        $isCalled = $maxId > 0 ? 'true' : 'false';

        $pdo->exec(
            sprintf(
                'SELECT setval(%s, %d, %s)',
                $pdo->quote($sequenceName),
                $seedValue,
                $isCalled
            )
        );
    }

    /**
     * @param array<string,mixed> $modelConfig
     * @param array<string,mixed> $joinConfig
     * @return array<int,array<string,mixed>>
     * @throws \Throwable
     */
    private function loadJoinedRows(array $modelConfig, array $joinConfig): array
    {
        $nestedRows = [];
        $mainAlias = (string)array_key_first($modelConfig['models']);
        $nestedSourceRows = $this->loadSingleModelRows($modelConfig['models'][$mainAlias]);
        foreach ($nestedSourceRows as $row) {
            $nestedRows[] = [$mainAlias => $row];
        }

        foreach ($joinConfig['joins'] as $join) {
            [$leftAlias, $leftField, $rightAlias, $rightField] = $this->parseJoinCondition((string)$join['condition']);
            $targetAlias = isset($modelConfig['models'][$rightAlias]) ? $rightAlias : $leftAlias;
            $targetField = $targetAlias === $rightAlias ? $rightField : $leftField;
            $sourceAlias = $targetAlias === $rightAlias ? $leftAlias : $rightAlias;
            $sourceField = $sourceAlias === $leftAlias ? $leftField : $rightField;
            $targetRows = $this->loadSingleModelRows($modelConfig['models'][$targetAlias]);
            $targetIndex = [];

            foreach ($targetRows as $targetRow) {
                $key = (string)($targetRow[$targetField] ?? '');
                $targetIndex[$key][] = $targetRow;
            }

            $expandedRows = [];
            foreach ($nestedRows as $nestedRow) {
                $sourceValue = (string)($nestedRow[$sourceAlias][$sourceField] ?? '');
                $matches = $targetIndex[$sourceValue] ?? [];

                if ($matches) {
                    foreach ($matches as $match) {
                        $expandedRows[] = array_merge($nestedRow, [$targetAlias => $match]);
                    }
                    continue;
                }

                if (strtoupper((string)($join['type'] ?? 'INNER')) === 'LEFT') {
                    $expandedRows[] = array_merge($nestedRow, [$targetAlias => []]);
                }
            }

            $nestedRows = $expandedRows;
        }

        return array_values(array_map(fn (array $nestedRow): array => $this->flattenJoinedRow($nestedRow), $nestedRows));
    }

    /**
     * @param array<string,array<string,mixed>> $nestedRow
     * @return array<string,mixed>
     */
    private function flattenJoinedRow(array $nestedRow): array
    {
        $flat = [];
        foreach ($nestedRow as $alias => $row) {
            foreach ($row as $field => $value) {
                $flat[$alias . '.' . $field] = $value;
            }
        }

        $mainAlias = (string)array_key_first($nestedRow);
        if ($mainAlias !== '' && isset($nestedRow[$mainAlias]['id'])) {
            $flat['id'] = $nestedRow[$mainAlias]['id'];
        }

        return $flat;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applySearch(array $rows, string $search): array
    {
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);
        return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            foreach ($row as $value) {
                if ($value === null || is_array($value)) {
                    continue;
                }

                if (mb_strpos(mb_strtolower((string)$value), $needle) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        $filters = array_filter($filters, static fn ($value) => $value !== null && $value !== '');
        if (!$filters) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $field => $expected) {
                $actual = $row[$field] ?? null;
                if ($actual === null) {
                    return false;
                }

                if (is_numeric($expected) && is_numeric($actual)) {
                    if ((string)$actual !== (string)$expected) {
                        return false;
                    }
                    continue;
                }

                if (mb_stripos((string)$actual, (string)$expected) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $sorts
     * @return array<int,array<string,mixed>>
     */
    private function applySorts(array $rows, array $sorts): array
    {
        if (!$sorts) {
            return $rows;
        }

        uasort($rows, static function (array $leftRow, array $rightRow) use ($sorts): int {
            foreach ($sorts as $field => $direction) {
                $left = $leftRow[$field] ?? null;
                $right = $rightRow[$field] ?? null;
                if ($left == $right) {
                    continue;
                }

                $result = strnatcasecmp((string)$left, (string)$right);
                return strtoupper((string)$direction) === 'DESC' ? -$result : $result;
            }

            return 0;
        });

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $sorts
     * @param array<string,mixed> $modelConfig
     * @return array<string,string>
     */
    private function normalizeSorts(array $sorts, array $modelConfig): array
    {
        if ($sorts) {
            return array_map(static fn (mixed $direction): string => strtoupper((string)$direction), $sorts);
        }

        if (count($modelConfig['models']) > 1) {
            $mainAlias = (string)array_key_first($modelConfig['models']);
            if ($mainAlias !== '') {
                return [$mainAlias . '.id' => 'DESC'];
            }
        }

        return ['id' => 'DESC'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function paginateRows(array $rows, int $page, int $pageSize): array
    {
        $total = count($rows);
        $lastPage = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $lastPage);
        $offset = max(0, ($page - 1) * $pageSize);
        $items = array_slice($rows, $offset, $pageSize);

        return [
            'data' => array_values($items),
            'total' => $total,
            'page' => $page,
            'limit' => $pageSize,
            'pageSize' => $pageSize,
            'pages' => $lastPage,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'lastPage' => $lastPage,
                'hasPrevPage' => $page > 1,
                'hasNextPage' => $page < $lastPage,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeModelConfig(string $model, mixed $modelConfig): array
    {
        if (is_array($modelConfig) && !empty($modelConfig['models']) && is_array($modelConfig['models'])) {
            $normalized = [
                'models' => [],
                'main_model' => (string)($modelConfig['main_model'] ?? ''),
                'aliases' => is_array($modelConfig['aliases'] ?? null) ? $modelConfig['aliases'] : [],
            ];

            foreach ($modelConfig['models'] as $alias => $modelClass) {
                $alias = (string)$alias;
                $modelClass = (string)$modelClass;
                $this->assertAllowedModel($modelClass);
                $normalized['models'][$alias] = $modelClass;
                $normalized['aliases'][$modelClass] = $alias;
                if ($normalized['main_model'] === '') {
                    $normalized['main_model'] = $modelClass;
                }
            }

            return $normalized;
        }

        return $this->parseModelConfig($model);
    }

    /**
     * @return array<string,mixed>
     */
    private function parseModelConfig(string $modelConfig): array
    {
        $result = [
            'models' => [],
            'main_model' => '',
            'aliases' => [],
        ];

        foreach (array_filter(array_map('trim', explode(',', $modelConfig))) as $part) {
            if (stripos($part, ' as ') !== false) {
                [$modelClass, $alias] = preg_split('/\s+as\s+/i', $part, 2);
                $modelClass = trim((string)$modelClass);
                $alias = trim((string)$alias);
            } else {
                $modelClass = trim($part);
                $alias = basename(str_replace('\\', '/', $modelClass));
            }

            $this->assertAllowedModel($modelClass);
            $result['models'][$alias] = $modelClass;
            $result['aliases'][$modelClass] = $alias;
            if ($result['main_model'] === '') {
                $result['main_model'] = $modelClass;
            }
        }

        if ($result['main_model'] === '') {
            throw new Exception(__('Demo table model is required.'));
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseJoinConfig(string $joinConfig): array
    {
        $result = ['joins' => []];

        foreach (array_filter(array_map('trim', explode(',', $joinConfig))) as $part) {
            if (preg_match('/^(left|right|inner|outer)\s+(.+?)\s+on\s+(.+)$/i', $part, $matches)) {
                $result['joins'][] = [
                    'type' => strtoupper($matches[1]),
                    'table' => trim($matches[2]),
                    'condition' => trim($matches[3]),
                ];
                continue;
            }

            if (preg_match('/^(left|right|inner|outer)\s+(.+)$/i', $part, $matches)) {
                $result['joins'][] = [
                    'type' => strtoupper($matches[1]),
                    'table' => '',
                    'condition' => trim($matches[2]),
                ];
                continue;
            }

            if (preg_match('/^(.+?)\s+on\s+(.+)$/i', $part, $matches)) {
                $result['joins'][] = [
                    'type' => 'INNER',
                    'table' => trim($matches[1]),
                    'condition' => trim($matches[2]),
                ];
                continue;
            }

            if (preg_match('/^[a-zA-Z_][\w]*\.[\w]+\s*=\s*[a-zA-Z_][\w]*\.[\w]+$/', $part)) {
                $result['joins'][] = [
                    'type' => 'INNER',
                    'table' => '',
                    'condition' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function parseJoinCondition(string $condition): array
    {
        if (!preg_match('/^\s*([a-zA-Z_][\w]*)\.([\w]+)\s*=\s*([a-zA-Z_][\w]*)\.([\w]+)\s*$/', $condition, $matches)) {
            throw new Exception(__('Invalid demo join condition: %{1}', [$condition]));
        }

        return [$matches[1], $matches[2], $matches[3], $matches[4]];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function parseDependencies(string $dependencies): array
    {
        $result = [];
        foreach (array_filter(array_map('trim', explode(',', $dependencies))) as $part) {
            if (!preg_match('/^\s*([a-zA-Z_][\w]*)\.([\w]+)\s*->\s*([a-zA-Z_][\w]*)\.([\w]+)\s*$/', $part, $matches)) {
                continue;
            }

            $result[] = [
                'source_alias' => $matches[1],
                'source_field' => $matches[2],
                'target_alias' => $matches[3],
                'target_field' => $matches[4],
            ];
        }

        return $result;
    }

    /**
     * @param array<int,string> $aliases
     * @param array<int,array<string,string>> $dependencies
     * @return array<int,string>
     */
    private function resolveDependencyOrder(array $aliases, array $dependencies): array
    {
        $incoming = array_fill_keys($aliases, 0);
        $graph = [];

        foreach ($dependencies as $dependency) {
            $from = $dependency['source_alias'];
            $to = $dependency['target_alias'];
            $graph[$from][] = $to;
            $incoming[$to] = ($incoming[$to] ?? 0) + 1;
        }

        $queue = array_values(array_filter($aliases, static fn (string $alias) => ($incoming[$alias] ?? 0) === 0));
        $ordered = [];

        while ($queue) {
            $alias = array_shift($queue);
            $ordered[] = $alias;

            foreach ($graph[$alias] ?? [] as $target) {
                $incoming[$target]--;
                if ($incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }

        foreach ($aliases as $alias) {
            if (!in_array($alias, $ordered, true)) {
                $ordered[] = $alias;
            }
        }

        return $ordered;
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws \Throwable
     */
    private function buildModelFields(string $modelClass, ?string $alias = null): array
    {
        $columns = $this->loadModelInstance($modelClass)->columns();
        $primaryKey = 'id';
        $fields = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $fieldName = (string)($column['Field'] ?? $column['field'] ?? '');
            if ($fieldName === '') {
                continue;
            }

            $fullFieldName = $alias ? $alias . '.' . $fieldName : $fieldName;
            $label = (string)($column['Comment'] ?? $column['comment'] ?? $this->humanizeFieldLabel($fieldName));
            if ($label === '') {
                $label = $this->humanizeFieldLabel($fieldName);
            }

            $field = [
                'name' => $fullFieldName,
                'label' => $alias ? strtoupper($alias) . ' ' . $label : $label,
                'type' => $this->resolveFieldType($fieldName, (string)($column['Type'] ?? '')),
                'sortable' => true,
                'searchable' => true,
                'visible' => true,
                'editable' => $fieldName !== $primaryKey,
                'is_primary' => $fieldName === $primaryKey,
                'primary_key' => $fieldName === $primaryKey,
                'required' => $fieldName !== $primaryKey && strtoupper((string)($column['Null'] ?? 'YES')) !== 'YES' && ($column['Default'] ?? null) === null,
                'placeholder' => 'Please enter ' . $label,
                'width' => $this->resolveFieldWidth($fieldName, (string)($column['Type'] ?? '')),
                'minWidth' => null,
                'maxWidth' => null,
                'resizable' => true,
                'display_orderable' => true,
                'template_defined' => false,
                'field_defined' => false,
                'from_field' => false,
                'options' => $this->resolveFieldOptions($modelClass, $fieldName),
            ];

            if ($alias) {
                $field['alias'] = $alias;
                $field['original_field'] = $fieldName;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $modelConfig
     * @return array<int,array<string,mixed>>
     * @throws \Throwable
     */
    private function buildMultiModelFields(array $modelConfig): array
    {
        $fields = [];
        foreach ($modelConfig['models'] as $alias => $modelClass) {
            foreach ($this->buildModelFields($modelClass, (string)$alias) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function defaultDisplayFields(array $fields): array
    {
        $preferred = array_values(array_filter($fields, static function (array $field): bool {
            $name = strtolower((string)($field['name'] ?? ''));
            return !in_array($name, ['password', 'attachment', 'avatar', 'photo', 'bio', 'content', 'detail', 'description', 'remark', 'note', 'comment'], true);
        }));

        return array_slice($preferred ?: $fields, 0, 8);
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function defaultFilterFields(array $fields): array
    {
        $preferredNames = ['id', 'name', 'email', 'status', 'order_no', 'sku', 'user_id'];
        $preferred = array_values(array_filter($fields, static function (array $field) use ($preferredNames): bool {
            $name = strtolower((string)($field['name'] ?? ''));
            $tail = str_contains($name, '.') ? strtolower((string)substr($name, strrpos($name, '.') + 1)) : $name;
            return in_array($tail, $preferredNames, true);
        }));

        return array_slice($preferred ?: $fields, 0, 4);
    }

    /**
     * @param array<int,array<string,mixed>> $allFields
     * @param array<int,array<string,mixed>> $cachedFields
     * @return array<int,array<string,mixed>>
     */
    private function mergeCachedFields(array $allFields, array $cachedFields): array
    {
        if (!$cachedFields) {
            return [];
        }

        $fieldMap = [];
        foreach ($allFields as $field) {
            $fieldMap[(string)$field['name']] = $field;
        }

        $merged = [];
        foreach ($cachedFields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '' || !isset($fieldMap[$name])) {
                continue;
            }
            $merged[] = array_merge($fieldMap[$name], $field);
        }

        return $merged;
    }

    private function assertAllowedModel(string $modelClass): void
    {
        if (!in_array($modelClass, self::ALLOWED_MODELS, true)) {
            throw new Exception(__('Frontend demo access is not allowed for model %{1}', [$modelClass]));
        }
    }

    private function loadModelInstance(string $modelClass): Model
    {
        $this->assertAllowedModel($modelClass);

        /** @var Model $model */
        $model = ObjectManager::make($modelClass);
        return $model;
    }

    private function resolveFieldType(string $fieldName, string $dbType): string
    {
        $dbType = strtolower($dbType);
        $fieldName = strtolower($fieldName);

        if (str_contains($dbType, 'date') && str_contains($dbType, 'time')) {
            return 'datetime';
        }
        if (str_contains($dbType, 'date')) {
            return 'date';
        }
        if (preg_match('/(^is_|status|state|type|gender|featured|payment_status|order_status)/', $fieldName)) {
            return 'select';
        }
        if (preg_match('/^(decimal|float|double|numeric|int|tinyint|smallint|bigint)/', $dbType)) {
            return 'number';
        }
        if (str_contains($dbType, 'text')) {
            return 'textarea';
        }
        if (str_contains($fieldName, 'email')) {
            return 'email';
        }
        if (str_contains($fieldName, 'phone')) {
            return 'tel';
        }
        if (str_contains($fieldName, 'password')) {
            return 'password';
        }
        if (preg_match('/(avatar|photo|image)$/', $fieldName)) {
            return 'image';
        }
        if (preg_match('/(attachment|file|document)$/', $fieldName)) {
            return 'file';
        }

        return 'text';
    }

    private function resolveFieldWidth(string $fieldName, string $dbType): string
    {
        $fieldName = strtolower($fieldName);
        $dbType = strtolower($dbType);

        if ($fieldName === 'id' || str_ends_with($fieldName, '_id')) {
            return '96px';
        }
        if (str_contains($dbType, 'text')) {
            return '240px';
        }
        if (str_contains($dbType, 'date') || str_contains($dbType, 'time')) {
            return '180px';
        }
        if (preg_match('/^(decimal|float|double|numeric|int|tinyint|smallint|bigint)/', $dbType)) {
            return '140px';
        }

        return '180px';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolveFieldOptions(string $modelClass, string $fieldName): array
    {
        $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName))) . 'Options';
        $model = $this->loadModelInstance($modelClass);
        if (!method_exists($model, $method)) {
            return [];
        }

        $options = $model->$method();
        return is_array($options) ? array_values($options) : [];
    }

    private function humanizeFieldLabel(string $fieldName): string
    {
        return ucwords(str_replace('_', ' ', $fieldName));
    }

    /**
     * @return array<int,string>
     */
    private function normalizeFieldList(mixed $fields): array
    {
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        if (!is_array($fields)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($field): string => trim((string)$field), $fields), static fn (string $field): bool => $field !== ''));
    }

    /**
     * @return array<int,array{name:string,label:string}>
     * @throws \Throwable
     */
    private function normalizeExportFields(array $fields, string $modelClass): array
    {
        if (!$fields) {
            $fields = array_map(static fn (array $field): array => [
                'name' => (string)$field['name'],
                'label' => (string)($field['label'] ?? $field['name']),
            ], $this->defaultDisplayFields($this->buildModelFields($modelClass)));
        }

        $normalized = [];
        foreach ($fields as $field) {
            $name = is_array($field) ? (string)($field['name'] ?? '') : (string)$field;
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'label' => is_array($field) ? (string)($field['label'] ?? $name) : $this->humanizeFieldLabel($name),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array{name:string,label:string}> $fields
     * @return array<int,array<string,mixed>>
     */
    private function mapRowsForExport(array $rows, array $fields): array
    {
        $result = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($fields as $field) {
                $item[$field['name']] = $row[$field['name']] ?? '';
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array{name:string,label:string}> $fields
     */
    private function buildCsv(array $rows, array $fields): string
    {
        $lines = [];
        $lines[] = implode(',', array_map(fn (array $field): string => $this->escapeCsvValue($field['label']), $fields));

        foreach ($rows as $row) {
            $line = [];
            foreach ($fields as $field) {
                $line[] = $this->escapeCsvValue($row[$field['name']] ?? '');
            }
            $lines[] = implode(',', $line);
        }

        return implode("\r\n", $lines);
    }

    private function escapeCsvValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = str_replace('"', '""', (string)$value);
        return '"' . $value . '"';
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeRowForPersistence(array $row): array
    {
        $normalized = [];
        foreach ($row as $field => $value) {
            if ($value instanceof \DateTimeInterface) {
                $normalized[$field] = $value->format('Y-m-d H:i:s');
                continue;
            }

            if (is_array($value)) {
                $normalized[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                continue;
            }

            if (is_bool($value)) {
                $normalized[$field] = $value ? 1 : 0;
                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    private function getConfigCacheKey(string $scope, string $tableId): string
    {
        return 'datatable_demo_fields_' . md5($scope . '|' . $tableId);
    }

    /**
     * @return array<string,mixed>
     */
    private function getConfigCache(string $scope, string $tableId): array
    {
        $cache = w_cache('default')->get($this->getConfigCacheKey($scope, $tableId));
        return is_array($cache) ? $cache : [];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $normalized ?? false;
    }
}
