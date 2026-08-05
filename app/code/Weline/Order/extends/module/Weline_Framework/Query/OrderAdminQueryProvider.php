<?php

declare(strict_types=1);

namespace Weline\Order\Extends\Module\Weline_Framework\Query;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\AdminControllerBridge;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Order\Controller\Backend\Status;
use Weline\Order\Service\DisplayNumberAllocator;
use Weline\Order\Service\DisplayNumberLookup;
use Weline\Order\Service\OrderObjectScopeService;

/**
 * 订单后台类型化写操作。
 *
 * 不接受 URL、Controller、HTTP method 或 headers，避免任意后台控制器转发。
 */
class OrderAdminQueryProvider implements QueryProviderInterface
{
    private readonly DisplayNumberLookup $displayNumberLookup;
    private readonly OrderObjectScopeService $objectScopeService;

    public function __construct(
        private readonly BackendObjectAuthorizationGuardInterface $objectAuthorizationGuard,
        ?DisplayNumberLookup $displayNumberLookup = null,
        ?OrderObjectScopeService $objectScopeService = null,
    ) {
        $this->displayNumberLookup = $displayNumberLookup
            ?? new DisplayNumberLookup(new DisplayNumberAllocator(useMemory: false));
        $this->objectScopeService = $objectScopeService ?? new OrderObjectScopeService();
    }

    public function getProviderName(): string
    {
        return 'order_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'lookupDisplayNumber' => $this->lookupDisplayNumber($params),
            'saveStatus' => $this->invokeStatus('save', ObjectAction::UPDATE, $params),
            'deleteStatus' => $this->invokeStatus('delete', ObjectAction::DELETE, $params),
            'toggleStatus' => $this->invokeStatus('toggle', ObjectAction::UPDATE, $params),
            default => throw new \InvalidArgumentException('Unsupported order admin operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'order_admin',
            'name' => __('Weline_Order typed admin operations'),
            'module' => 'Weline_Order',
            'operations' => [
                [
                    'name' => 'lookupDisplayNumber',
                    'description' => __('按类型与 Scope 查询订单对象展示号'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Order::order_view',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'number_kind', 'type' => 'string', 'required' => true],
                        ['name' => 'display_number', 'type' => 'string', 'required' => true],
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                        ['name' => 'store_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                $this->operationDescriptor(
                    'saveStatus',
                    'Weline_Order::status_save',
                    [
                        ['name' => 'payload', 'type' => 'string', 'required' => true],
                        $this->grantVersionParam(),
                    ],
                ),
                $this->operationDescriptor(
                    'deleteStatus',
                    'Weline_Order::status_delete',
                    [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                        $this->grantVersionParam(),
                    ],
                ),
                $this->operationDescriptor(
                    'toggleStatus',
                    'Weline_Order::status_toggle',
                    [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                        $this->grantVersionParam(),
                    ],
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function lookupDisplayNumber(array $params): array
    {
        $numberKind = \array_key_exists('number_kind', $params)
            ? (string)$params['number_kind']
            : null;
        $displayNumber = trim((string)($params['display_number'] ?? ''));
        $websiteId = $this->scopeId($params, 'website_id');
        $storeId = $this->scopeId($params, 'store_id');

        // Stable kind/display validation precedes object resolution and DML-free lookup.
        if ($numberKind === null || trim($numberKind) === '') {
            return $this->displayNumberLookup
                ->find($numberKind, $displayNumber, $websiteId, $storeId)
                ->toArray();
        }
        $this->displayNumberLookup->allocator()->normalizeKind($numberKind);
        if ($displayNumber === '') {
            throw new \InvalidArgumentException('display_number_required');
        }

        $scope = $this->objectScopeService->fromPersistedIds($websiteId, $storeId);
        $this->objectAuthorizationGuard->requireForQuery(ObjectAction::VIEW, $scope);
        $reference = $this->displayNumberLookup->find(
            $numberKind,
            $displayNumber,
            $websiteId,
            $storeId,
        );

        return ['success' => true] + $reference->toArray();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scopeId(array $params, string $field): int
    {
        $value = $params[$field] ?? null;
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int)$value;
        }

        throw new \InvalidArgumentException($field . '_required');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function invokeStatus(string $action, string $objectAction, array $params): mixed
    {
        $this->objectAuthorizationGuard->requireSubmitForQuery(
            $objectAction,
            ScopeIdentity::global(),
            $this->expectedGrantVersion($params),
        );
        $bodyParams = [];
        if ($action === 'save') {
            \parse_str((string)($params['payload'] ?? ''), $bodyParams);
            if (!\is_array($bodyParams)) {
                $bodyParams = [];
            }
        } else {
            $bodyParams['id'] = (int)($params['id'] ?? 0);
        }
        $bodyParams['expected_grant_version'] = $this->expectedGrantVersion($params);

        return AdminControllerBridge::invoke(
            Status::class,
            [$action],
            [],
            $bodyParams,
            'POST',
            \http_build_query($bodyParams),
        );
    }

    /**
     * @param list<array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function operationDescriptor(string $name, string $sourceId, array $params): array
    {
        return [
            'name' => $name,
            'description' => __('订单状态后台类型化操作'),
            'frontend' => true,
            'auth' => 'backend',
            'backend' => true,
            'backend_acl' => ['kind' => 'source', 'source_id' => $sourceId],
            'mode' => 'write',
            'params' => $params,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grantVersionParam(): array
    {
        return [
            'name' => 'expected_grant_version',
            'type' => 'int',
            'required' => true,
            'description' => __('页面读取到的对象授权版本'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function expectedGrantVersion(array $params): int
    {
        $value = $params['expected_grant_version'] ?? null;
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
