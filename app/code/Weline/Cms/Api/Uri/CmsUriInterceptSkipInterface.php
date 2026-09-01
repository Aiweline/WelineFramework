<?php

declare(strict_types=1);

namespace Weline\Cms\Api\Uri;

/**
 * Allows modules to declare URI identifiers that CMS must not intercept
 * in ProcessCmsPageUriBefore (route-layer catch-all).
 */
interface CmsUriInterceptSkipInterface
{
    public function ownerModule(): string;

    /**
     * @param array<string, mixed> $context
     */
    public function shouldSkipIntercept(string $identifier, array $context = []): bool;
}
