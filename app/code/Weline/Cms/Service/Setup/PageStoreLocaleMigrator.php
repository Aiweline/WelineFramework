<?php

declare(strict_types=1);

namespace Weline\Cms\Service\Setup;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Data half of add_cms_page_store_locale_variants_20260822-v1.1.0.
 * Schema columns/indexes are declared on PageLocale and planned by RSSE.
 */
final class PageStoreLocaleMigrator
{
    public function __construct(private readonly ConnectionFactory $connectionFactory)
    {
    }

    public function migrate(): void
    {
        $connection = $this->connectionFactory->getConnection();
        $localeTable = ObjectManager::getInstance(PageLocale::class)->getTable();
        if (!$connection->tableExist($localeTable)) {
            return;
        }

        $stores = ObjectManager::getInstance(StoreCatalogInterface::class);
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        foreach ($websites->all() as $website) {
            if ($stores->defaultStore($website->id) === null) {
                throw new \RuntimeException((string)__(
                    '网站 %{1}（%{2}）缺少默认店铺，CMS 1.1.0 升级已停止。',
                    [$website->id, $website->code],
                ));
            }
        }

        $pagePrototype = ObjectManager::getInstance(Page::class);
        $localePrototype = ObjectManager::getInstance(PageLocale::class);
        // RSSE expand guarantees the store_id column exists before transform.
        $this->preflight($stores, $pagePrototype, $localePrototype, true);
        $this->migratePages($stores, $pagePrototype, $localePrototype);
        $this->assertVariantOwnership($stores, $pagePrototype, $localePrototype);
    }

    private function preflight(
        StoreCatalogInterface $stores,
        Page $pagePrototype,
        PageLocale $localePrototype,
        bool $storeColumnExists,
    ): void {
        $lastPageId = 0;
        while (true) {
            $pages = (clone $pagePrototype)->clearData()->reset()
                ->where(Page::schema_fields_ID, $lastPageId, '>')
                ->order(Page::schema_fields_ID, 'ASC')
                ->limit(200)
                ->select()
                ->fetch()
                ->getItems();
            if ($pages === []) {
                break;
            }
            $nextPageId = $lastPageId;
            foreach ($pages as $page) {
                if (!$page instanceof Page || $page->getPageId() <= $lastPageId) {
                    continue;
                }
                $nextPageId = max($nextPageId, $page->getPageId());
                $defaultStore = $stores->defaultStore($page->getWebsiteId());
                if ($defaultStore === null) {
                    throw new \RuntimeException((string)__('CMS 页面所属网站缺少默认店铺，升级已停止。'));
                }
                $websiteStores = $stores->byWebsite($page->getWebsiteId());
                if (count($websiteStores) > 500) {
                    throw new \RuntimeException((string)__('单个网站的店铺数量超过 CMS 安全升级上限。'));
                }
                foreach ($websiteStores as $store) {
                    $this->assertStoreCode((string)$store->code);
                }
                $rows = (clone $localePrototype)->clearData()->reset()
                    ->where(PageLocale::schema_fields_PAGE_ID, $page->getPageId())
                    ->order(PageLocale::schema_fields_ID, 'ASC')
                    ->limit(1001)
                    ->select()
                    ->fetch()
                    ->getItems();
                if (count($rows) > 1000) {
                    throw new \RuntimeException((string)__('单个 CMS 页面的历史语言变体超过升级上限。'));
                }
                $identities = [];
                foreach ($rows as $row) {
                    if (!$row instanceof PageLocale) {
                        throw new \RuntimeException((string)__('CMS 页面语言变体数据无效，升级已停止。'));
                    }
                    $storeId = $row->getStoreId() > 0 ? $row->getStoreId() : $defaultStore->id;
                    $store = $stores->byId($storeId);
                    if ($store === null || $store->websiteId !== $page->getWebsiteId()) {
                        throw new \RuntimeException((string)__('CMS 店铺语言变体存在跨网站归属，升级已停止。'));
                    }
                    $localeCode = trim($row->getLocaleCode());
                    $this->assertVariantContentCanMigrate(
                        $row,
                        $page,
                        $storeId,
                        (string)$store->code,
                        $storeColumnExists && $row->getStoreId() > 0,
                    );
                    $identity = $storeId . "\0" . $localeCode;
                    if (isset($identities[$identity])) {
                        throw new \RuntimeException((string)__('CMS 旧语言行映射后发生店铺语言冲突，升级已停止。'));
                    }
                    $identities[$identity] = true;
                }
            }
            if ($nextPageId <= $lastPageId) {
                throw new \RuntimeException((string)__('CMS 变体升级预检无数据进展。'));
            }
            $lastPageId = $nextPageId;
            SchedulerSystem::yield();
            if (count($pages) < 200) {
                break;
            }
        }

        // Per-page scans above cannot observe a locale row whose page was
        // already removed. Audit the locale table independently before DDL.
        $lastLocaleId = 0;
        while (true) {
            $rows = (clone $localePrototype)->clearData()->reset()
                ->where(PageLocale::schema_fields_ID, $lastLocaleId, '>')
                ->order(PageLocale::schema_fields_ID, 'ASC')
                ->limit(500)
                ->select()
                ->fetch()
                ->getItems();
            if ($rows === []) {
                return;
            }
            $nextLocaleId = $lastLocaleId;
            $pageIds = [];
            foreach ($rows as $row) {
                if (!$row instanceof PageLocale || $row->getPageLocaleId() <= $lastLocaleId) {
                    continue;
                }
                $nextLocaleId = max($nextLocaleId, $row->getPageLocaleId());
                $pageIds[$row->getPageId()] = true;
            }
            foreach (array_keys($pageIds) as $pageId) {
                $page = (clone $pagePrototype)->clearData()->reset()->load((int)$pageId);
                if (!$page instanceof Page || $page->getPageId() <= 0) {
                    throw new \RuntimeException((string)__('CMS 页面语言变体存在孤立页面归属，升级已停止。'));
                }
            }
            if ($nextLocaleId <= $lastLocaleId) {
                throw new \RuntimeException((string)__('CMS 变体孤立数据预检无数据进展。'));
            }
            $lastLocaleId = $nextLocaleId;
            SchedulerSystem::yield();
            if (count($rows) < 500) {
                return;
            }
        }
    }

