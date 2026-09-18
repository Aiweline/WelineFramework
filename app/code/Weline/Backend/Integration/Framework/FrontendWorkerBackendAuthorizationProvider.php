<?php

declare(strict_types=1);

namespace Weline\Backend\Integration\Framework;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Acl\Model\Acl;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationProviderInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;

final class FrontendWorkerBackendAuthorizationProvider implements FrontendWorkerBackendAuthorizationProviderInterface
{
    public function __construct(
        private readonly BackendUserContextProviderInterface $userContextProvider,
        private readonly ResourceAuthorizationServiceInterface $authorizationService,
    ) {
    }

    public function assertSourceAllowed(
        FrontendWorkerBackendBinding $binding,
        string $sourceId,
        string $provider,
        string $operation,
    ): void {
        $actor = $this->userContextProvider->find($binding->backendUserId);
        if ($actor === null
            || !$actor->getIsEnabled()
            || $actor->getId() !== $binding->backendUserId
            || $actor->getRoleId() <= 0) {
            throw new FrontendWorkerBackendAuthorizationException(
                'backend_acl_denied',
                403,
                (string)__('当前后台账号无权执行该操作。'),
            );
        }
        if ($this->authorizationService->isSourceAllowed($actor->getRoleId(), $sourceId)) {
            return;
        }

        throw new FrontendWorkerBackendAuthorizationException(
            'backend_acl_denied',
            403,
            $this->deniedMessage($actor->getRoleId(), $sourceId),
        );
    }

    /**
     * Super-admin bypass still requires the exact source to exist as an enabled
     * backend ACL row — otherwise even role_id=1 is denied by design.
     */
    private function deniedMessage(int $roleId, string $sourceId): string
    {
        $sourceId = \trim($sourceId);
        if ($sourceId === '' || !$this->isRegisteredBackendSource($sourceId)) {
            return (string)__(
                'ACL 资源未注册或未启用：%{1}。超管也不会自动放行，请先执行 setup:upgrade 收集权限。',
                [$sourceId !== '' ? $sourceId : '(empty)'],
            );
        }
        if ($roleId === 1) {
            return (string)__(
                '当前站点关闭了超管 ACL 旁路，且角色未授权资源：%{1}。',
                [$sourceId],
            );
        }

        return (string)__('当前后台账号无权执行该操作。');
    }

    private function isRegisteredBackendSource(string $sourceId): bool
    {
        /** @var Acl $resource */
        $resource = ObjectManager::getInstance(Acl::class, [], false)
            ->fields([
                Acl::schema_fields_SOURCE_ID,
                Acl::schema_fields_IS_ENABLE,
                Acl::schema_fields_IS_BACKEND,
            ])
            ->where(Acl::schema_fields_SOURCE_ID, $sourceId)
            ->find()
            ->fetch();

        return \hash_equals($sourceId, $resource->getSourceId())
            && (bool)$resource->getData(Acl::schema_fields_IS_ENABLE)
            && (bool)$resource->getData(Acl::schema_fields_IS_BACKEND);
    }
}
