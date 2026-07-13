<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Head;

use Weline\Seo\Api\Head\Data\PageContext;
use Weline\Seo\Service\Head\PageSeoContextResolver;

final class PageContextResolver implements PageContextResolverInterface
{
    public function __construct(private readonly PageSeoContextResolver $resolver)
    {
    }

    public function resolve(mixed $template, array $options = []): PageContext
    {
        $context = $this->resolver->resolve($template, $options);

        return new PageContext((string)($context['site_name'] ?? ''));
    }
}
