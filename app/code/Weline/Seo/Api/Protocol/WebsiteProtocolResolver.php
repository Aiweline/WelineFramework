<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Protocol;

use Weline\Seo\Api\Protocol\Data\WebsiteContext;

final class WebsiteProtocolResolver implements WebsiteProtocolResolverInterface
{
    public function __construct(
        private readonly \Weline\Seo\Service\Protocol\WebsiteProtocolResolver $resolver,
    ) {
    }

    public function currentWebsite(): WebsiteContext
    {
        $website = $this->resolver->currentWebsite();

        return new WebsiteContext(
            (int)($website['website_id'] ?? $website['id'] ?? 0),
            (string)($website['url'] ?? ''),
            (string)($website['name'] ?? ''),
        );
    }

    public function currentBaseUrl(): string
    {
        return $this->resolver->currentBaseUrl();
    }
}
