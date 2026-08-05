<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Runtime;

use Weline\Ai\Middleware\TenantContext;
use Weline\Ai\Middleware\TenantIsolation;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        $failures = [];
        try {
            TenantContext::resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'tenant_context_state', $throwable);
        }

        try {
            ObjectManager::removeInstance(TenantContext::class);
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'tenant_context_instance', $throwable);
        }

        try {
            ObjectManager::removeInstance(TenantIsolation::class);
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'tenant_isolation_instance', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('ai_request_resetter', $failures);
        }
    }
}
