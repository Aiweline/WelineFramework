<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Contract alias for {@see StorefrontRenderContextInstaller::freezeCurrent()}.
 *
 * Prefer Installer::installOnce() at call sites; Resolver exists for naming parity
 * with StorefrontCacheKeyContextResolver::freezeCurrent().
 */
final class StorefrontRenderContextResolver
{
    public function __construct(
        private readonly ?StorefrontRenderContextInstaller $installer = null,
    ) {
    }

    public function freezeCurrent(): StorefrontRenderContext
    {
        return ($this->installer ?? new StorefrontRenderContextInstaller())->freezeCurrent();
    }
}
