<?php

declare(strict_types=1);

namespace Weline\Backend\Integration\Acl;

use Weline\Acl\Api\Auth\BackendIdentityContextProviderInterface;
use Weline\Backend\Api\Runtime\BackendWarmupContext;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;

final class BackendIdentityContextProvider implements BackendIdentityContextProviderInterface
{
    public function getAclContext(int $userId): ?array
    {
        return BackendUser::getAclContext($userId);
    }

    public function currentAclContext(): ?array
    {
        $userId = BackendWarmupContext::isActive()
            ? BackendWarmupContext::currentUserId()
            : 0;
        if ($userId <= 0) {
            try {
                $userId = (int)(\Weline\Framework\Session\SessionFactory::getInstance()
                    ->createBackendSession()
                    ->getUserId() ?? 0);
            } catch (\Throwable) {
                return null;
            }
        }

        return $userId > 0 ? $this->getAclContext($userId) : null;
    }

    public function currentWarmupUserId(Request $request): int
    {
        if (!BackendWarmupContext::isInternalWarmupRequest($request)
            || !BackendWarmupContext::isActive()
        ) {
            return 0;
        }
        return BackendWarmupContext::currentUserId();
    }
}
