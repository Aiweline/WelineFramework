<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Api\Data\CmsEditorContext;
use Weline\Cms\Api\Data\CmsPageVariantIdentity;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutWorkspaceInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** Store/Locale publication boundary for CMS. Carries no mutable current context. */
final class CmsPageVariantService
{
    public function __construct(
        private readonly PageLocale $localeModel,
        private readonly PageLocaleService $locales,
        private readonly CmsEditorContextResolver $contexts,
        private readonly LayoutWorkspaceInterface $layouts,
        private readonly StoreCatalogInterface $stores,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly CmsPageResourceChangePublisher $resourceChanges,
    ) {
    }

    /** @param list<string> $roles @return array<string,mixed> */
    public function publish(
        Page $page,
        int $storeId,
        string $localeCode,
        string $layoutOption,
        ?int $actorId = null,
        array $roles = [],
    ): array {
        if ($page->getPageId() <= 0 || $page->isDeleted()) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在或已删除，不能发布。'));
        }
        if ($page->getStatus() === Page::STATUS_DISABLED) {
            throw new \InvalidArgumentException((string)__('CMS 页面已禁用，请先恢复为草稿。'));
        }

        $context = $this->contexts->resolve($page, $storeId, $localeCode, true);
        $layoutIdentity = $this->layoutIdentity($page, $context, $layoutOption);
        $connection = $page->getConnection();
        $publishedVariant = null;
        $publishedPage = null;
        $themePublication = [];
        $save = function () use (
            $page,
            $context,
            $layoutIdentity,
            $actorId,
            $roles,
            &$publishedVariant,
            &$publishedPage,
            &$themePublication,
        ): void {
            $currentPage = $this->lockPageForMutation($page, true);
            if ($currentPage->isDeleted() || $currentPage->getStatus() === Page::STATUS_DISABLED) {
                throw new \InvalidArgumentException((string)__('CMS 页面已删除或禁用，不能发布。'));
            }
            if ($currentPage->getWebsiteId() !== $context->websiteId) {
                throw new \RuntimeException((string)__('CMS 页面网站已发生变化，请刷新后重试。'));
            }
            $pageBefore = $currentPage->getData();
            $current = $this->locales->findVariant(
                $currentPage->getPageId(),
                $context->storeId,
                $context->localeCode,
                true,
            );
            if (!$current instanceof PageLocale) {
                throw new \InvalidArgumentException((string)__('当前店铺语言尚未保存 CMS 页面变体。'));
            }
            if (trim($current->getTitle()) === '') {
                throw new \InvalidArgumentException((string)__('当前店铺语言的 CMS 页面标题不能为空。'));
            }
            if ($current->getTranslationState() !== PageLocale::TRANSLATION_STATE_REVIEWED) {
                throw new \InvalidArgumentException((string)__('当前店铺语言的标题或翻译尚未审核，不能发布。'));
            }

            $themePublication = $this->layouts->publishTargetVariant(
                Page::LAYOUT_TYPE,
                $layoutIdentity,
                [
                    'scope_identity' => $context->scopeIdentity,
                    'locale_code' => $context->localeCode,
                    'actor_id' => $actorId,
                    'roles' => array_values($roles),
                    'purpose' => 'publish',
                    'policy_revision' => 1,
                    'cms_variant' => (new CmsPageVariantIdentity(
                        $currentPage->getPageId(),
                        $currentPage->getWebsiteId(),
                        $context->storeId,
                        $context->localeCode,
                    ))->toArray(),
                    'reference_owner_type' => 'cms_page_variant',
                    'reference_owner_id' => $currentPage->getPageId() . ':' . $context->storeId . ':' . $context->localeCode,
                    'owner_version' => $current->getVariantRevision() + 1,
                ],
                true,
            );
            if (empty($themePublication['success'])) {
                throw new \RuntimeException((string)__('Theme 布局变体发布失败，CMS 状态未更新。'));
            }
            $current->setData(PageLocale::schema_fields_VALIDATION_STATE, PageLocale::VALIDATION_STATE_VALID);
            $current->setData(PageLocale::schema_fields_VARIANT_STATUS, PageLocale::VARIANT_STATUS_PUBLISHED);
            $current->setData(PageLocale::schema_fields_PUBLISHED_AT, date('Y-m-d H:i:s'));
            $current->save();
            $publishedVariant = $current;
            $this->aggregatePageStatus($currentPage, false);
            $this->resourceChanges->publish($currentPage, 'publish', $pageBefore, '', '');
            $publishedPage = $currentPage;
        };
        if ($this->transactions->isActive($connection)) {
            $this->assertWriteIntent($connection);
            $this->transactions->withSavepoint($connection, 'cms_page_variant_publish', $save);
        } else {
            $this->transactions->runWrite($connection, $save);
        }

