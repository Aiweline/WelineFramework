<?php

declare(strict_types=1);

namespace Weline\Help\Extends\Module\Weline_Cms\UriInterceptSkip;

use Weline\Cms\Api\Uri\CmsUriInterceptSkipInterface;
use Weline\Help\Api\Uri\HelpNamespace;

final class HelpUriInterceptSkip implements CmsUriInterceptSkipInterface
{
    public function ownerModule(): string
    {
        return 'Weline_Help';
    }

    public function shouldSkipIntercept(string $identifier, array $context = []): bool
    {
        return HelpNamespace::isHelpIdentifier(trim(strtolower($identifier), '/ '));
    }
}
