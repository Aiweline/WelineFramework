<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

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
        private readonly ThemeLayout $layout,
    ) {
    }

    public function resolveActiveThemeId(string $area, bool $allowPreview = false): int
    {
        $theme = $this->themeContext->resolveTheme($area, null, $allowPreview);

        return max(0, (int)($theme?->getId() ?? 0));
    }

    public function initializeVersionIfNeeded(
        int $themeId,
        string $pageType,
        ?int $userId,
        LayoutIdentity $identity,
    ): void {
        $this->versionService->initializeVersionIfNeeded(
            $themeId,
            $pageType,
            $userId,
            $identity->toArray(),
        );
    }

    public function replaceLayout(
        int $themeId,
        string $pageType,
        array $layoutData,
        LayoutStatus $status,
        LayoutIdentity $identity,
    ): bool {
        return $this->layoutService->saveLayout(
            $themeId,
            $pageType,
            $layoutData,
            $status->value,
            $identity->toArray(),
        );
    }

    public function publishLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $identity,
        bool $allowEmpty = false,
    ): bool {
        return $this->layoutService->publishLayout(
            $themeId,
            $pageType,
            $identity->toArray(),
            $allowEmpty,
        );
    }

    public function copyLayout(
        int $themeId,
        string $pageType,
        LayoutIdentity $sourceIdentity,
        LayoutIdentity $targetIdentity,
    ): LayoutCopyResult {
        return LayoutCopyResult::fromArray($this->layoutService->copyLayoutIdentity(
            $themeId,
            $pageType,
            $sourceIdentity->toArray(),
            $targetIdentity->toArray(),
        ));
    }

    public function hasLayout(int $themeId, string $pageType, LayoutIdentity $identity): bool
    {
        $identity = $identity->toArray();
        try {
            $row = $this->layout->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return false;
        } finally {
            $this->layout->clearQuery()->clearData();
        }

        return is_array($row) && (int)($row[ThemeLayout::schema_fields_ID] ?? 0) > 0;
    }

    public function deleteLayout(int $themeId, string $pageType, LayoutIdentity $identity): int
    {
        $identity = $identity->toArray();
        try {
            $rows = $this->layout->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return 0;
        }

        $deleted = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            if ($layoutId <= 0) {
                continue;
            }
            try {
                $this->layout->clearQuery()->clearData()->load($layoutId)->delete();
                $deleted++;
            } catch (\Throwable) {
            }
        }
        $this->layout->clearQuery()->clearData();

        return $deleted;
    }
}