        if (!$publishedVariant instanceof PageLocale) {
            throw new \RuntimeException((string)__('CMS 页面变体发布结果无效。'));
        }
        if ($publishedPage instanceof Page) {
            $page->setData($publishedPage->getData());
        }

        return [
            'success' => true,
            'status' => PageLocale::VARIANT_STATUS_PUBLISHED,
            'variant' => $this->variantArray($publishedVariant),
            'editor_context' => $context->toArray(),
            'layout_identity' => $layoutIdentity->toArray(),
            'theme_id' => (int)($themePublication['theme_id'] ?? 0),
        ];
    }

    public function aggregatePageStatus(Page $page, bool $preserveDisabled = true): string
    {
        $connection = $page->getConnection();
        if (!$this->transactions->isActive($connection)) {
            return $this->transactions->runWrite(
                $connection,
                fn (): string => $this->aggregatePageStatus($page, $preserveDisabled),
            );
        }
        $this->assertWriteIntent($connection);
        $currentPage = $this->lockPageForMutation($page);
        if ($currentPage->isDeleted()
            || ($preserveDisabled && $currentPage->getStatus() === Page::STATUS_DISABLED)
        ) {
            $status = Page::STATUS_DISABLED;
        } else {
            $published = (clone $this->localeModel)->clearData()->reset()
                ->where(PageLocale::schema_fields_PAGE_ID, $currentPage->getPageId())
                ->where(PageLocale::schema_fields_VARIANT_STATUS, PageLocale::VARIANT_STATUS_PUBLISHED)
                ->find()
                ->fetchArray();
            $status = is_array($published) && (int)($published[PageLocale::schema_fields_ID] ?? 0) > 0
                ? Page::STATUS_PUBLISHED
                : Page::STATUS_DRAFT;
        }
        if ($currentPage->getStatus() !== $status) {
            $currentPage->setData(Page::schema_fields_STATUS, $status)->save();
        }
        $page->setData($currentPage->getData());
        return $status;
    }

    public function disableAll(Page $page): int
    {
        return $this->changeAll($page, PageLocale::VARIANT_STATUS_DISABLED);
    }

    public function restoreAllAsDraft(Page $page): int
    {
        $connection = $page->getConnection();
        if (!$this->transactions->isActive($connection)) {
            return $this->transactions->runWrite(
                $connection,
                fn (): int => $this->restoreAllAsDraft($page),
            );
        }
        $this->assertWriteIntent($connection);
        $count = $this->changeAll($page, PageLocale::VARIANT_STATUS_DRAFT);
        $currentPage = $this->lockPageForMutation($page);
        $currentPage->setData(Page::schema_fields_STATUS, Page::STATUS_DRAFT)->save();
        $page->setData($currentPage->getData());
        return $count;
    }

    public function resolveForRead(
        Page $page,
        int $storeId,
        string $localeCode,
        bool $preview = false,
    ): ?PageLocale {
        $context = $this->contexts->resolve($page, $storeId, $localeCode, false);
        if (!$preview && !$context->storeEnabled) {
            return null;
        }
        $variant = $this->locales->findVariant(
            $page->getPageId(),
            $context->storeId,
            $context->localeCode,
        );
        if (!$variant instanceof PageLocale) {
            return null;
        }
        if (!$preview && $variant->getVariantStatus() !== PageLocale::VARIANT_STATUS_PUBLISHED) {
            return null;
        }
        return $variant;
    }

    /** @return array{copied:int,skipped:list<array<string,mixed>>,theme_results:list<array<string,mixed>>} */
    public function copyVariants(Page $source, Page $target, bool $replaceInitialSeed = false): array
    {
        $connection = $target->getConnection();
        if (!$this->transactions->isActive($connection)) {
            return $this->transactions->runWrite(
                $connection,
                fn (): array => $this->copyVariants($source, $target, $replaceInitialSeed),
            );
        }
        $this->assertWriteIntent($connection);
        $target = $this->lockPageForMutation($target, true);
        if ($target->isDeleted()) {
            throw new \InvalidArgumentException((string)__('目标 CMS 页面已删除，不能复制变体。'));
        }
        $sourceDefault = $this->stores->defaultStore($source->getWebsiteId());
        $targetDefault = $this->stores->defaultStore($target->getWebsiteId());
        if ($sourceDefault === null || $targetDefault === null) {
            throw new \RuntimeException((string)__('源网站或目标网站缺少默认店铺，不能复制 CMS 变体。'));
        }
        $targetByCode = [];
        foreach ($this->stores->byWebsite($target->getWebsiteId()) as $store) {
            if ($store->lifecycleStatus === 'active' && $store->tombstonedAt === null) {
                $targetByCode[$store->code] = $store;
            }
        }

        $rows = (clone $this->localeModel)->clearData()->reset()
            ->where(PageLocale::schema_fields_PAGE_ID, $source->getPageId())
            ->order(PageLocale::schema_fields_ID, 'ASC')
            ->limit(1001)
            ->select()
            ->fetch()
            ->getItems();
        if (count($rows) > 1000) {
            throw new \RuntimeException((string)__('CMS 页面变体数量超过单次复制上限。'));
        }
        $copied = 0;
        $skipped = [];
        $themeResults = [];
        $sourceLocales = array_fill_keys($this->locales->getWebsiteLocales($source->getWebsiteId()), true);
        $targetLocales = array_fill_keys($this->locales->getWebsiteLocales($target->getWebsiteId()), true);
        $targetSourceLocale = $this->locales->resolveSourceLocale($target);
        if ($replaceInitialSeed) {
            $this->markInitialSeedNeedsReview($target, $targetDefault->id, $targetSourceLocale);
        }
        foreach ($rows as $row) {
            if (!$row instanceof PageLocale) {
                continue;
            }
            $sourceStore = $this->stores->byId($row->getStoreId());
            if ($sourceStore === null
                || $sourceStore->websiteId !== $source->getWebsiteId()
                || $sourceStore->lifecycleStatus !== 'active'
                || $sourceStore->tombstonedAt !== null
            ) {
                $skipped[] = [
                    'source_store_id' => $row->getStoreId(),
                    'source_store_code' => $row->getStoreCode(),
                    'locale_code' => $row->getLocaleCode(),
                    'reason' => 'source_store_unavailable',
                ];
                continue;
            }
            if (!isset($sourceLocales[$row->getLocaleCode()])
                || !isset($targetLocales[$row->getLocaleCode()])
            ) {
                $skipped[] = [
                    'source_store_id' => $row->getStoreId(),
                    'source_store_code' => $row->getStoreCode(),
                    'locale_code' => $row->getLocaleCode(),
                    'reason' => 'locale_not_mapped',
                ];
                continue;
            }
            $targetStore = $row->getStoreId() === $sourceDefault->id
                ? $targetDefault
                : ($targetByCode[$row->getStoreCode()] ?? null);
            if ($targetStore === null) {
                $skipped[] = [
                    'source_store_id' => $row->getStoreId(),
                    'source_store_code' => $row->getStoreCode(),
                    'locale_code' => $row->getLocaleCode(),
                    'reason' => 'store_not_mapped',
                ];
                continue;
            }
            $sourceContext = $this->contexts->resolve($source, $row->getStoreId(), $row->getLocaleCode());
            $targetContext = $this->contexts->resolve($target, $targetStore->id, $row->getLocaleCode());
            $selection = $this->layouts->resolveLayoutSelection(
                Page::TARGET_TYPE,
                $source->getPageId(),
                Page::LAYOUT_TYPE,
                $sourceContext->canonicalScope,
                $sourceContext->localeCode,
            );
            $layoutOption = trim((string)($selection['layout_option'] ?? 'default')) ?: 'default';
            $copy = function () use (
                $target,
                $row,
                $targetStore,
                $source,
                $sourceContext,
                $targetContext,
                $layoutOption,
                $replaceInitialSeed,
                $targetDefault,
                $targetSourceLocale,
            ): array {
                $existingTarget = $this->locales->findVariant(
                    $target->getPageId(),
                    $targetStore->id,
                    $row->getLocaleCode(),
                    true,
                );
                $replaceSeed = $replaceInitialSeed
                    && $targetStore->id === $targetDefault->id
                    && $row->getLocaleCode() === $targetSourceLocale;
                if ($existingTarget instanceof PageLocale && !$replaceSeed) {
                    return [null, null, 'target_variant_exists'];
                }
                $targetRow = $this->locales->upsertTitle(
                    $target,
                    $row->getLocaleCode(),
                    $row->getTitle(),
                    PageLocale::ORIGIN_MANUAL,
                    hash('sha256', $row->getTitle()),
                    null,
                    $targetStore->id,
                    PageLocale::TRANSLATION_STATE_DRAFT,
                    PageLocale::VALIDATION_STATE_PENDING,
                    PageLocale::VARIANT_STATUS_DRAFT,
                );
                $themeResult = $this->layouts->copyTargetLayoutData(
                    Page::LAYOUT_TYPE,
                    $this->layoutIdentity($source, $sourceContext, $layoutOption),
                    $this->layoutIdentity($target, $targetContext, $layoutOption),
                    $sourceContext->scopeIdentity,
                    $targetContext->scopeIdentity,
                );
                if (!$themeResult->success) {
                    throw new \RuntimeException((string)__('Theme 布局变体复制失败。'));
                }
                return [$targetRow, $themeResult->toArray(), null];
            };
            [$targetRow, $themeResult, $skipReason] = $this->transactions->withSavepoint(
                $connection,
                'cms_page_variant_copy',
                $copy,
            );
            if (is_string($skipReason) && $skipReason !== '') {
                $skipped[] = [
                    'source_store_id' => $row->getStoreId(),
                    'source_store_code' => $row->getStoreCode(),
                    'locale_code' => $row->getLocaleCode(),
                    'reason' => $skipReason,
                ];
                continue;
            }
            $themeResults[] = $themeResult;
            $copied += $targetRow instanceof PageLocale && $targetRow->getPageLocaleId() > 0 ? 1 : 0;
        }
        $this->aggregatePageStatus($target, false);
        return ['copied' => $copied, 'skipped' => $skipped, 'theme_results' => $themeResults];
    }

    private function markInitialSeedNeedsReview(Page $target, int $defaultStoreId, string $sourceLocale): void
    {
        $connection = $target->getConnection();
        $mark = function () use ($target, $defaultStoreId, $sourceLocale): void {
            $seed = $this->locales->findVariant(
                $target->getPageId(),
                $defaultStoreId,
                $sourceLocale,
                true,
            );
            if (!$seed instanceof PageLocale) {
                return;
            }
            $seed->setData(PageLocale::schema_fields_TRANSLATION_STATE, PageLocale::TRANSLATION_STATE_DRAFT);
            $seed->setData(PageLocale::schema_fields_VALIDATION_STATE, PageLocale::VALIDATION_STATE_PENDING);
            $seed->setData(PageLocale::schema_fields_VARIANT_STATUS, PageLocale::VARIANT_STATUS_DRAFT);
            $seed->save();
        };
        if ($this->transactions->isActive($connection)) {
            $this->assertWriteIntent($connection);
            $this->transactions->withSavepoint($connection, 'cms_page_copy_seed_review', $mark);
            return;
        }
        $this->transactions->runWrite($connection, $mark);
    }

    private function requireVariant(Page $page, CmsEditorContext $context, bool $forUpdate): PageLocale
    {
        $variant = $this->locales->findVariant(
            $page->getPageId(),
            $context->storeId,
            $context->localeCode,
            $forUpdate,
        );
        if (!$variant instanceof PageLocale) {
            throw new \InvalidArgumentException((string)__('当前店铺语言尚未保存 CMS 页面变体。'));
        }
        return $variant;
    }

    private function layoutIdentity(Page $page, CmsEditorContext $context, string $layoutOption): LayoutIdentity
    {
        return new LayoutIdentity(
            trim($layoutOption) !== '' ? $layoutOption : 'default',
            $context->canonicalScope,
            Page::TARGET_TYPE,
            $page->getPageId(),
            $context->localeCode,
        );
    }

    private function changeAll(Page $page, string $status): int
    {
        $change = function () use ($page, $status): int {
            $currentPage = $this->lockPageForMutation($page);
            $items = (clone $this->localeModel)->clearData()->reset()
                ->where(PageLocale::schema_fields_PAGE_ID, $currentPage->getPageId())
                ->limit(1001)
                ->select()
                ->fetch()
                ->getItems();
            if (count($items) > 1000) {
                throw new \RuntimeException((string)__('CMS 页面变体数量超过单次状态变更上限。'));
            }
            $changed = 0;
            foreach ($items as $item) {
                if (!$item instanceof PageLocale) {
                    continue;
                }
                $item->setData(PageLocale::schema_fields_VARIANT_STATUS, $status);
                $item->setData(PageLocale::schema_fields_VALIDATION_STATE, PageLocale::VALIDATION_STATE_PENDING);
                $item->save();
                $changed++;
            }
            return $changed;
        };
        $connection = $page->getConnection();
        if ($this->transactions->isActive($connection)) {
            $this->assertWriteIntent($connection);
            return $this->transactions->withSavepoint($connection, 'cms_page_variants_change_all', $change);
        }
        return $this->transactions->runWrite($connection, $change);
    }

    private function assertWriteIntent(\Weline\Framework\Database\ConnectionFactory $connection): void
    {
        if (!$this->transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('CMS 页面变体写入必须位于写意图事务内。'));
        }
    }

    private function lockPageForMutation(Page $expected, bool $assertStableIdentity = false): Page
    {
        if ($expected->getPageId() <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面身份无效。'));
        }
        $query = clone $expected;
        $query->clearData()->reset()
            ->where(Page::schema_fields_ID, $expected->getPageId())
            ->limit(1);
        if ($this->supportsForUpdate($expected)) {
            $query->additional('FOR UPDATE');
        }
        $items = array_values($query->select()->fetch()->getItems());
        $current = $items[0] ?? null;
        if (!$current instanceof Page) {
            throw new \RuntimeException((string)__('CMS 页面已被其他请求删除。'));
        }
        if ($assertStableIdentity && (
            $current->getWebsiteId() !== $expected->getWebsiteId()
            || !hash_equals($current->getWebsiteCode(), $expected->getWebsiteCode())
        )) {
            throw new \RuntimeException((string)__('CMS 页面身份已被其他请求修改，请刷新后重试。'));
        }
        return $current;
    }

    private function supportsForUpdate(Page $page): bool
    {
        $type = strtolower((string)$page->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    /** @return array<string,mixed> */
    private function variantArray(PageLocale $variant): array
    {
        return [
            'page_locale_id' => $variant->getPageLocaleId(),
            'page_id' => $variant->getPageId(),
            'store_id' => $variant->getStoreId(),
            'store_code' => $variant->getStoreCode(),
            'locale_code' => $variant->getLocaleCode(),
            'title' => $variant->getTitle(),
            'variant_status' => $variant->getVariantStatus(),
            'translation_state' => $variant->getTranslationState(),
            'validation_state' => $variant->getValidationState(),
            'published_at' => $variant->getPublishedAt(),
            'variant_revision' => $variant->getVariantRevision(),
        ];
    }
}
