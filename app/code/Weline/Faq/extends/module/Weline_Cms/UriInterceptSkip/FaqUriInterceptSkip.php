<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Cms\UriInterceptSkip;

use Weline\Cms\Api\Uri\CmsUriInterceptSkipInterface;
use Weline\Faq\Api\Uri\FaqNamespace;

final class FaqUriInterceptSkip implements CmsUriInterceptSkipInterface
{
    public function ownerModule(): string
    {
        return 'Weline_Faq';
    }

    public function shouldSkipIntercept(string $identifier, array $context = []): bool
    {
        return FaqNamespace::isFaqIdentifier(trim(strtolower($identifier), '/ '));
    }
}
