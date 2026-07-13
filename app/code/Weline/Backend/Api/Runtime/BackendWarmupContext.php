<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Service\BackendWarmupContext as InternalBackendWarmupContext;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticableInterface;

/**
 * Read-only public view of the backend warmup request context.
 *
 * Context installation and user loading stay internal to Weline_Backend. Other
 * modules may only inspect the already-installed request state.
 */
final class BackendWarmupContext
{
    public static function isInternalWarmupRequest(?Request $request = null): bool
    {
        return InternalBackendWarmupContext::isInternalWarmupRequest($request);
    }

    public static function isActive(): bool
    {
        return InternalBackendWarmupContext::isActive();
    }

    public static function currentUser(): ?AuthenticableInterface
    {
        return InternalBackendWarmupContext::currentUser();
    }

    public static function currentUserId(): int
    {
        return InternalBackendWarmupContext::currentUserId();
    }
}
