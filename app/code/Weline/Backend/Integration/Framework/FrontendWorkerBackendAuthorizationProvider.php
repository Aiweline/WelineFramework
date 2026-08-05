<?php

declare(strict_types=1);

namespace Weline\Backend\Integration\Framework;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
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
            || $actor->getRoleId() <= 0
            || !$this->authorizationService->isSourceAllowed($actor->getRoleId(), $sourceId)) {
            throw new FrontendWorkerBackendAuthorizationException(
                'backend_acl_denied',
                403,
                (string)__('当前后台账号无权执行该操作。'),
            );
        }
    }
}
