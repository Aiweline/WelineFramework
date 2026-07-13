<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Runtime;

use Weline\Ai\Middleware\TenantContext;
use Weline\Ai\Middleware\TenantIsolation;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        TenantContext::resetRequestState();
        ObjectManager::removeInstance(TenantContext::class);
        ObjectManager::removeInstance(TenantIsolation::class);
    }
}
