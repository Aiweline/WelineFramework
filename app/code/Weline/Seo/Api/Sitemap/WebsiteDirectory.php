<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

use Weline\Seo\Api\Sitemap\Data\Website;
use Weline\Seo\Service\SeoWebsiteDirectory;

final class WebsiteDirectory implements WebsiteDirectoryInterface
{
    public function __construct(private readonly SeoWebsiteDirectory $directory)
    {
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->directory->listWebsites() as $row) {
            $website = $this->project($row);
            if ($website !== null) {
                $result[] = $website;
            }
        }

        return $result;
    }

    public function get(int $websiteId): ?Website
    {
        $row = $this->directory->getWebsiteById($websiteId);

        return is_array($row) ? $this->project($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function project(array $row): ?Website
    {
        if (!array_key_exists('website_id', $row) && !array_key_exists('id', $row)) {
            return null;
        }

        $id = (int)($row['website_id'] ?? $row['id'] ?? 0);
        if ($id < 0) {
            return null;
        }

        return new Website(
            $id,
            (string)($row['name'] ?? ''),
            (string)($row['code'] ?? ''),
            (string)($row['url'] ?? ''),
        );
    }
}
