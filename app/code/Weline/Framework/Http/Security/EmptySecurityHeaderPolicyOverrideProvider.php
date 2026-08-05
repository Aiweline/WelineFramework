<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Framework-only baseline provider. Weline_SystemConfig replaces this
 * implementation when scoped security policy storage is installed.
 */
final class EmptySecurityHeaderPolicyOverrideProvider implements SecurityHeaderPolicyOverrideProviderInterface
{
    public function currentOverride(): array
    {
        return [];
    }
}
