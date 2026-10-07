<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

/** Website scope: each post belongs to exactly one website_id; 0 = default site only, not a global fallback. */
final class BlogWebsiteScope
{
    /**
     * @return list<int>
     */
    public static function websiteIdsForQuery(int $scopedWebsiteId): array
    {
        return [max(0, $scopedWebsiteId)];
    }

    public static function matchesWebsite(int $scopedWebsiteId, int $documentWebsiteId): bool
    {
        return max(0, $scopedWebsiteId) === max(0, $documentWebsiteId);
    }
}