    private function assertStoreCode(string $storeCode): void
    {
        $storeCode = trim($storeCode);
        if ($storeCode === ''
            || strlen($storeCode) > 64
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $storeCode) !== 1
        ) {
            throw new \RuntimeException((string)__('CMS 店铺代码无法用于 Store+Locale 变体升级。'));
        }
    }

    private function assertVariantContentCanMigrate(
        PageLocale $row,
        Page $page,
        int $targetStoreId,
        string $targetStoreCode,
        bool $alreadyMigrated,
    ): void {
        $this->assertStoreCode($targetStoreCode);
        $localeCode = trim($row->getLocaleCode());
        $title = trim($row->getTitle());
        $origin = trim($row->getOrigin());
        $sourceHash = strtolower(trim($row->getSourceHash()));
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        if ($row->getPageLocaleId() <= 0
            || $row->getPageId() !== $page->getPageId()
            || $targetStoreId < 0
            || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $localeCode) !== 1
            || strlen($localeCode) > 16
            || preg_match('//u', $title) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $title) === 1
            || $titleLength > 255
            || !in_array($origin, PageLocale::ORIGINS, true)
            || ($sourceHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1)
        ) {
            throw new \RuntimeException((string)__('CMS 页面语言变体存在无法迁移的内容，升级已停止。'));
        }

        $status = $alreadyMigrated
            ? $row->getVariantStatus()
            : match ($page->getStatus()) {
                Page::STATUS_PUBLISHED => PageLocale::VARIANT_STATUS_PUBLISHED,
                Page::STATUS_DISABLED => PageLocale::VARIANT_STATUS_DISABLED,
                default => PageLocale::VARIANT_STATUS_DRAFT,
            };
        $translationState = $alreadyMigrated
            ? $row->getTranslationState()
            : ($origin === PageLocale::ORIGIN_AI
                ? PageLocale::TRANSLATION_STATE_DRAFT
                : PageLocale::TRANSLATION_STATE_REVIEWED);
        $validationState = $alreadyMigrated
            ? $row->getValidationState()
            : PageLocale::VALIDATION_STATE_LEGACY_UNVERIFIED;
        if (!in_array($status, PageLocale::VARIANT_STATUSES, true)
            || !in_array($translationState, PageLocale::TRANSLATION_STATES, true)
            || !in_array($validationState, PageLocale::VALIDATION_STATES, true)
            || ($status === PageLocale::VARIANT_STATUS_PUBLISHED && $title === '')
            || ($status === PageLocale::VARIANT_STATUS_PUBLISHED
                && $translationState !== PageLocale::TRANSLATION_STATE_REVIEWED
                && $validationState !== PageLocale::VALIDATION_STATE_LEGACY_UNVERIFIED)
            || ($status === PageLocale::VARIANT_STATUS_PUBLISHED
                && !in_array($validationState, [
                    PageLocale::VALIDATION_STATE_VALID,
                    PageLocale::VALIDATION_STATE_LEGACY_UNVERIFIED,
                ], true))
        ) {
            throw new \RuntimeException((string)__('CMS 页面语言变体状态无法安全迁移，升级已停止。'));
        }
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function migratePages(
        StoreCatalogInterface $stores,
        Page $pagePrototype,
        PageLocale $localePrototype,
    ): void {
        $lastPageId = 0;
        while (true) {
            $pages = (clone $pagePrototype)->clearData()->reset()
                ->where(Page::schema_fields_ID, $lastPageId, '>')
                ->order(Page::schema_fields_ID, 'ASC')
                ->limit(200)
                ->select()
                ->fetch()
                ->getItems();
            if ($pages === []) {
                return;
            }

            $nextPageId = $lastPageId;
            foreach ($pages as $page) {
                if (!$page instanceof Page || $page->getPageId() <= $lastPageId) {
                    continue;
                }
                $nextPageId = max($nextPageId, $page->getPageId());
                $defaultStore = $stores->defaultStore($page->getWebsiteId());
                if ($defaultStore === null) {
                    throw new \RuntimeException((string)__('CMS 页面所属网站缺少默认店铺，升级已停止。'));
                }
                $existing = (clone $localePrototype)->clearData()->reset()
                    ->where(PageLocale::schema_fields_PAGE_ID, $page->getPageId())
                    ->order(PageLocale::schema_fields_ID, 'ASC')
                    ->limit(1001)
                    ->select()
                    ->fetch()
                    ->getItems();
                if (count($existing) > 1000) {
                    throw new \RuntimeException((string)__('单个 CMS 页面的历史语言变体超过升级上限。'));
                }
                foreach ($existing as $row) {
                    // A rerun must not collapse already-migrated Store variants
                    // back onto the default Store.
                    if ($row instanceof PageLocale && $row->getStoreId() <= 0 && $row->getStoreId() !== $defaultStore->id) {
                        if ($this->variantExists(
                            $localePrototype,
                            $page->getPageId(),
                            $defaultStore->id,
                            $row->getLocaleCode(),
                        )) {
                            throw new \RuntimeException((string)__('CMS 旧语言行与默认店铺变体冲突，升级已停止。'));
                        }
                        $this->markLegacyRow($row, $page, $defaultStore->id, $defaultStore->code);
                    }
                }

                $sourceRows = (clone $localePrototype)->clearData()->reset()
                    ->where(PageLocale::schema_fields_PAGE_ID, $page->getPageId())
                    ->where(PageLocale::schema_fields_STORE_ID, $defaultStore->id)
                    ->order(PageLocale::schema_fields_ID, 'ASC')
                    ->limit(1001)
                    ->select()
                    ->fetch()
                    ->getItems();
                if (count($sourceRows) > 1000) {
                    throw new \RuntimeException((string)__('单个 CMS 页面的默认店铺语言变体超过升级上限。'));
                }
                foreach ($stores->byWebsite($page->getWebsiteId()) as $store) {
                    if ($store->id === $defaultStore->id
                        || $store->lifecycleStatus !== 'active'
                        || $store->tombstonedAt !== null
                    ) {
                        continue;
                    }
                    foreach ($sourceRows as $source) {
                        if (!$source instanceof PageLocale
                            || $this->variantExists(
                                $localePrototype,
                                $page->getPageId(),
                                $store->id,
                                $source->getLocaleCode(),
                            )
                        ) {
                            continue;
                        }
                        $copy = clone $localePrototype;
                        $copy->clearData()->setData($source->getData());
                        $copy->unsetData(PageLocale::schema_fields_ID);
                        $copy->setData(PageLocale::schema_fields_STORE_ID, $store->id);
                        $copy->setData(PageLocale::schema_fields_STORE_CODE, $store->code);
                        $copy->setData(PageLocale::schema_fields_TRANSLATION_STATE, PageLocale::TRANSLATION_STATE_DRAFT);
                        $copy->setData(PageLocale::schema_fields_VALIDATION_STATE, PageLocale::VALIDATION_STATE_LEGACY_UNVERIFIED);
                        if (!$store->enabled) {
                            $copy->setData(
                                PageLocale::schema_fields_VARIANT_STATUS,
                                PageLocale::VARIANT_STATUS_DRAFT,
                            );
                            $copy->setData(PageLocale::schema_fields_PUBLISHED_AT, null);
                        }
                        $copy->save();
                    }
                }
            }
            if ($nextPageId <= $lastPageId) {
                throw new \RuntimeException((string)__('CMS 变体升级无数据进展。'));
            }
            $lastPageId = $nextPageId;
            SchedulerSystem::yield();
            if (count($pages) < 200) {
                return;
            }
        }
    }

    private function variantExists(PageLocale $prototype, int $pageId, int $storeId, string $localeCode): bool
    {
        $row = (clone $prototype)->clearData()->reset()
            ->where(PageLocale::schema_fields_PAGE_ID, $pageId)
            ->where(PageLocale::schema_fields_STORE_ID, $storeId)
            ->where(PageLocale::schema_fields_LOCALE_CODE, $localeCode)
            ->find()
            ->fetchArray();
        return is_array($row) && (int)($row[PageLocale::schema_fields_ID] ?? 0) > 0;
    }

    private function assertVariantOwnership(
        StoreCatalogInterface $stores,
        Page $pagePrototype,
        PageLocale $localePrototype,
    ): void {
        $lastId = 0;
        while (true) {
            $rows = (clone $localePrototype)->clearData()->reset()
                ->where(PageLocale::schema_fields_ID, $lastId, '>')
                ->order(PageLocale::schema_fields_ID, 'ASC')
                ->limit(500)
                ->select()
                ->fetch()
                ->getItems();
            if ($rows === []) {
                return;
            }
            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!$row instanceof PageLocale || $row->getPageLocaleId() <= $lastId) {
                    continue;
                }
                $nextId = max($nextId, $row->getPageLocaleId());
                $page = (clone $pagePrototype)->clearData()->reset()->load($row->getPageId());
                $store = $stores->byId($row->getStoreId());
                if (!$page instanceof Page
                    || $page->getPageId() <= 0
                    || $store === null
                    || $store->websiteId !== $page->getWebsiteId()
                ) {
                    throw new \RuntimeException((string)__('CMS 店铺语言变体存在孤立或跨网站归属，升级已停止。'));
                }
            }
            if ($nextId <= $lastId) {
                throw new \RuntimeException((string)__('CMS 变体归属校验无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < 500) {
                return;
            }
        }
    }

    private function markLegacyRow(PageLocale $row, Page $page, int $storeId, string $storeCode): void
    {
        $status = match ($page->getStatus()) {
            Page::STATUS_PUBLISHED => PageLocale::VARIANT_STATUS_PUBLISHED,
            Page::STATUS_DISABLED => PageLocale::VARIANT_STATUS_DISABLED,
            default => PageLocale::VARIANT_STATUS_DRAFT,
        };
        $row->setData(PageLocale::schema_fields_STORE_ID, $storeId);
        $row->setData(PageLocale::schema_fields_STORE_CODE, $storeCode);
        $row->setData(PageLocale::schema_fields_VARIANT_STATUS, $status);
        $row->setData(
            PageLocale::schema_fields_TRANSLATION_STATE,
            $row->getOrigin() === PageLocale::ORIGIN_AI
                ? PageLocale::TRANSLATION_STATE_DRAFT
                : PageLocale::TRANSLATION_STATE_REVIEWED,
        );
        $row->setData(PageLocale::schema_fields_VALIDATION_STATE, PageLocale::VALIDATION_STATE_LEGACY_UNVERIFIED);
        if ($status === PageLocale::VARIANT_STATUS_PUBLISHED) {
            $publishedAt = trim((string)$page->getData(Page::schema_fields_UPDATED_AT));
            $row->setData(PageLocale::schema_fields_PUBLISHED_AT, $publishedAt !== '' ? $publishedAt : date('Y-m-d H:i:s'));
        }
        $row->save();
    }
}
