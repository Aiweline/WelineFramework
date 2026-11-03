<?php

declare(strict_types=1);

namespace Weline\Frontend\Integration\Framework;

use Weline\Framework\Runtime\StorefrontGenerationIdentityInterface;
use Weline\Frontend\Helper\StorefrontGenerationIdentity;

/**
 * Publishes the storefront generation identity through the Framework contract.
 */
final class StorefrontGenerationIdentityProvider implements StorefrontGenerationIdentityInterface
{
    public function currentVersionId(): string
    {
        return StorefrontGenerationIdentity::currentVersionId();
    }
}
