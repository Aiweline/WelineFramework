<?php
declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Acl\Api\Authorization\AuthorizationServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Server\Service\WlsPanelLifecycleService;

final class WlsPanelLifecycleQueryProvider implements QueryProviderInterface
{
    private const ROUTES = [
        'status' => ['server/backend/wls-panel/lifecycle-status', 'GET'],
        'reload' => ['server/backend/wls-panel/lifecycle-reload', 'POST'],
        'restart' => ['server/backend/wls-panel/lifecycle-restart', 'POST'],
    ];

    private const BACKEND_ACL = [
        'status' => 'Weline_Server::wls_panel_lifecycle_status',
        'reload' => 'Weline_Server::wls_panel_lifecycle_reload',
        'restart' => 'Weline_Server::wls_panel_lifecycle_restart',
    ];

    public function __construct(
        private readonly WlsPanelLifecycleService $lifecycleService,
        private readonly SessionFactory $sessionFactory,
        private readonly BackendUserContextProviderInterface $userContextProvider,
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'wlsPanelLifecycle';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->assertAuthorized($operation);
        $projectKey = \trim((string)($params['project_key'] ?? ''));

        return match ($operation) {
            'status' => $this->lifecycleService->status($projectKey),
            'reload' => $this->lifecycleService->reload($projectKey),
            'restart' => $this->lifecycleService->restart(
                $projectKey,
                \trim((string)($params['confirm_project'] ?? ''))
            ),
            default => throw new \InvalidArgumentException(
                (string)__('不支持的 WLS 面板生命周期操作：%{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'wlsPanelLifecycle',
            'name' => __('WLS 面板生命周期'),
            'description' => __('在后台权限与项目边界内控制 WLS 重载和重启'),
            'module' => 'Weline_Server',
            'operations' => [
                $this->operationDescriptor('status', 'read', [
                    'project_key' => ['type' => 'string', 'required' => true, 'max_length' => 80],
                ]),
                $this->operationDescriptor('reload', 'write', [
                    'project_key' => ['type' => 'string', 'required' => true, 'max_length' => 80],
                ]),
                $this->operationDescriptor('restart', 'write', [
                    'project_key' => ['type' => 'string', 'required' => true, 'max_length' => 80],
                    'confirm_project' => ['type' => 'string', 'required' => true, 'max_length' => 160],
                ]),
            ],
        ];
    }

    private function assertAuthorized(string $operation): BackendUserContext
    {
        $route = self::ROUTES[$operation] ?? null;
        if ($route === null) {
            throw new \InvalidArgumentException(
                (string)__('不支持的 WLS 面板生命周期操作：%{1}', [$operation])
            );
        }

        $session = $this->sessionFactory->createBackendSession();
        $session->start();
        if (!$session->isLoggedIn()) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }

        $actor = $this->userContextProvider->current();
        if (!$actor instanceof BackendUserContext || !$actor->getIsEnabled()) {
            throw new \RuntimeException((string)__('后台操作员不可用'));
        }

        [$path, $method] = $route;
        if (!$this->authorizationService->isRouteProtected($path)
            || !$this->authorizationService->isRouteAllowed($actor->getRoleId(), $path, $method)) {
            throw new \RuntimeException((string)__('无权执行 WLS 面板生命周期操作'));
        }

        return $actor;
    }

    /**
     * @param array<string, array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function operationDescriptor(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 5 : 2,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => self::BACKEND_ACL[$name],
            ],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }
}
