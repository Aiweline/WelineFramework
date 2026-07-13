<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Service\BackendWarmupContext;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\BackendWarmupProviderInterface;

final class BackendWarmupProvider implements BackendWarmupProviderInterface
{
    public function resolveWarmupUserId(): int
    {
        return BackendWarmupContext::resolveWarmupUserId();
    }

    public function installRequestContext(Request $request): void
    {
        if (!BackendWarmupContext::isInternalWarmupRequest($request)) {
            BackendWarmupContext::clear();
            return;
        }
        $user = BackendWarmupContext::resolveWarmupUser($request);
        if ($user === null) {
            BackendWarmupContext::clear();
            return;
        }
        BackendWarmupContext::installForUser($user);
    }

    public function shouldBypassLogin(Request $request): bool
    {
        return BackendWarmupContext::isInternalWarmupRequest($request)
            && BackendWarmupContext::isActive();
    }
}
