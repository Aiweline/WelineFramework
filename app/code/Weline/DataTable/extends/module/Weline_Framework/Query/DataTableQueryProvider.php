<?php
declare(strict_types=1);

namespace Weline\DataTable\Extends\Module\Weline_Framework\Query;

use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class DataTableQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly DemoTableService $demoTableService
    ) {
    }

    public function getProviderName(): string
    {
        return 'datatable';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'data' => $this->success($this->demoTableService->getTableData($params)),
            'fields' => $this->success($this->demoTableService->getTableFields($params)),
            'formFields' => $this->success($this->demoTableService->getFormFields($params)),
            'formRecord' => $this->success($this->demoTableService->getRecord($params)),
            'create' => $this->success($this->demoTableService->createRecord($params), 'Record created.'),
            'update' => $this->success($this->demoTableService->updateRecord($params), 'Record updated.'),
            'saveData' => $this->success($this->demoTableService->saveData($params), 'Record saved.'),
            'deleteData' => $this->success($this->demoTableService->deleteRecords($params), 'Record deleted.'),
            'exportData' => $this->success($this->demoTableService->exportData($params), 'Export generated.'),
            'saveConfig' => $this->success($this->demoTableService->saveFieldConfig($params), 'Field config saved.'),
            'clearConfig' => $this->success($this->demoTableService->clearFieldConfig($params), 'Field config cleared.'),
            'initData' => $this->success($this->demoTableService->initDemoData(), 'Demo data initialized.'),
            'clearData' => $this->success($this->demoTableService->clearDemoData(), 'Demo data cleared.'),
            default => throw new \InvalidArgumentException('DataTable query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'datatable',
            'name' => __('DataTable Frontend Demo Query'),
            'description' => __('Whitelist-only frontend DataTable demo operations through the worker channel.'),
            'module' => 'Weline_DataTable',
            'operations' => [
                $this->operation('data', 'read', true, 3, 'Load demo table rows'),
                $this->operation('fields', 'read', true, 2, 'Load demo table fields'),
                $this->operation('formFields', 'read', true, 2, 'Load demo form fields'),
                $this->operation('formRecord', 'read', false, 2, 'Load one demo form record'),
                $this->operation('create', 'write', false, 5, 'Create demo record'),
                $this->operation('update', 'write', false, 5, 'Update demo record'),
                $this->operation('saveData', 'write', false, 5, 'Save demo record'),
                $this->operation('deleteData', 'write', false, 5, 'Delete demo records'),
                $this->operation('exportData', 'read', false, 5, 'Export demo rows'),
                $this->operation('saveConfig', 'write', false, 3, 'Save demo field config'),
                $this->operation('clearConfig', 'write', false, 3, 'Clear demo field config'),
                $this->operation('initData', 'write', false, 5, 'Initialize demo data'),
                $this->operation('clearData', 'write', false, 5, 'Clear demo data'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function success(array $data, string $message = 'OK'): array
    {
        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operation(string $name, string $mode, bool $graph, int $cost, string $summary): array
    {
        return [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'mode' => $mode,
            'graph' => $graph,
            'cost' => $cost,
            'params' => [
                'model' => ['type' => 'string', 'required' => false],
                'scope' => ['type' => 'string', 'required' => false],
                'table_id' => ['type' => 'string', 'required' => false],
                'form_id' => ['type' => 'string', 'required' => false],
                'record_id' => ['type' => 'mixed', 'required' => false],
                'id' => ['type' => 'mixed', 'required' => false],
                'ids' => ['type' => 'list', 'required' => false, 'max_items' => 200],
                'page' => ['type' => 'int', 'required' => false, 'min' => 1],
                'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                'pageSize' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                'search' => ['type' => 'string', 'required' => false],
                'filters' => ['type' => 'array', 'required' => false],
                'sort' => ['type' => 'array', 'required' => false],
                'sorts' => ['type' => 'array', 'required' => false],
                'join' => ['type' => 'string', 'required' => false],
                'model_config' => ['type' => 'array', 'required' => false],
                'data' => ['type' => 'array', 'required' => false],
                'exclude_fields' => ['type' => 'list', 'required' => false, 'max_items' => 100],
                'include_fields' => ['type' => 'list', 'required' => false, 'max_items' => 100],
                'manual_fields' => ['type' => 'list', 'required' => false, 'max_items' => 100],
                'fields' => ['type' => 'list', 'required' => false, 'max_items' => 100],
                'format' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                'dependencies' => ['type' => 'string', 'required' => false, 'max_length' => 4096],
                'transaction' => ['type' => 'bool', 'required' => false],
                'type' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                'soft_delete' => ['type' => 'bool', 'required' => false],
            ],
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
    }
}
