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
        $current = $this->websiteDirectory->currentWebsite();
        if (array_key_exists('website_id', $current) || array_key_exists('id', $current)) {
            $websiteId = (int)($current['website_id'] ?? $current['id']);
            if ($websiteId >= 0) {
                $configured = $this->websiteDirectory->getWebsiteById($websiteId);
                if (is_array($configured)) {
                    $merged = array_replace($current, $configured);
                    $merged['url'] = $this->websiteDirectory->effectivePublicBaseUrl($merged);
                    return $merged;
                }
            }
        }

        $current['url'] = $this->websiteDirectory->effectivePublicBaseUrl($current);
        return $current;
    }

    public function currentBaseUrl(): string
    {
        return $this->websiteDirectory->effectivePublicBaseUrl($this->currentWebsite());
    }

    /**
     * @param array<string, mixed>|null $website
     * @return list<array<string, mixed>>
     */
    public function listPublicOrigins(?array $website = null): array
    {
        return $this->websiteDirectory->listPublicOrigins($website ?? $this->currentWebsite());
    }
}
