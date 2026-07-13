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
