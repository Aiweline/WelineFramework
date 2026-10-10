<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

/**
 * Marketing 实体网站范围：-1=全部站；>=0=具体站（含默认站 0）。
 */
final class MarketingWebsiteScope
{
    public const ENTITY_ALL = -1;

    public static function normalizeEntity(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::ENTITY_ALL;
        }
        $id = (int)$raw;

        return $id < 0 ? self::ENTITY_ALL : $id;
    }

    /**
     * 后台选中站 W 时：该站 ∪ 全部(-1)。
     *
     * @return list<int>
     */
    public static function listMatchIds(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);

        return [$websiteId, self::ENTITY_ALL];
    }

    public static function label(int $websiteId): string
    {
        if ($websiteId < 0) {
            return (string)__('全部');
        }

        return '#' . $websiteId;
    }

    public static function appliesToSite(int $campaignWebsiteId, int $siteWebsiteId): bool
    {
        if ($campaignWebsiteId < 0) {
            return true;
        }

        return $campaignWebsiteId === max(0, $siteWebsiteId);
    }
}
