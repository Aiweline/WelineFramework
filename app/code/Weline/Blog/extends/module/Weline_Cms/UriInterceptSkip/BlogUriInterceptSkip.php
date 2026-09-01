<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Cms\UriInterceptSkip;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Cms\Api\Uri\CmsUriInterceptSkipInterface;

final class BlogUriInterceptSkip implements CmsUriInterceptSkipInterface
{
    public function ownerModule(): string
    {
        return 'Weline_Blog';
    }

    public function shouldSkipIntercept(string $identifier, array $context = []): bool
    {
        return BlogNamespace::isBlogIdentifier(trim(strtolower($identifier), '/ '));
    }
}
