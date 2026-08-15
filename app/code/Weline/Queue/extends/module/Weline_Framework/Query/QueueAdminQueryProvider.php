<?php

declare(strict_types=1);

namespace Weline\Queue\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Queue\Service\QueueAdminService;
use Weline\Queue\Service\QueueAdminListingView;

/**
 * Narrow, backend-authenticated browser surface for Queue administration.
 *
 * The general `queue` provider intentionally remains server-only: dispatch,
 * takeover, force deletion and lease ownership are never published here.
 */
final class QueueAdminQueryProvider implements QueryProviderInterface
{
    private const ACTION_ACL_SOURCES = [
        'delete' => 'Weline_Queue::delete',
        'stop' => 'Weline_Queue::stop',
        'continue' => 'Weline_Queue::continue',
        'retry' => 'Weline_Queue::continue',
        'reset' => 'Weline_Queue::reset',
    ];

    private const BATCH_ACTION_ACL_SOURCES = [
        'delete' => 'Weline_Queue::delete',
        'stop' => 'Weline_Queue::stop',
        'continue' => 'Weline_Queue::continue',
    ];

    public function __construct(
        private readonly QueueAdminService $adminService,
        private readonly QueueAdminListingView $listingView,
    ) {
    }

    public function getProviderName(): string
    {
        return 'queue_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'snapshot' => $this->listingView->snapshot($params),
            'searchTypes' => $this->adminService->searchTypes($params),
            'typeAttributes' => $this->adminService->typeAttributes($params),
            'resolveAttributeDependence' => $this->adminService->resolveAttributeDependence($params),
            'save' => $this->adminService->save($params),
            'action' => $this->adminService->action($params),
            'batchAction' => $this->adminService->batchAction($params),
            'setTypeEnabled' => $this->adminService->setTypeEnabled($params),
            default => throw new \InvalidArgumentException(
                (string)__('Queue 后台查询器不支持的操作：%{1}', $operation),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'queue_admin',
            'name' => (string)__('Queue 后台管理查询器'),
            'description' => (string)__('为 Queue 后台页面提供受 ACL 保护的列表、表单和控制操作。'),
            'module' => 'Weline_Queue',
            'operations' => [
                $this->sourceOperation(
                    'snapshot',
                    'read',
                    'Weline_Queue::index',
                    2,
                    [
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1, 'default' => 1],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'max_length' => 12],
                        ['name' => 'q', 'type' => 'string', 'required' => false, 'max_length' => 200],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'max_length' => 191],
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'min' => 1],
                        ['name' => 'known_revision', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                ),
                $this->sourceOperation(
                    'searchTypes',
                    'read',
                    'Weline_Queue::search_type',
                    1,
                    [
                        ['name' => 'q', 'type' => 'string', 'required' => false, 'max_length' => 120],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'dir', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ],
                ),
                $this->sourceOperation(
                    'typeAttributes',
                    'read',
                    'Weline_Queue::form',
                    2,
                    [
                        ['name' => 'type_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'min' => 1],
                    ],
                ),
                $this->sourceOperation(
                    'resolveAttributeDependence',
                    'read',
                    'Weline_Queue::get_type_attributes',
                    2,
                    [
                        ['name' => 'type_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'attribute', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'dependence_attribute', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'dependence_value', 'type' => 'mixed', 'required' => true],
                        ['name' => 'attribute_value', 'type' => 'mixed', 'required' => false],
                    ],
                ),
                $this->sourceOperation(
                    'save',
                    'write',
                    'Weline_Queue::form',
                    3,
                    [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => false, 'min' => 1],
                        ['name' => 'type_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'max_length' => 255],
                        ['name' => 'biz_key', 'type' => 'string', 'required' => false, 'max_length' => 191],
                        ['name' => 'attributes', 'type' => 'list', 'required' => false, 'max_items' => 100],
                    ],
                ),
                $this->mappedOperation(
                    'action',
                    self::ACTION_ACL_SOURCES,
                    [
                        ['name' => 'queue_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'max_length' => 16],
                    ],
                ),
                $this->mappedOperation(
                    'batchAction',
                    self::BATCH_ACTION_ACL_SOURCES,
                    [
                        ['name' => 'queue_ids', 'type' => 'list', 'required' => true, 'max_items' => QueueAdminService::MAX_BATCH_SIZE],
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'max_length' => 16],
                    ],
                ),
                $this->sourceOperation(
                    'setTypeEnabled',
                    'write',
                    'Weline_Queue::type_manage',
                    2,
                    [
                        ['name' => 'type_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'enabled', 'type' => 'bool', 'required' => true],
                    ],
                ),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $params
     * @return array<string,mixed>
     */
    private function sourceOperation(
        string $name,
        string $mode,
        string $sourceId,
        int $cost,
        array $params,
    ): array {
        return [
            'name' => $name,
            'frontend' => true,
            'backend' => true,
            'external' => true,
            'auth' => 'backend',
            'mode' => $mode,
            'graph' => false,
            'cost' => $cost,
            'backend_acl' => ['kind' => 'source', 'source_id' => $sourceId],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }

    /**
     * @param array<string,string> $aclMap
     * @param list<array<string,mixed>> $params
     * @return array<string,mixed>
     */
    private function mappedOperation(string $name, array $aclMap, array $params): array
    {
        return [
            'name' => $name,
            'frontend' => true,
            'backend' => true,
            'external' => true,
            'auth' => 'backend',
            'mode' => 'write',
            'graph' => false,
            'cost' => 3,
            'backend_acl' => [
                'kind' => 'param_map',
                'param' => 'action',
                'map' => $aclMap,
            ],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }
}
