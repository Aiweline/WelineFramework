<?php
declare(strict_types=1);

namespace Weline\DataTable\Extends\Module\Weline_Framework\Query;

use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class DataTableQueryProvider implements QueryProviderInterface
{
    private const ACL_SOURCE = 'Weline_DataTable::datatable_test_comprehensive';

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
            'metadata' => $this->success($this->demoTableService->getModelMetadata($params)),
            'formFields' => $this->success($this->demoTableService->getFormFields($params)),
            'formRecord' => $this->success($this->demoTableService->getRecord($params)),
            'create' => $this->success($this->demoTableService->createRecord($params), 'Record created.'),
            'update' => $this->success($this->demoTableService->updateRecord($params), 'Record updated.'),
            'saveData' => $this->success($this->demoTableService->saveData($params), 'Record saved.'),
            'deleteData' => $this->success($this->demoTableService->deleteRecords($params), 'Record deleted.'),
            'exportData' => $this->success($this->demoTableService->exportData($params), 'Export generated.'),
            'saveConfig' => $this->success($this->demoTableService->saveFieldConfig($params), 'Field config saved.'),
            'clearConfig' => $this->success($this->demoTableService->clearFieldConfig($params), 'Field config cleared.'),
            'previewWrite' => $this->success($this->demoTableService->previewWrite($params), 'Write plan ready.'),
            'executeWrite' => $this->success($this->demoTableService->executeWrite($params), 'Confirmed write completed.'),
            'initData' => $this->success($this->demoTableService->initDemoData(), 'Demo data initialized.'),
            'clearData' => $this->success($this->demoTableService->clearDemoData(), 'Demo data cleared.'),
            default => throw new \InvalidArgumentException('DataTable query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'datatable',
            'name' => __('DataTable Registered Resource Query'),
            'description' => __('Registered DataTable demo resources through the Weline.Api worker channel.'),
            'module' => 'Weline_DataTable',
            'operations' => [
                $this->operation('data', 'read', true, 3, 'Load registered table rows', 'any'),
                $this->operation('fields', 'read', true, 2, 'Load registered table fields', 'any'),
                $this->operation('metadata', 'read', true, 2, 'Load registered model metadata', 'any'),
                $this->operation('formFields', 'read', true, 2, 'Load registered form fields', 'any'),
                $this->operation('formRecord', 'read', false, 2, 'Load one registered form record', 'backend'),
                $this->operation('create', 'write', false, 5, 'Create registered record', 'backend'),
                $this->operation('update', 'write', false, 5, 'Update registered record', 'backend'),
                $this->operation('saveData', 'write', false, 5, 'Save registered record', 'backend'),
                $this->operation('deleteData', 'write', false, 5, 'Delete registered records', 'backend'),
                $this->operation('exportData', 'read', false, 5, 'Export registered rows', 'any'),
                $this->operation('saveConfig', 'write', false, 3, 'Save table field preferences', 'backend'),
                $this->operation('clearConfig', 'write', false, 3, 'Clear table field preferences', 'backend'),
                $this->operation('previewWrite', 'write', false, 4, 'Preview a registered composite write', 'backend'),
                $this->operation('executeWrite', 'write', false, 6, 'Execute a confirmed registered write', 'backend'),
                $this->operation('initData', 'write', false, 5, 'Initialize demo data', 'backend'),
                $this->operation('clearData', 'write', false, 5, 'Clear demo data', 'backend'),
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
    private function operation(
        string $name,
        string $mode,
        bool $graph,
        int $cost,
        string $summary,
        string $auth
    ): array
    {
        $descriptor = [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'backend' => $auth === 'backend',
            'external' => false,
            'auth' => $auth,
            'mode' => $mode,
            'graph' => $graph,
            'cost' => $cost,
            'params' => [
                'model' => ['type' => 'string', 'required' => false],
                'resource' => ['type' => 'string', 'required' => false, 'max_length' => 120],
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
                'write_operation' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                'write_order' => ['type' => 'string', 'required' => false, 'max_length' => 1024],
                'plan_token' => ['type' => 'string', 'required' => false, 'max_length' => 128],
            ],
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];

        if ($auth === 'backend') {
            $descriptor['backend_acl'] = [
                'kind' => 'source',
                'source_id' => self::ACL_SOURCE,
            ];
        }

        return $descriptor;
    }
}
