<?php

declare(strict_types=1);

namespace Weline\Dashboard\Service;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Dashboard\Model\DashboardView;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\DefaultWebsiteService;

class DashboardViewService
{
    public const DEFAULT_WEBSITE_ID = 0;

    public function __construct(
        private readonly DashboardView $dashboardView,
        private readonly Website $website,
        private readonly BackendUserConfig $backendUserConfig,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeLayoutService $themeLayoutService,
        private readonly ThemeLayoutVersionService $themeLayoutVersionService,
        private readonly ThemeLayout $themeLayout,
        private readonly DefaultWebsiteService $defaultWebsiteService,
    ) {
    }

    public function getCurrentUserId(): int
    {
        return max(0, $this->backendUserConfig->getCurrentUserId());
    }

    public function getDefaultWebsiteId(): int
    {
        try {
            $row = $this->defaultWebsiteService->ensureDefaultWebsite(false);
            if ((string)($row[Website::schema_fields_CODE] ?? '') === Website::CODE_DEFAULT) {
                return max(self::DEFAULT_WEBSITE_ID, (int)($row[Website::schema_fields_ID] ?? self::DEFAULT_WEBSITE_ID));
            }
        } catch (\Throwable) {
        }

        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)
                ->find()
                ->fetchArray();
            if (is_array($row) && array_key_exists(Website::schema_fields_ID, $row)) {
                return max(self::DEFAULT_WEBSITE_ID, (int)($row[Website::schema_fields_ID] ?? self::DEFAULT_WEBSITE_ID));
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
            $this->defaultWebsiteService->ensureDefaultWebsite(false);
        } catch (\Throwable) {
        }

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
            if ($websiteId < self::DEFAULT_WEBSITE_ID) {
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
        if ($websiteId < self::DEFAULT_WEBSITE_ID) {
            return null;
        }

        $existing = $this->findSystemDefaultView($websiteId);
        if ($existing) {
            $this->ensureLayoutInitialized($existing);
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
        $this->ensureLayoutInitialized($view);

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
        if ($websiteId < self::DEFAULT_WEBSITE_ID) {
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

    /**
     * Ensure a module-owned dashboard page identity exists for a website.
     *
     * The page identity is stored as a Dashboard view, while its widgets remain
     * normal Theme layout rows under the view's layout identity.
     *
     * @param array<string,list<array<string,mixed>>>|list<array<string,mixed>> $layoutData
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ensureSharedLayoutPage(
        int $websiteId,
        string $code,
        string $name,
        array $layoutData = [],
        array $options = []
    ): array {
        $websiteId = $this->normalizeWebsiteId($websiteId);
        if ($websiteId < self::DEFAULT_WEBSITE_ID) {
            throw new \InvalidArgumentException((string)__('缺少站点。'));
        }

        $code = $this->normalizePageCode($code, $name);
        $visibility = (string)($options['visibility'] ?? DashboardView::VISIBILITY_SYSTEM);
        if (!in_array($visibility, [DashboardView::VISIBILITY_SYSTEM, DashboardView::VISIBILITY_PUBLIC], true)) {
            $visibility = DashboardView::VISIBILITY_SYSTEM;
        }

        $view = $this->findSharedViewByCode($websiteId, $code);
        $created = false;
        if (!$view) {
            $view = clone $this->dashboardView;
            $view->clearQuery()->clearData()
                ->setWebsiteId($websiteId)
                ->setOwnerAdminId(null)
                ->setName($name)
                ->setCode($code)
                ->setVisibility($visibility)
                ->setIsDefault(false)
                ->setIsActive(true)
                ->setSortOrder((int)($options['sort_order'] ?? $this->nextSharedSortOrder($websiteId)))
                ->save();
            $created = true;
        } else {
            $changed = false;
            if (!empty($options['update_name']) && $view->getName() !== trim($name)) {
                $view->setName($name);
                $changed = true;
            }
            if (!empty($options['update_visibility']) && $view->getVisibility() !== $visibility) {
                $view->setVisibility($visibility);
                $changed = true;
            }
            if (!$view->isActive()) {
                $view->setIsActive(true);
                $changed = true;
            }
            if ($changed) {
                $view->save();
            }
        }

        $this->ensureLayoutInitialized($view);

        $replaceLayout = !empty($options['replace_layout']);
        $hasLayout = $this->hasLayoutRows($view);
        $layoutResult = [
            'success' => true,
            'status' => $hasLayout ? 'kept_existing_layout' : 'empty_layout',
            'seeded' => [],
        ];

        if ($replaceLayout || !$hasLayout) {
            if ($layoutData !== []) {
                $layoutResult = $this->seedLayout($view, $layoutData);
            } elseif (($options['copy_default_layout'] ?? true) === true) {
                $source = $this->findDefaultView($websiteId) ?? $this->ensureDefaultView($websiteId);
                $layoutResult = $source && $source->getViewId() !== $view->getViewId()
                    ? $this->copyLayoutIdentity($source, $view)
                    : [
                        'success' => false,
                        'status' => 'missing_source_layout_identity',
                        'copied' => [],
                    ];
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'layout' => $layoutResult,
            'view' => $this->viewToPayload($view, 0),
            'identity' => $view->layoutIdentity(),
        ];
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
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0 || $view->getViewId() <= 0) {
            return;
        }

        try {
            $this->themeLayoutVersionService->initializeVersionIfNeeded(
                $themeId,
                DashboardView::PAGE_TYPE,
                null,
                $view->layoutIdentity()
            );
        } catch (\Throwable) {
        }
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
        return $websiteId >= self::DEFAULT_WEBSITE_ID ? $websiteId : $this->getDefaultWebsiteId();
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

    private function findSharedViewByCode(int $websiteId, string $code): ?DashboardView
    {
        try {
            $row = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_OWNER_ADMIN_ID, null, 'IS NULL')
                ->where(DashboardView::schema_fields_CODE, $code)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return is_array($row) && (int)($row[DashboardView::schema_fields_ID] ?? 0) > 0
            ? $this->loadView((int)$row[DashboardView::schema_fields_ID])
            : null;
    }

    private function normalizePageCode(string $code, string $fallbackName): string
    {
        $value = strtolower(trim($code));
        $value = preg_replace('/[^a-z0-9_\\-]+/', '-', $value) ?: '';
        $value = trim($value, '-_');
        if ($value === '') {
            $value = $this->slug($fallbackName);
        }
        if ($value === '') {
            $value = 'module-page';
        }

        return mb_substr($value, 0, 120);
    }

    private function nextSharedSortOrder(int $websiteId): int
    {
        try {
            $rows = $this->dashboardView->clearQuery()->clearData()
                ->where(DashboardView::schema_fields_WEBSITE_ID, $websiteId)
                ->where(DashboardView::schema_fields_OWNER_ADMIN_ID, null, 'IS NULL')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return 100;
        }

        $max = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $max = max($max, (int)($row[DashboardView::schema_fields_SORT_ORDER] ?? 0));
        }

        return max(100, $max + 10);
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
        $result = $this->copyLayoutIdentity($source, $target);
        if (empty($result['success'])) {
            return false;
        }

        return array_sum(array_map('intval', $result['copied'] ?? [])) > 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function copyLayoutIdentity(DashboardView $source, DashboardView $target): array
    {
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            return [
                'success' => false,
                'status' => 'missing_backend_theme',
                'copied' => [],
            ];
        }

        return $this->themeLayoutService->copyLayoutIdentity(
            $themeId,
            DashboardView::PAGE_TYPE,
            $source->layoutIdentity(),
            $target->layoutIdentity()
        );
    }

    /**
     * @param array<string,list<array<string,mixed>>>|list<array<string,mixed>> $layoutData
     * @return array<string,mixed>
     */
    private function seedLayout(DashboardView $view, array $layoutData): array
    {
        $themeId = $this->getBackendThemeId();
        if ($themeId <= 0) {
            return [
                'success' => false,
                'status' => 'missing_backend_theme',
                'seeded' => [],
            ];
        }

        $payload = $this->normalizeSeedLayout($layoutData);
        if ($payload === []) {
            return [
                'success' => false,
                'status' => 'empty_seed_layout',
                'seeded' => [],
            ];
        }

        $seeded = [];
        foreach ([ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED] as $status) {
            $this->themeLayoutService->saveLayout(
                $themeId,
                DashboardView::PAGE_TYPE,
                $payload,
                $status,
                $view->layoutIdentity()
            );
            $seeded[$status] = array_sum(array_map('count', $payload));
        }

        return [
            'success' => true,
            'status' => 'seeded',
            'seeded' => $seeded,
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>>|list<array<string,mixed>> $layoutData
     * @return array<string,list<array<string,mixed>>>
     */
    private function normalizeSeedLayout(array $layoutData): array
    {
        $grouped = [];
        foreach ($layoutData as $area => $widgets) {
            if (is_string($area) && is_array($widgets) && $this->arrayIsList($widgets)) {
                foreach ($widgets as $widget) {
                    if (!is_array($widget)) {
                        continue;
                    }
                    $normalized = $this->normalizeSeedWidget($widget, $area);
                    if ($normalized !== null) {
                        $grouped[$area][] = $normalized;
                    }
                }
                continue;
            }

            if (is_array($widgets)) {
                $normalized = $this->normalizeSeedWidget($widgets);
                if ($normalized !== null) {
                    $widgetArea = (string)($widgets['area'] ?? 'content');
                    $grouped[$widgetArea][] = $normalized;
                }
            }
        }

        foreach ($grouped as &$widgets) {
            usort($widgets, static fn(array $a, array $b): int => ((int)($a['_sort_order'] ?? 0)) <=> ((int)($b['_sort_order'] ?? 0)));
            foreach ($widgets as &$widget) {
                unset($widget['_sort_order']);
            }
            unset($widget);
        }
        unset($widgets);

        return $grouped;
    }

    /**
     * @param array<string,mixed> $widget
     * @return array<string,mixed>|null
     */
    private function normalizeSeedWidget(array $widget, string $defaultArea = 'content'): ?array
    {
        $module = trim((string)($widget['widget_module'] ?? $widget['module'] ?? ''));
        $type = trim((string)($widget['widget_type'] ?? $widget['type'] ?? ''));
        $code = trim((string)($widget['widget_code'] ?? $widget['code'] ?? ''));
        if ($module === '' || $type === '' || $code === '') {
            return null;
        }

        $sortOrder = (int)($widget['sort_order'] ?? $widget['position'] ?? 0);

        return [
            'widget_module' => $module,
            'widget_type' => $type,
            'widget_code' => $code,
            'slot_id' => $widget['slot_id'] ?? $widget['slot'] ?? null,
            'config' => is_array($widget['config'] ?? null) ? $widget['config'] : [],
            'is_active' => (bool)($widget['is_active'] ?? true),
            '_sort_order' => $sortOrder,
            'area' => (string)($widget['area'] ?? $defaultArea),
        ];
    }

    /**
     * @param array<mixed> $value
     */
    private function arrayIsList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
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
