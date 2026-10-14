<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Localization\ResolvedLanguageCatalogInterface;
use Weline\Websites\Model\ScopeLanguage;
use Weline\Websites\Model\ScopeLanguageOverride;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Service\WebsiteCacheInvalidationService;

/**
 * Resolve ordered locale codes: channel override → store override → website → platform ranks.
 */
final class ResolvedLanguageCatalog implements ResolvedLanguageCatalogInterface
{
    public function getResolvedLanguageCodes(
        int $websiteId,
        ?int $storeId = null,
        ?int $channelId = null,
    ): array {
        $websiteId = \max(0, $websiteId);

        if ($storeId !== null && $channelId !== null && $channelId >= 0) {
            if ($this->hasOverride($websiteId, $storeId, $channelId)) {
                return $this->loadOverrideCodes($websiteId, $storeId, $channelId);
            }
        }

        if ($storeId !== null) {
            if ($this->hasOverride($websiteId, $storeId, null)) {
                return $this->loadOverrideCodes($websiteId, $storeId, null);
            }
        }

        return $this->loadWebsiteCodesOrdered($websiteId);
    }

    public function hasOverride(int $websiteId, int $storeId, ?int $channelId): bool
    {
        $channelKey = $this->normalizeChannelKey($channelId);
        try {
            /** @var ScopeLanguageOverride $model */
            $model = ObjectManager::getInstance(ScopeLanguageOverride::class);
            $row = $model->clearQuery()
                ->where(ScopeLanguageOverride::schema_fields_WEBSITE_ID, $websiteId)
                ->where(ScopeLanguageOverride::schema_fields_STORE_ID, $storeId)
                ->where(ScopeLanguageOverride::schema_fields_CHANNEL_ID, $channelKey)
                ->find()
                ->fetchArray();
            if (\is_array($row) && \array_is_list($row)) {
                $row = $row[0] ?? null;
            }
            if (!\is_array($row)) {
                return false;
            }

            return (int)($row[ScopeLanguageOverride::schema_fields_HAS_OVERRIDE] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function saveOverride(
        int $websiteId,
        int $storeId,
        ?int $channelId,
        array $languageCodes,
    ): void {
        $websiteId = \max(0, $websiteId);
        $channelKey = $this->normalizeChannelKey($channelId);
        $ordered = $this->normalizeCodeList($languageCodes);

        /** @var ScopeLanguageOverride $overrideModel */
        $overrideModel = ObjectManager::getInstance(ScopeLanguageOverride::class);
        /** @var ScopeLanguage $langModel */
        $langModel = ObjectManager::getInstance(ScopeLanguage::class);

        $existing = $overrideModel->clearQuery()
            ->where(ScopeLanguageOverride::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ScopeLanguageOverride::schema_fields_STORE_ID, $storeId)
            ->where(ScopeLanguageOverride::schema_fields_CHANNEL_ID, $channelKey)
            ->find()
            ->fetch();
        if ($existing && (int)$existing->getData(ScopeLanguageOverride::schema_fields_ID) > 0) {
            $existing->setData(ScopeLanguageOverride::schema_fields_HAS_OVERRIDE, 1)->save();
        } else {
            $overrideModel->clearData(true)
                ->setData(ScopeLanguageOverride::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(ScopeLanguageOverride::schema_fields_STORE_ID, $storeId)
                ->setData(ScopeLanguageOverride::schema_fields_CHANNEL_ID, $channelKey)
                ->setData(ScopeLanguageOverride::schema_fields_HAS_OVERRIDE, 1)
                ->save();
        }

        $langModel->clearQuery()
            ->where(ScopeLanguage::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ScopeLanguage::schema_fields_STORE_ID, $storeId)
            ->where(ScopeLanguage::schema_fields_CHANNEL_ID, $channelKey)
            ->delete()
            ->fetch();

        if ($ordered !== []) {
            $rows = [];
            $sort = 0;
            foreach ($ordered as $code) {
                $rows[] = [
                    ScopeLanguage::schema_fields_WEBSITE_ID => $websiteId,
                    ScopeLanguage::schema_fields_STORE_ID => $storeId,
                    ScopeLanguage::schema_fields_CHANNEL_ID => $channelKey,
                    ScopeLanguage::schema_fields_LANGUAGE_CODE => $code,
                    ScopeLanguage::schema_fields_SORT_ORDER => $sort,
                ];
                $sort++;
            }
            $langModel->clearData(true)->insert($rows)->fetch();
        }

        $this->expandWebsiteUnionAndWarmup($websiteId, $ordered);
        $this->invalidateWebsite($websiteId);
    }

    public function clearOverride(int $websiteId, int $storeId, ?int $channelId): void
    {
        $websiteId = \max(0, $websiteId);
        $channelKey = $this->normalizeChannelKey($channelId);

        /** @var ScopeLanguage $langModel */
        $langModel = ObjectManager::getInstance(ScopeLanguage::class);
        $langModel->clearQuery()
            ->where(ScopeLanguage::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ScopeLanguage::schema_fields_STORE_ID, $storeId)
            ->where(ScopeLanguage::schema_fields_CHANNEL_ID, $channelKey)
            ->delete()
            ->fetch();

        /** @var ScopeLanguageOverride $overrideModel */
        $overrideModel = ObjectManager::getInstance(ScopeLanguageOverride::class);
        $overrideModel->clearQuery()
            ->where(ScopeLanguageOverride::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ScopeLanguageOverride::schema_fields_STORE_ID, $storeId)
            ->where(ScopeLanguageOverride::schema_fields_CHANNEL_ID, $channelKey)
            ->delete()
            ->fetch();

        $this->invalidateWebsite($websiteId);
    }

    /**
     * @return list<string>
     */
    private function loadOverrideCodes(int $websiteId, int $storeId, ?int $channelId): array
    {
        $channelKey = $this->normalizeChannelKey($channelId);
        try {
            /** @var ScopeLanguage $model */
            $model = ObjectManager::getInstance(ScopeLanguage::class);
            $items = $model->clearQuery()
                ->where(ScopeLanguage::schema_fields_WEBSITE_ID, $websiteId)
                ->where(ScopeLanguage::schema_fields_STORE_ID, $storeId)
                ->where(ScopeLanguage::schema_fields_CHANNEL_ID, $channelKey)
                ->order(ScopeLanguage::schema_fields_SORT_ORDER, 'ASC')
                ->order(ScopeLanguage::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $codes = [];
            foreach ($items as $item) {
                $code = \trim((string)$item->getData(ScopeLanguage::schema_fields_LANGUAGE_CODE));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }

            return $codes;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function loadWebsiteCodesOrdered(int $websiteId): array
    {
        try {
            /** @var WebsiteLanguage $model */
            $model = ObjectManager::getInstance(WebsiteLanguage::class);
            return $model->fetchAssociationCodesFromDatabase($websiteId);
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @param list<string> $codes
     */
    private function expandWebsiteUnionAndWarmup(int $websiteId, array $codes): void
    {
        if ($codes === []) {
            return;
        }
        try {
            /** @var WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(WebsiteLanguage::class);
            $existing = $wl->fetchAssociationCodesFromDatabase($websiteId);
            $merged = $existing;
            $known = [];
            foreach ($existing as $c) {
                $known[\strtolower($c)] = true;
            }
            $added = false;
            foreach ($codes as $code) {
                $key = \strtolower($code);
                if (!isset($known[$key])) {
                    $merged[] = $code;
                    $known[$key] = true;
                    $added = true;
                }
            }
            if ($added) {
                // Preserve existing website order; append new codes at end.
                $wl->setWebsiteLanguages($websiteId, $merged);
            } elseif (\class_exists(\Weline\I18n\Service\WebsiteLocaleTranslationSync::class)) {
                ObjectManager::getInstance(\Weline\I18n\Service\WebsiteLocaleTranslationSync::class)
                    ->onWebsiteLocalesChanged($codes, 'scope_language_override');
            }
        } catch (\Throwable) {
        }
    }

    private function invalidateWebsite(int $websiteId): void
    {
        try {
            ObjectManager::getInstance(WebsiteCacheInvalidationService::class)->invalidateWebsite(
                ObjectManager::getInstance(WebsiteLanguage::class)->getConnection(),
                $websiteId,
                ['language'],
            );
        } catch (\Throwable) {
        }
    }

    private function normalizeChannelKey(?int $channelId): int
    {
        if ($channelId === null) {
            return ScopeLanguageOverride::CHANNEL_ID_STORE_SCOPE;
        }

        return \max(0, $channelId);
    }

    /**
     * @param list<string>|array<int|string, mixed> $languageCodes
     * @return list<string>
     */
    private function normalizeCodeList(array $languageCodes): array
    {
        $out = [];
        $seen = [];
        foreach ($languageCodes as $code) {
            $code = \trim(\str_replace('-', '_', (string)$code));
            if ($code === '') {
                continue;
            }
            $key = \strtolower($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $code;
        }

        return $out;
    }
}
