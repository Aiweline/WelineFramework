<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Layout\LayoutCopyResult;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutStatus;
use Weline\Theme\Api\Layout\LayoutWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;

final class LayoutWorkspace implements LayoutWorkspaceInterface
{
    public function __construct(
        private readonly ThemeContextService $themeContext,
        private readonly ThemeLayoutService $layoutService,
        private readonly ThemeLayoutVersionService $versionService,
        private readonly ThemeVirtualLayoutService $virtualLayouts,
        private readonly ThemeLayout $layout,
        private readonly LayoutContentValidationRegistry $contentValidators,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
    ) {
    }

    public function resolveActiveThemeId(
        string $area,
        bool $allowPreview = false,
        ?ScopeIdentity $scopeIdentity = null,
    ): int {
        $theme = $scopeIdentity instanceof ScopeIdentity
            ? $this->themeContext->resolveThemeForScope($area, $scopeIdentity)
            : $this->themeContext->resolveTheme($area, null, $allowPreview);

        return max(0, (int)($theme?->getId() ?? 0));
    }

    public function initializeVersionIfNeeded(
        int $themeId,
        string $pageType,
        ?int $userId,
        LayoutIdentity $identity,
    ): void {
        $this->atomic('theme_layout_version_initialize', function () use (
            $themeId,
            $pageType,
            $userId,
            $identity,
        ): void {
            $this->versionService->initializeVersionIfNeeded(
                $themeId,
                $pageType,
                $userId,
                $identity->toArray(),
            );
        });
    }

