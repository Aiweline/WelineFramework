<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Head;

use Weline\Seo\Api\Head\Data\PageContext;

interface PageContextResolverInterface
{
    /** @param array<string, mixed> $options */
    public function resolve(mixed $template, array $options = []): PageContext;
}
