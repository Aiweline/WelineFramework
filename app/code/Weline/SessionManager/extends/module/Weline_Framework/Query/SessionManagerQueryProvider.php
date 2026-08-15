<?php

declare(strict_types=1);

namespace Weline\SessionManager\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\SessionFactory;
use Weline\SessionManager\Service\AuthenticatedDeviceRegistry;

final class SessionManagerQueryProvider implements QueryProviderInterface
{
    private const ACL_SOURCE = 'Weline_SessionManager::device_manage_self';

    public function __construct(
        private readonly AuthenticatedDeviceRegistry $devices,
        private readonly SessionFactory $sessionFactory,
    ) {
    }

    public function getProviderName(): string
    {
        return 'session_manager';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'listFrontendDevices' => $this->listDevices('frontend', $params),
            'revokeFrontendDevice' => $this->revokeDevice('frontend', $params),
            'listBackendDevices' => $this->listDevices('backend', $params),
            'revokeBackendDevice' => $this->revokeDevice('backend', $params),
            default => throw new \InvalidArgumentException(
                (string)__('设备管理查询器不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'session_manager',
            'name' => (string)__('设备管理'),
            'description' => (string)__('管理当前身份自己的登录设备。'),
            'module' => 'Weline_SessionManager',
            'operations' => [
                $this->operation('listFrontendDevices', 'customer', 'read', [
                    [
                        'name' => 'page',
                        'type' => 'int',
                        'min' => 1,
                        'description' => (string)__('页码，从 1 开始，默认 1。'),
                    ],
                    [
                        'name' => 'page_size',
                        'type' => 'int',
                        'min' => 1,
                        'max' => 100,
                        'description' => (string)__('每页数量，默认 20，最大 100。'),
                    ],
                ]),
                $this->operation('revokeFrontendDevice', 'customer', 'write', [
                    [
                        'name' => 'device_id',
                        'type' => 'string',
                        'required' => true,
                        'max_length' => 100,
                        'description' => (string)__('要下线的公开设备 ID。'),
                    ],
                ]),
                $this->operation('listBackendDevices', 'backend', 'read', [
                    [
                        'name' => 'page',
                        'type' => 'int',
                        'min' => 1,
                        'description' => (string)__('页码，从 1 开始，默认 1。'),
                    ],
                    [
                        'name' => 'page_size',
                        'type' => 'int',
                        'min' => 1,
                        'max' => 100,
                        'description' => (string)__('每页数量，默认 20，最大 100。'),
                    ],
                ], true),
                $this->operation('revokeBackendDevice', 'backend', 'write', [
                    [
                        'name' => 'device_id',
                        'type' => 'string',
                        'required' => true,
                        'max_length' => 100,
                        'description' => (string)__('要下线的公开设备 ID。'),
                    ],
                ], true),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function listDevices(string $area, array $params): array
    {
        [$principalId, $context] = $this->currentIdentity($area);
        try {
            return ['success' => true] + $this->devices->listForOwner(
                $area,
                $principalId,
                $context,
                (int)($params['page'] ?? 1),
                (int)($params['page_size'] ?? 20),
            );
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('设备管理服务暂时不可用，请稍后重试。'));
        }
    }

    /** @param array<string,mixed> $params */
    private function revokeDevice(string $area, array $params): array
    {
        [$principalId, $context] = $this->currentIdentity($area);
        try {
            return $this->devices->revokeForOwner(
                $area,
                $principalId,
                (string)($params['device_id'] ?? ''),
                $context,
            );
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('设备管理服务暂时不可用，请稍后重试。'));
        }
    }

    /** @return array{0:string,1:AuthenticatedDeviceContext} */
    private function currentIdentity(string $area): array
    {
        // Backend Query authorization restores the page-attested Session into
        // the request singleton before provider execution. A separately
        // injected factory would create a second, unauthenticated view.
        $factory = $area === 'backend'
            ? SessionFactory::getInstance()
            : $this->sessionFactory;
        $session = $area === 'backend'
            ? $factory->createBackendSession()
            : $factory->createFrontendSession();
        if (!$session->isLoggedIn()) {
            throw new \RuntimeException((string)__('请先登录后管理设备。'));
        }
        $principalId = (string)($session->getUserId() ?? '');
        $sessionId = (string)$session->getId();
        if ($principalId === '' || $sessionId === '') {
            throw new \RuntimeException((string)__('当前认证会话无效。'));
        }
        return [
            $principalId,
            new AuthenticatedDeviceContext(
                area: $area,
                principalId: $principalId,
                sessionId: $sessionId,
                sessionExpiresAt: time() + $this->sessionTtl($session),
                deviceId: $this->boundDeviceId($session, $area),
            ),
        ];
    }

    private function sessionTtl(AuthenticatedSessionInterface $session): int
    {
        $rawSession = $session->getSession();
        return method_exists($rawSession, 'getDefaultTtl')
            ? max(1, (int)$rawSession->getDefaultTtl())
            : 3600;
    }

    private function boundDeviceId(AuthenticatedSessionInterface $session, string $area): ?string
    {
        $deviceId = $session->get(AuthenticatedDeviceContext::sessionKeyForArea($area));
        return is_string($deviceId) && trim($deviceId) !== '' ? trim($deviceId) : null;
    }

    /** @param list<array<string,mixed>> $params */
    private function operation(
        string $name,
        string $auth,
        string $mode,
        array $params,
        bool $backend = false,
    ): array {
        $operation = [
            'name' => $name,
            'description' => str_starts_with($name, 'list')
                ? (string)__('分页列出当前身份的有效登录设备。')
                : (string)__('下线当前身份的一个非当前设备。'),
            'frontend' => true,
            'external' => false,
            'auth' => $auth,
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'read' ? 1 : 3,
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
        if ($backend) {
            $operation['backend'] = true;
            $operation['backend_acl'] = [
                'kind' => 'source',
                'source_id' => self::ACL_SOURCE,
            ];
        }
        return $operation;
    }
}
