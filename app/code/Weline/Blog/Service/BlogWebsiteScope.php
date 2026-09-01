<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

/** Website scope helpers: website_id=0 means global/default content. */
final class BlogWebsiteScope
{
    /**
     * @return list<int>
     */
    public static function websiteIdsForQuery(int $scopedWebsiteId): array
    {
        if ($scopedWebsiteId <= 0) {
            return [0];
        }

        return [$scopedWebsiteId, 0];
    }

    public static function matchesWebsite(int $scopedWebsiteId, int $documentWebsiteId): bool
    {
        if ($documentWebsiteId <= 0) {
            return true;
        }

        return $scopedWebsiteId <= 0 || $documentWebsiteId === $scopedWebsiteId;
    }
}
