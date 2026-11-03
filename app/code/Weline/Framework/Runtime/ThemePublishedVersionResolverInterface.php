<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional storefront generation acceptor supplied by the theme module.
 */
interface ThemePublishedVersionResolverInterface
{
    public function isAcceptableStorefrontGenerationId(string $clientVersionId): bool;
}
