<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Extends\Module\Weline_Framework\Query;

use Weline\Acl\Api\Authorization\AuthorizationServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Database\Api\ModuleRollbackManagerInterface;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/** ModuleManager is an authenticated adapter; rollback ownership stays in Weline_Database. */
final class ModuleRollbackQueryProvider implements QueryProviderInterface
{
    private const ROUTES = [
        'listTargets' => ['module-manager/backend/rollback/targets', 'GET'],
        'createPlan' => ['module-manager/backend/rollback/plan', 'POST'],
        'start' => ['module-manager/backend/rollback/start', 'POST'],
        'getOperation' => ['module-manager/backend/rollback/operation', 'GET'],
    ];

    public function __construct(
        private readonly ModuleRollbackManagerInterface $rollbackManager,
        private readonly SessionFactory $sessionFactory,
        private readonly BackendUserContextProviderInterface $userContextProvider,
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'moduleRollback';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $actor = $this->assertAuthorized($operation);
        return match ($operation) {
            'listTargets' => [
                'success' => true,
                'items' => $this->rollbackManager->listTargets(trim((string)($params['module_name'] ?? ''))),
            ],
            'createPlan' => [
                'success' => true,
                'plan' => $this->rollbackManager->createPlan(
                    trim((string)($params['module_name'] ?? '')),
                    trim((string)($params['target_version'] ?? '')),
                ),
            ],
            'start' => $this->start($params, $actor),
            'getOperation' => [
                'success' => true,
                'operation' => $this->rollbackManager->getOperation(trim((string)($params['operation_id'] ?? ''))),
            ],
            default => throw new \InvalidArgumentException(__('不支持的模块回滚操作: %{1}', $operation)),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'moduleRollback',
            'name' => __('模块版本回滚'),
            'description' => __('ModuleManager 对 Weline_Database 联动回滚能力的后台适配器'),
            'module' => 'Weline_ModuleManager',
            'operations' => [
                $this->operationDescriptor('listTargets', 'read', [
                    ['name' => 'module_name', 'type' => 'string', 'required' => true, 'max_length' => 100],
                ]),
                $this->operationDescriptor('createPlan', 'write', [
                    ['name' => 'module_name', 'type' => 'string', 'required' => true, 'max_length' => 100],
                    ['name' => 'target_version', 'type' => 'string', 'required' => true, 'max_length' => 50],
                ]),
                $this->operationDescriptor('start', 'write', [
                    ['name' => 'plan_id', 'type' => 'string', 'required' => true, 'max_length' => 64],
                    ['name' => 'plan_hash', 'type' => 'string', 'required' => true, 'max_length' => 64],
                    ['name' => 'confirm_module', 'type' => 'string', 'required' => true, 'max_length' => 100],
                    ['name' => 'confirm_version', 'type' => 'string', 'required' => true, 'max_length' => 50],
                ]),
                $this->operationDescriptor('getOperation', 'read', [
                    ['name' => 'operation_id', 'type' => 'string', 'required' => true, 'max_length' => 64],
                ]),
            ],
        ];
    }

    private function start(array $params, BackendUserContext $actor): array
    {
        $planId = trim((string)($params['plan_id'] ?? ''));
        $operation = $this->rollbackManager->getOperation($planId);
        $plan = (array)($operation['plan'] ?? []);
        $confirmedModule = trim((string)($params['confirm_module'] ?? ''));
        $confirmedVersion = trim((string)($params['confirm_version'] ?? ''));
        if ($confirmedModule !== (string)($plan['root_module'] ?? '')
            || $confirmedVersion !== (string)($plan['target_version'] ?? '')) {
            throw new \RuntimeException(__('确认的根模块或目标版本与预检计划不一致'));
        }
        return $this->rollbackManager->start(
            $planId,
            trim((string)($params['plan_hash'] ?? '')),
            $actor->getUsername() . '#' . $actor->getId(),
        );
    }

    private function assertAuthorized(string $operation): BackendUserContext
    {
        $route = self::ROUTES[$operation] ?? null;
        if ($route === null) {
            throw new \InvalidArgumentException(__('不支持的模块回滚操作: %{1}', $operation));
        }
        $session = $this->sessionFactory->createBackendSession();
        $session->start();
        if (!$session->isLoggedIn()) {
            throw new \RuntimeException(__('请先登录后台'));
        }
        $actor = $this->userContextProvider->current();
        if (!$actor instanceof BackendUserContext || !$actor->getIsEnabled()) {
            throw new \RuntimeException(__('后台操作员不可用'));
        }
        [$path, $method] = $route;
        if (!$this->authorizationService->isRouteProtected($path)
            || !$this->authorizationService->isRouteAllowed($actor->getRoleId(), $path, $method)) {
            throw new \RuntimeException(__('无权执行模块回滚操作'));
        }
        return $actor;
    }

    private function operationDescriptor(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 5 : 2,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }
}
