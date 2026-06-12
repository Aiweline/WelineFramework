<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Protocol;

use Weline\Seo\Service\SeoWebsiteDirectory;

class WebsiteProtocolResolver
{
    public function __construct(private readonly SeoWebsiteDirectory $websiteDirectory)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function currentWebsite(): array
    {
        return $this->websiteDirectory->currentWebsite();
    }

    public function currentBaseUrl(): string
    {
        return $this->websiteDirectory->currentBaseUrl();
    }
}