    public function replaceLayout(
        int $themeId,
        string $pageType,
        array $layoutData,
        LayoutStatus $status,
        LayoutIdentity $identity,
    ): bool {
        try {
            return $this->atomic('theme_layout_replace', function () use (
                $themeId,
                $pageType,
                $layoutData,
                $status,
                $identity,
            ): bool {
                if (!$this->layoutService->saveLayout(
                    $themeId,
                    $pageType,
                    $layoutData,
                    $status->value,
                    $identity->toArray(),
                )) {
                    throw new \RuntimeException((string)__('Theme 布局保存失败。'));
                }
                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    public function publishLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $identity,
        bool $allowEmpty = false,
    ): bool {
        try {
            return $this->atomic('theme_layout_publish', function () use (
                $themeId,
                $pageType,
                $identity,
                $allowEmpty,
            ): bool {
                if (!$this->layoutService->publishLayout(
                    $themeId,
                    $pageType,
                    $identity->toArray(),
                    $allowEmpty,
                )) {
                    throw new \RuntimeException((string)__('Theme 布局发布失败。'));
                }
                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    public function copyLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $sourceIdentity,
        LayoutIdentity $targetIdentity,
    ): LayoutCopyResult {
        try {
            return $this->atomic(
                'theme_layout_copy',
                fn (): LayoutCopyResult => LayoutCopyResult::fromArray(
                    $this->layoutService->copyLayoutIdentity(
                        $themeId,
                        $pageType,
                        $sourceIdentity->toArray(),
                        $targetIdentity->toArray(),
                    ),
                ),
            );
        } catch (\Throwable) {
            return new LayoutCopyResult(false, 'copy_failed', [], $sourceIdentity, $targetIdentity);
        }
    }

    public function hasLayout(int $themeId, string $pageType, LayoutIdentity $identity): bool
    {
        $identity = $identity->toArray();
        $model = null;
        try {
            $model = clone $this->layout;
            $row = $model->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return false;
        } finally {
            $model?->clearQuery()->clearData();
        }

        return is_array($row) && (int)($row[ThemeLayout::schema_fields_ID] ?? 0) > 0;
    }

    public function deleteLayout(int $themeId, string $pageType, LayoutIdentity $identity): int
    {
        $identity = $identity->toArray();
        try {
            return $this->atomic('theme_layout_delete', function () use (
                $themeId,
                $pageType,
                $identity,
            ): int {
                $model = clone $this->layout;
                $rows = $model->clearQuery()->clearData()
                    ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                    ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                    ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                    ->where(ThemeLayout::schema_fields_LOCALE_CODE, $identity['locale_code'])
                    ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                    ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                    ->select()
                    ->fetchArray();

                $deleted = 0;
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
                    if ($layoutId <= 0) {
                        continue;
                    }
                    $item = clone $this->layout;
                    $item->clearQuery()->clearData()->load($layoutId)->delete();
                    $deleted++;
                }
                return $deleted;
            });
        } catch (\Throwable) {
            return 0;
        }
    }

    public function resolveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        ?string $scope = null,
        ?string $localeCode = null,
    ): ?array {
        return $this->virtualLayouts->resolveLayoutSelection(
            $targetType,
            $targetId,
            $layoutType,
            $scope,
            $localeCode,
        );
    }

    public function saveLayoutSelection(
        string $targetType,
        int $targetId,
        string $layoutType,
        string $layoutOption,
        ?string $scope = null,
        ?string $localeCode = null,
        array $options = [],
    ): array {
        return $this->atomic(
            'theme_layout_selection_save',
            fn (): array => $this->virtualLayouts->saveLayoutSelection(
                $targetType,
                $targetId,
                $layoutType,
                $layoutOption,
                $scope,
                $localeCode,
                $options,
            ),
        );
    }

    public function validateTargetVariant(
        string $pageType,
        LayoutIdentity $identity,
        array $context,
    ): void {
        $this->assertIdentityMatchesContext($identity, $context);
        $themeId = $this->themeIdForContext($context);
        $this->validateResolvedTargetVariant($themeId, $pageType, $identity, $context);
    }

    /** @param array<string,mixed> $context */
    private function validateResolvedTargetVariant(
        int $themeId,
        string $pageType,
        LayoutIdentity $identity,
        array $context,
    ): void {
        if ($themeId < 1 || trim($pageType) === '') {
            throw new \InvalidArgumentException((string)__('主题布局变体身份无效。'));
        }
        if ($identity->targetType === 'cms_page' && ($identity->targetId < 1 || $identity->localeCode === '')) {
            throw new \InvalidArgumentException((string)__('CMS 布局必须指定页面 ID 和精确语言。'));
        }
        $layoutData = $this->layoutService->getLayout(
            $themeId,
            $pageType,
            LayoutStatus::DRAFT->value,
            $identity->toArray(),
            true,
        );
        $this->contentValidators->validate($layoutData, array_replace($context, [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'layout_identity' => $identity->toArray(),
        ]));
    }

    public function publishTargetVariant(
        string $pageType,
        LayoutIdentity $identity,
        array $context,
        bool $allowEmpty = false,
    ): array {
        $publicationContext = array_replace($context, [
            'index_references' => true,
        ]);
        $this->assertIdentityMatchesContext($identity, $publicationContext);
        $themeId = $this->themeIdForContext($publicationContext);
        return $this->atomic(
            'theme_target_variant_publish',
            function () use ($themeId, $pageType, $identity, $publicationContext, $allowEmpty): array {
                $this->validateResolvedTargetVariant(
                    $themeId,
                    $pageType,
                    $identity,
                    $publicationContext,
                );
                if (!$this->layoutService->publishLayout(
                    $themeId,
                    $pageType,
                    $identity->toArray(),
                    $allowEmpty,
                    $publicationContext,
                )) {
                    throw new \RuntimeException((string)__('Theme 布局变体发布失败。'));
                }
                return ['success' => true, 'theme_id' => $themeId];
            },
        );
    }

    public function copyTargetLayoutData(
        string $pageType,
        LayoutIdentity $sourceIdentity,
        LayoutIdentity $targetIdentity,
        ScopeIdentity $sourceScopeIdentity,
        ScopeIdentity $targetScopeIdentity,
    ): LayoutCopyResult {
        $this->assertIdentityMatchesScope($sourceIdentity, $sourceScopeIdentity);
        $this->assertIdentityMatchesScope($targetIdentity, $targetScopeIdentity);
        $sourceThemeId = $this->resolveActiveThemeId('frontend', false, $sourceScopeIdentity);
        $targetThemeId = $this->resolveActiveThemeId('frontend', false, $targetScopeIdentity);
        if ($sourceThemeId <= 0 || $targetThemeId <= 0) {
            return new LayoutCopyResult(
                false,
                'theme_not_found',
                [],
                $sourceIdentity,
                $targetIdentity,
            );
        }
        $copy = function () use (
            $pageType,
            $sourceIdentity,
            $targetIdentity,
            $sourceThemeId,
            $targetThemeId,
        ): LayoutCopyResult {
            $selection = $this->resolveLayoutSelection(
                $sourceIdentity->targetType,
                $sourceIdentity->targetId,
                $pageType,
                $sourceIdentity->scope,
                $sourceIdentity->localeCode,
            );
            $selectionResult = $this->saveLayoutSelection(
                $targetIdentity->targetType,
                $targetIdentity->targetId,
                $pageType,
                (string)($selection['layout_option'] ?? $sourceIdentity->layoutOption),
                $targetIdentity->scope,
                $targetIdentity->localeCode,
                [
                    'reason' => (string)__('复制 Theme target 布局选择'),
                    'metadata' => [
                        'copied_from_target_type' => $sourceIdentity->targetType,
                        'copied_from_target_id' => $sourceIdentity->targetId,
                    ],
                ],
            );
            if (empty($selectionResult['success'])) {
                throw new \RuntimeException((string)__('Theme 布局选择复制失败。'));
            }
            $layout = $this->layoutService->copyLayoutIdentityBetweenThemes(
                $sourceThemeId,
                $targetThemeId,
                $pageType,
                $sourceIdentity->toArray(),
                $targetIdentity->toArray(),
                true,
            );
            if (empty($layout['success'])) {
                throw new \RuntimeException((string)__('Theme 布局数据复制失败。'));
            }
            $virtual = $this->virtualLayouts->copyVirtualLayoutIdentity(
                ['theme_id' => $sourceThemeId, 'area' => 'frontend', 'layout_type' => $pageType] + $sourceIdentity->toArray(),
                ['theme_id' => $targetThemeId, 'area' => 'frontend', 'layout_type' => $pageType] + $targetIdentity->toArray(),
                false,
            );
            if (empty($virtual['success'])) {
                throw new \RuntimeException((string)__('Theme 虚拟布局数据复制失败。'));
            }

            $copied = [];
            foreach ((array)($layout['copied'] ?? []) as $status => $count) {
                $copied['layout_' . (string)$status] = max(0, (int)$count);
            }
            $copied['virtual_layout'] = max(0, (int)($virtual['copied'] ?? 0));
            $copied['selection'] = 1;
            return new LayoutCopyResult(true, 'copied', $copied, $sourceIdentity, $targetIdentity);
        };

        try {
            return $this->atomic('theme_target_layout_copy', $copy);
        } catch (\Throwable) {
            return new LayoutCopyResult(false, 'copy_failed', [], $sourceIdentity, $targetIdentity);
        }
    }

    /** @param array<string,mixed> $context */
    private function themeIdForContext(array $context): int
    {
        $scopeIdentity = $this->scopeIdentityFromContext($context);
        $themeId = $this->resolveActiveThemeId(
            (string)($context['area'] ?? 'frontend'),
            false,
            $scopeIdentity,
        );
        if ($themeId <= 0) {
            throw new \RuntimeException((string)__('当前网站没有可用的前台主题。'));
        }

        return $themeId;
    }

    /** @param array<string,mixed> $context */
    private function assertIdentityMatchesContext(LayoutIdentity $identity, array $context): void
    {
        $scopeIdentity = $this->scopeIdentityFromContext($context);
        $this->assertIdentityMatchesScope($identity, $scopeIdentity);
        $contextLocale = trim((string)($context['locale_code'] ?? ''));
        if ($contextLocale !== '') {
            $contextLocale = (new LayoutIdentity(localeCode: $contextLocale))->localeCode;
            if (!hash_equals($identity->localeCode, $contextLocale)) {
                throw new \InvalidArgumentException((string)__('布局身份语言与发布上下文不一致。'));
            }
        }

        $cmsVariant = $context['cms_variant'] ?? null;
        if (!is_array($cmsVariant)) {
            return;
        }
        $pageId = (int)($cmsVariant['page_id'] ?? 0);
        $websiteId = (int)($cmsVariant['website_id'] ?? -1);
        $storeId = (int)($cmsVariant['store_id'] ?? 0);
        $localeCode = (string)($cmsVariant['locale_code'] ?? '');
        if ($identity->targetType !== 'cms_page'
            || $identity->targetId !== $pageId
            || $pageId < 1
            || $storeId < 1
            || $scopeIdentity->scopeKind !== ScopeIdentity::KIND_STORE
            || $scopeIdentity->websiteId !== $websiteId
            || !hash_equals($identity->localeCode, $localeCode)
        ) {
            throw new \InvalidArgumentException((string)__('CMS 布局身份与发布变体上下文不一致。'));
        }
        if (($context['index_references'] ?? false) === true) {
            $expectedOwnerId = $pageId . ':' . $storeId . ':' . $localeCode;
            if (($context['reference_owner_type'] ?? null) !== 'cms_page_variant'
                || !hash_equals($expectedOwnerId, (string)($context['reference_owner_id'] ?? ''))
            ) {
                throw new \InvalidArgumentException((string)__('CMS 图片引用索引身份与发布变体不一致。'));
            }
        }
    }

    private function assertIdentityMatchesScope(
        LayoutIdentity $identity,
        ScopeIdentity $scopeIdentity,
    ): void {
        $normalized = $this->scopeNormalizer->normalize([
            'layout_option' => $identity->layoutOption,
            'scope_identity' => $scopeIdentity,
            'store_mode' => $scopeIdentity->storeMode ?? ScopeIdentity::MODE_NORMAL,
            'target_type' => $identity->targetType,
            'target_id' => $identity->targetId,
            'locale_code' => $identity->localeCode,
        ]);
        if (!hash_equals($identity->scope, $normalized['scope'])) {
            throw new \InvalidArgumentException((string)__('布局身份 Scope 与显式 ScopeIdentity 不一致。'));
        }
    }

    /** @param array<string,mixed> $context */
    private function scopeIdentityFromContext(array $context): ScopeIdentity
    {
        $scopeIdentity = $context['scope_identity'] ?? null;
        if (is_array($scopeIdentity)) {
            $scopeIdentity = ScopeIdentity::fromArray($scopeIdentity);
        }
        if (!$scopeIdentity instanceof ScopeIdentity) {
            throw new \InvalidArgumentException((string)__('布局发布必须显式指定 ScopeIdentity。'));
        }
        return $scopeIdentity;
    }

    /** @template T @param callable():T $operation @return T */
    private function atomic(string $savepoint, callable $operation): mixed
    {
        $connection = $this->layout->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('Theme 布局写入必须位于写意图事务内。'));
            }
            return $this->transactions->withSavepoint($connection, $savepoint, $operation);
        }
        return $this->transactions->runWrite($connection, $operation);
    }
}
