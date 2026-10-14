<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

/**
 * Soft invalidation after language *order* changes.
 *
 * Refreshes language catalog caches (local HotCache / WebsiteData + CDN/WLS tags for
 * BinQuery language switcher). Does NOT emit website-level w_changed and does NOT
 * run legacy full cacheClear / FPC wipe.
 */
final class LanguageCatalogInvalidationService
{
    public const CDN_TAG_SWITCHER_CATALOG = 'binquery-i18n-getLanguageSwitcherCatalog';

    public function invalidateAfterReorder(int $websiteId): void
    {
        $websiteId = \max(0, $websiteId);

        try {
            WebsiteData::languageCodesForWebsite($websiteId, true);
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\I18n\Taglib\LanguageSwitcher::class)) {
                \Weline\I18n\Taglib\LanguageSwitcher::clearProcessCaches();
            }
        } catch (\Throwable) {
        }

        // Soft reorder must not wipe the whole i18n pool — that thrash can poke
        // Frontend Scope/generation stamp checks (user-facing 409 toast) even on
        // backend pages that embed language widgets. Targeted clears above + bump below.

        try {
            /** @var WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(WebsiteLanguage::class);
            ObjectManager::getInstance(WebsiteCacheInvalidationService::class)
                ->bumpLanguageCatalogOnly($wl->getConnection(), $websiteId);
        } catch (\Throwable) {
        }

        $this->purgeLocalAndCdnLanguageCatalogTags($websiteId);
    }

    private function purgeLocalAndCdnLanguageCatalogTags(int $websiteId): void
    {
        $tags = [
            self::CDN_TAG_SWITCHER_CATALOG,
            'website-' . $websiteId . '-language-catalog',
        ];

        if (\class_exists(\Weline\Server\Service\MemoryCacheService::class)) {
            foreach ($tags as $tag) {
                try {
                    \Weline\Server\Service\MemoryCacheService::purgeByTag($tag);
                } catch (\Throwable) {
                }
            }
        }

        if (!\class_exists(\Weline\Cdn\Service\CachePurger::class)
            || !\class_exists(\Weline\Cdn\Model\Domain::class)
        ) {
            return;
        }
        try {
            /** @var \Weline\Cdn\Model\Domain $domainModel */
            $domainModel = ObjectManager::getInstance(\Weline\Cdn\Model\Domain::class);
            $domains = (clone $domainModel)->reset()
                ->where(\Weline\Cdn\Model\Domain::schema_fields_SITE_ID, $websiteId)
                ->where(\Weline\Cdn\Model\Domain::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
            if ($domains === []) {
                return;
            }
            /** @var \Weline\Cdn\Service\CachePurger $purger */
            $purger = ObjectManager::getInstance(\Weline\Cdn\Service\CachePurger::class);
            foreach ($domains as $domain) {
                if (!$domain instanceof \Weline\Cdn\Model\Domain) {
                    continue;
                }
                $domainId = (int)$domain->getData(\Weline\Cdn\Model\Domain::schema_fields_DOMAIN_ID);
                if ($domainId < 1) {
                    continue;
                }
                try {
                    $purger->purge($domainId, 'tags', ['tags' => $tags]);
                } catch (\Throwable) {
                    // Tag purge is best-effort; local soft clear already applied.
                }
            }
        } catch (\Throwable) {
        }
    }
}
