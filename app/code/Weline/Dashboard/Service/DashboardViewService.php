<?php

declare(strict_types=1);

namespace Weline\Dashboard\Service;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Dashboard\Model\DashboardView;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Websites\Model\Website;

class DashboardViewService
{
    public function __construct(
        private readonly DashboardView $dashboardView,
        private readonly Website $website,
        private readonly BackendUserConfig $backendUserConfig,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeLayoutService $themeLayoutService,
        private readonly ThemeLayout $themeLayout,
    ) {
    }

    public function getCurrentUserId(): int
    {
        return max(0, $this->backendUserConfig->getCurrentUserId());
    }

    public function getDefaultWebsiteId(): int
    {
        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)
                ->find()
                ->fetchArray();
            $websiteId = (int)($row[Website::schema_fields_ID] ?? 0);
            if ($websiteId > 0) {
                return $websiteId;
            }
        } catch (\Throwable) {
        }

        try {
            $row = $this->website->clearQuery()->clearData()
                ->order(Website::schema_fields_ID, 'ASC')
                ->find()
                ->fetchArray();
            return max(0, (int)($row[Website::schema_fields_ID] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function listWebsites(): array
    {
        try {
            $rows = $this->website->clearQuery()->clearData()
                ->order(Website::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            $rows = [];
        }

        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row[Website::schema_fields_ID] ?? 0);
            if ($websiteId <= 0) {
                continue;
            }
            $result[] = [
                'website_id' => $websiteId,
                'name' => (string)($row[Website::schema_fields_NAME] ?? ('#' . $websiteId)),
                'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
                'url' => (string)($row[Website::schema_fields_URL] ?? ''),
            ];
        }

        return $result;
    }

    public function ensureDefaultView(int $websiteId): ?DashboardView
    {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        if ($websiteId <= 0) {
            return null;
        }

        $existing = $this->findSystemDefaultView($websiteId);
        if ($existing) {
            return $existing;
        }

        $view = clone $this->dashboardView;
        $view->clearQuery()->clearData()
            ->setWebsiteId($websiteId)
            ->setOwnerAdminId(null)
            ->setName((string)__('默认概览'))
            ->setCode('default')
            ->setVisibility(DashboardView::VISIBILITY_SYSTEM)
            ->setIsDefault(true)
            ->setIsActive(true)
            ->setSortOrder(0)
            ->save();

        $this->clearOtherDefaults($websiteId, $view->getViewId());

        return $view;
    }

    public function getVisibleViews(int $websiteId, int $userId = 0): array
    {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        $userId = $userId > 0 ? $userId : $this->getCurrentUserId();
        $this->ensureDefaultView($websiteId);

        try {
            $rows = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_IS_ACTIVE, 1)
                ->order(DashboardView::schema_fields_IS_DEFAULT, 'DESC')
                ->order(DashboardView::schema_fields_VISIBILITY, 'ASC')
                ->order(DashboardView::schema_fields_SORT_ORDER, 'ASC')
                ->order(DashboardView::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $views = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !$this->rowIsVisibleTo($row, $userId)) {
                continue;
            }
            $views[] = $this->rowToPayload($row, $userId);
        }

        return $views;
    }

    public function getViewForUser(int $viewId, int $userId = 0): ?DashboardView
    {
        if ($viewId <= 0) {
            return null;
        }
        $userId = $userId > 0 ? $userId : $this->getCurrentUserId();

        $view = clone $this->dashboardView;
        try {
            $view->clearQuery()->clearData()->load($viewId);
        } catch (\Throwable) {
            return null;
        }

        if (!$view->getViewId() || !$view->isActive()) {
            return null;
        }

        return $this->canView($view, $userId) ? $view : null;
    }

    public function resolveActiveView(int $websiteId, int $viewId = 0, int $userId = 0): ?DashboardView
    {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        $userId = $userId > 0 ? $userId : $this->getCurrentUserId();

        if ($viewId > 0) {
            $view = $this->getViewForUser($viewId, $userId);
            if ($view && $view->getWebsiteId() === $websiteId) {
                return $view;
            }
        }

        $default = $this->findDefaultView($websiteId);
        if ($default && $this->canView($default, $userId)) {
            return $default;
        }

        return $this->ensureDefaultView($websiteId);
    }

    public function createView(
        int $websiteId,
        int $userId,
        string $name,
        string $visibility = DashboardView::VISIBILITY_PRIVATE,
        bool $copyDefaultLayout = false
    ): DashboardView
    {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        if ($websiteId <= 0) {
            throw new \InvalidArgumentException((string)__('缺少站点。'));
        }
        if ($userId <= 0) {
            throw new \RuntimeException((string)__('需要登录后台用户。'));
        }

        $visibility = $visibility === DashboardView::VISIBILITY_PUBLIC
            ? DashboardView::VISIBILITY_PUBLIC
            : DashboardView::VISIBILITY_PRIVATE;
        $code = $this->uniqueCode($websiteId, $userId, $this->slug($name));

        $view = clone $this->dashboardView;
        $view->clearQuery()->clearData()
            ->setWebsiteId($websiteId)
            ->setOwnerAdminId($userId)
            ->setName($name)
            ->setCode($code)
            ->setVisibility($visibility)
            ->setIsDefault(false)
            ->setIsActive(true)
            ->setSortOrder($this->nextSortOrder($websiteId, $userId))
            ->save();

        if ($copyDefaultLayout) {
            $source = $this->findDefaultView($websiteId) ?? $this->ensureDefaultView($websiteId);
            if ($source && $source->getViewId() !== $view->getViewId()) {
                $this->copyLayout($source, $view);
            }
        }

        return $view;
    }

    public function renameView(int $viewId, int $userId, string $name): DashboardView
    {
        $view = $this->requireEditableView($viewId, $userId);
        $view->setName($name)->save();
        return $view;
    }

    public function publishView(int $viewId, int $userId): DashboardView
    {
        $view = $this->requireEditableView($viewId, $userId);
        if ($view->getVisibility() === DashboardView::VISIBILITY_PRIVATE) {
            $view->setVisibility(DashboardView::VISIBILITY_PUBLIC)->save();
        }
        return $view;
    }

    public function privatizeView(int $viewId, int $userId): DashboardView
    {
        $view = $this->requireEditableView($viewId, $userId);
        if ($view->isDefault()) {
            throw new \RuntimeException((string)__('默认视图不能转为私有。'));
        }
        $view->setVisibility(DashboardView::VISIBILITY_PRIVATE)->save();
        return $view;
    }

    public function duplicateView(int $sourceViewId, int $userId, ?string $name = null): DashboardView
    {
        $source = $this->getViewForUser($sourceViewId, $userId);
        if (!$source) {
            throw new \RuntimeException((string)__('视图不存在或无权访问。'));
        }

        $view = $this->createView(
            $source->getWebsiteId(),
            $userId,
            $name ?: (string)__('%{1} 副本', [$source->getName()]),
            DashboardView::VISIBILITY_PRIVATE,
            false
        );
        $this->copyLayout($source, $view);

        return $view;
    }

    public function ensureLayoutInitialized(DashboardView $view): void
    {
        // Default dashboard widgets are suggestions in ThemeEditor, not automatic
        // mutations. An empty layout may be intentional after the user deletes
        // every widget and saves.
    }

    public function setDefaultView(int $viewId, int $userId): DashboardView
    {
        $view = $this->requireEditableView($viewId, $userId);
        if ($view->getVisibility() === DashboardView::VISIBILITY_PRIVATE) {
            $view->setVisibility(DashboardView::VISIBILITY_PUBLIC);
        }
        $view->setIsDefault(true)->save();
        $this->clearOtherDefaults($view->getWebsiteId(), $view->getViewId());

        return $view;
    }

    public function deleteView(int $viewId, int $userId): bool
    {
        $view = $this->requireEditableView($viewId, $userId);
        if ($view->isDefault() || $view->getVisibility() === DashboardView::VISIBILITY_SYSTEM) {
            throw new \RuntimeException((string)__('默认概览不能删除。'));
        }

        $this->deleteLayoutRows($view);
        $view->delete();
        return true;
    }

    public function canView(DashboardView $view, int $userId): bool
    {
        return match ($view->getVisibility()) {
            DashboardView::VISIBILITY_SYSTEM,
            DashboardView::VISIBILITY_PUBLIC => true,
            DashboardView::VISIBILITY_PRIVATE => $view->getOwnerAdminId() === $userId,
            default => false,
        };
    }

    public function canEdit(DashboardView $view, int $userId): bool
    {
        if ($view->getVisibility() === DashboardView::VISIBILITY_SYSTEM) {
            return false;
        }

        return $view->getOwnerAdminId() === $userId;
    }

    public function saveLayout(int $viewId, int $userId): bool
    {
        $view = $this->getViewForUser($viewId, $userId);
        if (!$view) {
            throw new \RuntimeException((string)__('视图不存在或无权访问。'));
        }

        if ($view->getVisibility() !== DashboardView::VISIBILITY_SYSTEM && !$this->canEdit($view, $userId)) {
            throw new \RuntimeException((string)__('当前视图不能直接编辑，请先复制到我的视图。'));
        }

        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            throw new \RuntimeException((string)__('未找到后台主题，无法保存 Dashboard 布局。'));
        }

        $saved = $this->themeLayoutService->publishLayout(
            $themeId,
            DashboardView::PAGE_TYPE,
            $view->layoutIdentity(),
            true
        );
        if (!$saved) {
            throw new \RuntimeException((string)__('Dashboard 布局保存失败。'));
        }

        return true;
    }

    public function getBackendThemeId(): int
    {
        try {
            $theme = $this->themeContext->resolveTheme('backend', null, false);
            return (int)($theme?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function buildThemeEditorUrl(DashboardView $view): string
    {
        /** @var \Weline\Framework\Http\Url $url */
        $url = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);

        return $url->getBackendUrl('theme/backend/theme-editor/index', [
            'editor_area' => 'backend',
            'theme_id' => $this->getBackendThemeId(),
            'page_type' => DashboardView::PAGE_TYPE,
            'layout_type' => DashboardView::PAGE_TYPE,
            'layout_option' => DashboardView::LAYOUT_OPTION,
            'lock_layout_context' => 1,
            'lock_source' => 'dashboard',
            'scope' => $view->scopeKey(),
            'target_type' => DashboardView::TARGET_TYPE_WEBSITE,
            'target_id' => $view->getWebsiteId(),
            'layout_lock_target_type' => DashboardView::TARGET_TYPE_WEBSITE,
            'layout_lock_target_id' => $view->getWebsiteId(),
            'theme_layout_target_type' => DashboardView::TARGET_TYPE_WEBSITE,
            'theme_layout_target_id' => $view->getWebsiteId(),
            'theme_layout_source_target_type' => DashboardView::TARGET_TYPE_WEBSITE,
            'theme_layout_source_target_id' => $view->getWebsiteId(),
            'widget_allow_supports' => 'dashboard-widget',
        ]);
    }

    public function rowToPayload(array $row, int $userId = 0): array
    {
        $viewId = (int)($row[DashboardView::schema_fields_ID] ?? 0);
        $ownerId = (int)($row[DashboardView::schema_fields_OWNER_ADMIN_ID] ?? 0);
        $visibility = (string)($row[DashboardView::schema_fields_VISIBILITY] ?? DashboardView::VISIBILITY_PRIVATE);

        return [
            'view_id' => $viewId,
            'website_id' => (int)($row[DashboardView::schema_fields_WEBSITE_ID] ?? 0),
            'owner_admin_id' => $ownerId > 0 ? $ownerId : null,
            'name' => (string)($row[DashboardView::schema_fields_NAME] ?? ''),
            'code' => (string)($row[DashboardView::schema_fields_CODE] ?? ''),
            'visibility' => $visibility,
            'is_default' => (int)($row[DashboardView::schema_fields_IS_DEFAULT] ?? 0) === 1,
            'is_active' => (int)($row[DashboardView::schema_fields_IS_ACTIVE] ?? 0) === 1,
            'sort_order' => (int)($row[DashboardView::schema_fields_SORT_ORDER] ?? 0),
            'created_at' => (string)($row[DashboardView::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[DashboardView::schema_fields_UPDATED_AT] ?? ''),
            'scope' => 'dashboard_view:' . $viewId,
            'layout_identity' => [
                'layout_option' => DashboardView::LAYOUT_OPTION,
                'scope' => 'dashboard_view:' . $viewId,
                'target_type' => DashboardView::TARGET_TYPE_WEBSITE,
                'target_id' => (int)($row[DashboardView::schema_fields_WEBSITE_ID] ?? 0),
            ],
            'can_edit' => $visibility !== DashboardView::VISIBILITY_SYSTEM && $ownerId > 0 && $ownerId === $userId,
        ];
    }

    public function viewToPayload(DashboardView $view, int $userId = 0): array
    {
        return $this->rowToPayload($view->getData(), $userId);
    }

    private function normalizeWebsiteId(int $websiteId): int
    {
        return $websiteId > 0 ? $websiteId : $this->getDefaultWebsiteId();
    }

    private function findSystemDefaultView(int $websiteId): ?DashboardView
    {
        try {
            $row = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_VISIBILITY, DashboardView::VISIBILITY_SYSTEM)
                ->where(DashboardView::schema_fields_IS_DEFAULT, 1)
                ->where(DashboardView::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) && (int)($row[DashboardView::schema_fields_ID] ?? 0) > 0
            ? $this->loadView((int)$row[DashboardView::schema_fields_ID])
            : null;
    }

    private function findDefaultView(int $websiteId): ?DashboardView
    {
        try {
            $row = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_IS_DEFAULT, 1)
                ->where(DashboardView::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) && (int)($row[DashboardView::schema_fields_ID] ?? 0) > 0
            ? $this->loadView((int)$row[DashboardView::schema_fields_ID])
            : null;
    }

    private function loadView(int $viewId): ?DashboardView
    {
        $view = clone $this->dashboardView;
        try {
            $view->clearQuery()->clearData()->load($viewId);
        } catch (\Throwable) {
            return null;
        }

        return $view->getViewId() > 0 ? $view : null;
    }

    private function rowIsVisibleTo(array $row, int $userId): bool
    {
        $visibility = (string)($row[DashboardView::schema_fields_VISIBILITY] ?? '');
        $ownerId = (int)($row[DashboardView::schema_fields_OWNER_ADMIN_ID] ?? 0);
        if ($visibility === DashboardView::VISIBILITY_SYSTEM || $visibility === DashboardView::VISIBILITY_PUBLIC) {
            return true;
        }

        return $visibility === DashboardView::VISIBILITY_PRIVATE && $ownerId > 0 && $ownerId === $userId;
    }

    private function requireEditableView(int $viewId, int $userId): DashboardView
    {
        $view = $this->getViewForUser($viewId, $userId);
        if (!$view) {
            throw new \RuntimeException((string)__('视图不存在或无权访问。'));
        }
        if (!$this->canEdit($view, $userId)) {
            throw new \RuntimeException((string)__('只能编辑自己创建的视图。'));
        }

        return $view;
    }

    private function clearOtherDefaults(int $websiteId, int $keepViewId): void
    {
        try {
            $rows = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_IS_DEFAULT, 1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            $viewId = (int)($row[DashboardView::schema_fields_ID] ?? 0);
            if ($viewId <= 0 || $viewId === $keepViewId) {
                continue;
            }
            $view = $this->loadView($viewId);
            if ($view) {
                $view->setIsDefault(false)->save();
            }
        }
    }

    private function uniqueCode(int $websiteId, int $ownerId, string $baseCode): string
    {
        $baseCode = $baseCode !== '' ? $baseCode : 'view';
        $code = $baseCode;
        $index = 1;
        while ($this->codeExists($websiteId, $ownerId, $code)) {
            $index++;
            $code = $baseCode . '-' . $index;
        }

        return $code;
    }

    private function codeExists(int $websiteId, int $ownerId, string $code): bool
    {
        try {
            $row = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_OWNER_ADMIN_ID, $ownerId)
                ->where(DashboardView::schema_fields_CODE, $code)
                ->find()
                ->fetchArray();
            return is_array($row) && (int)($row[DashboardView::schema_fields_ID] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function slug(string $name): string
    {
        $value = strtolower(trim($name));
        $value = preg_replace('/[^a-z0-9\\x{4e00}-\\x{9fa5}_\\-]+/u', '-', $value) ?: '';
        $value = preg_replace('/-+/', '-', $value) ?: '';
        $value = trim($value, '-_');
        if ($value === '') {
            $value = 'view';
        }
        if (preg_match('/[\\x{4e00}-\\x{9fa5}]/u', $value) === 1) {
            $value = 'view-' . substr(sha1($name), 0, 8);
        }

        return $value;
    }

    private function nextSortOrder(int $websiteId, int $userId): int
    {
        try {
            $rows = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_OWNER_ADMIN_ID, $userId)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return 10;
        }

        $max = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $max = max($max, (int)($row[DashboardView::schema_fields_SORT_ORDER] ?? 0));
        }

        return $max + 10;
    }

    private function copyLayout(DashboardView $source, DashboardView $target): bool
    {
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            return false;
        }
        $copied = false;
        foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
            $layout = $this->themeLayoutService->getLayout(
                $themeId,
                DashboardView::PAGE_TYPE,
                $status,
                $source->layoutIdentity()
            );
            $payload = [];
            foreach ($layout as $area => $areaData) {
                $widgets = is_array($areaData['widgets'] ?? null) ? $areaData['widgets'] : [];
                if ($widgets !== []) {
                    $payload[$area] = $widgets;
                }
            }
            if ($payload !== []) {
                $this->themeLayoutService->saveLayout(
                    $themeId,
                    DashboardView::PAGE_TYPE,
                    $payload,
                    $status,
                    $target->layoutIdentity()
                );
                $copied = true;
            }
        }

        return $copied;
    }

    private function hasLayoutRows(DashboardView $view): bool
    {
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            return false;
        }

        $identity = $view->layoutIdentity();
        try {
            $row = $this->themeLayout->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return false;
        }

        return is_array($row) && (int)($row[ThemeLayout::schema_fields_ID] ?? 0) > 0;
    }

    private function deleteLayoutRows(DashboardView $view): void
    {
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            return;
        }
        $identity = $view->layoutIdentity();
        try {
            $rows = $this->themeLayout->clearQuery()->clearData()
                ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, DashboardView::PAGE_TYPE)
                ->where(ThemeLayout::schema_fields_LAYOUT_OPTION, $identity['layout_option'])
                ->where(ThemeLayout::schema_fields_SCOPE, $identity['scope'])
                ->where(ThemeLayout::schema_fields_TARGET_TYPE, $identity['target_type'])
                ->where(ThemeLayout::schema_fields_TARGET_ID, $identity['target_id'])
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
            if ($layoutId <= 0) {
                continue;
            }
            try {
                $this->themeLayout->clearQuery()->clearData();
                $this->themeLayout->load($layoutId);
                $this->themeLayout->delete();
            } catch (\Throwable) {
            }
        }
        $this->themeLayout->clearQuery()->clearData();
    }
}
