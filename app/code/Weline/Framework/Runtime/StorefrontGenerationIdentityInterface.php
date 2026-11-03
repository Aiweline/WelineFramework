<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional storefront generation identity supplied by the frontend module.
 */
interface StorefrontGenerationIdentityInterface
{
    public function currentVersionId(): string;
}
