<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Backend\Setup\Ui\IconDataMigrator;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutIdentityHasher;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.2.418';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->migrateSemanticIcons();
        $this->purgeLegacyLocalSharedChrome();
        $this->migrateHelpPageTypeToFaq();
        $this->migrateFooterHelpCenterLinkToFaq();
        $this->migrateScopedFooterHelpCenterLinkNodes();
        $this->migrateThemeLayoutEntitiesCutover();
        $this->migrateReconcileChromePayloadFromHomepageCarrier();
        $this->migratePublishActiveThemeCategoryFilters();
        $this->migrateProductListPageTypeToProducts();
        $this->migrateCheckoutSuccessFailureLayoutPaths();
    }

    /**
     * Injection-table rename is not enough: scoped drafts still block publish with
     * "widget not registered" until node widget_code is rewritten.
     */
    private function migrateScopedFooterHelpCenterLinkNodes(): void
    {
        try {
            /** @var \Weline\Theme\Service\Scoped\FooterHelpCenterLinkScopedMigrator $migrator */
            $migrator = ObjectManager::getInstance(
                \Weline\Theme\Service\Scoped\FooterHelpCenterLinkScopedMigrator::class,
            );
            $result = $migrator->migrate();
            if (($result['renamed_nodes'] ?? 0) > 0 || ($result['skipped'] ?? []) !== []) {
                w_log_info('theme_footer_help_center_scoped_migrate: ' . \json_encode($result, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            w_log_warning('theme_footer_help_center_scoped_migrate_failed: ' . $e->getMessage());
        }
    }

    private function migrateSemanticIcons(): void
    {
        try {
            ObjectManager::getInstance(IconDataMigrator::class)->migrate();
        } catch (\Throwable $e) {
            throw new \Weline\Framework\App\Exception(
                __('Weline UI 2.0 语义图标迁移失败：%{1}', [$e->getMessage()]),
                0,
                $e,
            );
        }
    }

    /**
     * 历史 * 默认注入在各业务布局留下的本地 chrome 副本，阻断全局共享；升级时清空非载体布局占用。
     * 使用 ScopeIdentity::global() 直接构造 ThemeEditorContext，避免旧 fromInput 缺 typed scope 时静默跳过。
     */
    private function purgeLegacyLocalSharedChrome(): void
    {
        try {
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->clear()->clearQuery()->select()->fetch();
            $items = \is_array($themes) ? $themes : [];
            if ($themes instanceof \Traversable) {
                $items = [];
                foreach ($themes as $row) {
                    $items[] = $row;
                }
            }
            if ($items === []) {
                $fallback = clone $themeModel;
                $fallback->clearData()->clearQuery()->load(1);
                if ((int)$fallback->getId() > 0) {
                    $items[] = $fallback;
                }
            }

            /** @var ScopeHierarchyInterface $scopes */
            $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
            /** @var SharedChromeService $chrome */
            $chrome = ObjectManager::getInstance(SharedChromeService::class);
            $scope = $scopes->contextFromIdentity(ScopeIdentity::global());

            foreach ($items as $theme) {
                $themeId = 0;
                if (\is_object($theme) && \method_exists($theme, 'getId')) {
                    $themeId = (int)$theme->getId();
                } elseif (\is_array($theme)) {
                    $themeId = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
                }
                if ($themeId <= 0) {
                    continue;
                }

                $context = new ThemeEditorContext(
                    scope: $scope,
                    area: 'frontend',
                    resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                    themeId: $themeId,
                    layoutType: ThemeLayout::PAGE_TYPE_HOME,
                    layoutOption: 'default',
                );

                $chrome->forceInheritAndPublishNonCarriers(
                    $context,
                    null,
                    null,
                    'setup:upgrade',
                    'Weline_Theme shared chrome default inherit',
                );
            }
        } catch (\Throwable $e) {
            // 存量清理失败不阻断模块升级；编辑器仍可通过 restore-chrome?all_non_carrier=1 手工收敛。
            w_log_warning('shared_chrome_legacy_purge_failed: ' . $e->getMessage());
        }
    }

    /**
     * Hard-cut page_type help → faq; recompute layout_identity_hash.
     */
    private function migrateHelpPageTypeToFaq(): void
    {
        try {
            $this->migrateThemeLayoutHelpRows();
            $this->migrateThemeLayoutVersionHelpRows();
            $this->migrateThemeWidgetDefaultInjectionHelpRows();
            $this->migrateThemeVirtualLayoutHelpRows();
        } catch (\Throwable $e) {
            w_log_warning('theme_help_to_faq_migrate_failed: ' . $e->getMessage());
        }
    }

    /**
     * Rename published/default footer help-center widget code to footer-faq-link.
     */
    private function migrateFooterHelpCenterLinkToFaq(): void
    {
        try {
            /** @var ThemeWidgetDefaultInjection $model */
            $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
            $rows = $model->clear()->clearQuery()
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, 'footer-help-center-link')
                ->select()
                ->fetch();
            foreach ($this->iterateRows($rows) as $row) {
                if (!\is_object($row) || !($row instanceof ThemeWidgetDefaultInjection)) {
                    continue;
                }
                $row->setData(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, 'footer-faq-link');
                $injectionKey = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY);
                if ($injectionKey !== '' && \str_contains($injectionKey, 'footer-help-center-link')) {
                    $row->setData(
                        ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY,
                        \str_replace('footer-help-center-link', 'footer-faq-link', $injectionKey),
                    );
                }
                $themeId = (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_THEME_ID);
                $componentArea = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA);
                $pageType = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE);
                $injectionKey = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY);
                $identity = new LayoutIdentity(
                    (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION),
                    (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_SCOPE),
                    (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE),
                    (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID),
                    (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE),
                );
                $row->setData(
                    ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                    LayoutIdentityHasher::injection(
                        $themeId,
                        $componentArea,
                        $pageType,
                        $identity,
                        $injectionKey,
                    ),
                );
                $row->save();
            }
        } catch (\Throwable $e) {
            w_log_warning('theme_footer_help_center_to_faq_failed: ' . $e->getMessage());
        }
    }

    private function migrateThemeLayoutHelpRows(): void
    {
        /** @var ThemeLayout $model */
        $model = ObjectManager::getInstance(ThemeLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, 'help')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayout)) {
                $row = $this->hydrateLayout($model, $row);
                if ($row === null) {
                    continue;
                }
            }
            $themeId = $row->getThemeId();
            $nodeUid = $row->getNodeUid();
            $status = $row->getStatus();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType(ThemeLayout::PAGE_TYPE_FAQ);
            $row->setData(
                ThemeLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::node($themeId, ThemeLayout::PAGE_TYPE_FAQ, $identity, $status, $nodeUid),
            );
            $row->save();
        }
    }

    private function migrateThemeLayoutVersionHelpRows(): void
    {
        /** @var ThemeLayoutVersion $model */
        $model = ObjectManager::getInstance(ThemeLayoutVersion::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, 'help')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayoutVersion)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType(ThemeLayout::PAGE_TYPE_FAQ);
            $row->setData(
                ThemeLayoutVersion::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::base($themeId, ThemeLayout::PAGE_TYPE_FAQ, $identity),
            );
            $row->save();
        }
    }

    private function migrateThemeWidgetDefaultInjectionHelpRows(): void
    {
        /** @var ThemeWidgetDefaultInjection $model */
        $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, 'help')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeWidgetDefaultInjection)) {
                continue;
            }
            $themeId = (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_THEME_ID);
            $componentArea = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA);
            $injectionKey = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY);
            $identity = new LayoutIdentity(
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_SCOPE),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE),
                (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE),
            );
            $row->setData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, ThemeLayout::PAGE_TYPE_FAQ);
            $row->setData(
                ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::injection(
                    $themeId,
                    $componentArea,
                    ThemeLayout::PAGE_TYPE_FAQ,
                    $identity,
                    $injectionKey,
                ),
            );
            $row->save();
        }
    }

    private function migrateThemeVirtualLayoutHelpRows(): void
    {
        /** @var ThemeVirtualLayout $model */
        $model = ObjectManager::getInstance(ThemeVirtualLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, 'help')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeVirtualLayout)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $area = $row->getArea();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setLayoutType(ThemeLayout::PAGE_TYPE_FAQ);
            $row->setData(
                ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::virtual($themeId, $area, ThemeLayout::PAGE_TYPE_FAQ, $identity),
            );
            $row->save();
        }
    }

    /**
     * Hard-cutover: bake ThemeScopeVersion chrome + page layout entities from published workspaces.
     * Fails loudly — incomplete bake must block module upgrade.
     */
    private function migrateThemeLayoutEntitiesCutover(): void
    {
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $themes = $themeModel->clear()->clearQuery()->select()->fetch();
        $themeItems = $this->iterateRows($themes);
        if ($themeItems === []) {
            $fallback = clone $themeModel;
            $fallback->clearData()->clearQuery()->load(1);
            if ((int)$fallback->getId() > 0) {
                $themeItems[] = $fallback;
            }
        }

        /** @var ThemeScopeWorkspace $workspaceModel */
        $workspaceModel = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        /** @var ThemeScopedWorkspaceInterface $workspaceService */
        $workspaceService = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var ThemeLayoutEntityBakeCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
        /** @var ThemeScopeVersionService $scopeVersions */
        $scopeVersions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        /** @var ThemeLayoutEntityPointerResolver $pointers */
        $pointers = ObjectManager::getInstance(ThemeLayoutEntityPointerResolver::class);
        /** @var ScopeHierarchyInterface $scopes */
        $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        /** @var SharedChromeService $chrome */
        $chrome = ObjectManager::getInstance(SharedChromeService::class);
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths $paths */
        $paths = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths::class,
        );

        $forceInheritThemes = [];

        foreach ($themeItems as $theme) {
            $themeId = 0;
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                $themeId = (int)$theme->getId();
            } elseif (\is_array($theme)) {
                $themeId = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
            }
            if ($themeId <= 0) {
                continue;
            }
            $forceInheritThemes[$themeId] = true;

            $rows = $this->iterateRows(
                $workspaceModel->clear()->clearQuery()
                    ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, ThemeEditorContext::RESOURCE_LAYOUT)
                    ->where(ThemeScopeWorkspace::schema_fields_AREA, 'frontend')
                    ->select()
                    ->fetch(),
            );

            $scopesSeen = [];
            $pageRows = [];
            foreach ($rows as $row) {
                $data = \is_object($row) && \method_exists($row, 'getData')
                    ? (array)$row->getData()
                    : (\is_array($row) ? $row : []);
                $scope = \trim((string)($data[ThemeScopeWorkspace::schema_fields_SCOPE] ?? ''));
                $identityHash = \trim((string)($data[ThemeScopeWorkspace::schema_fields_IDENTITY_HASH] ?? ''));
                $releaseId = (int)($data[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID] ?? 0);
                if ($scope === '' || $identityHash === '' || $releaseId < 1) {
                    continue;
                }
                $scopesSeen[$scope] = true;
                $pageRows[] = $data;
            }

            foreach (\array_keys($scopesSeen) as $scope) {
                $homeData = null;
                foreach ($pageRows as $data) {
                    if ((string)$data[ThemeScopeWorkspace::schema_fields_SCOPE] !== $scope) {
                        continue;
                    }
                    if ((string)$data[ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE] === ThemeLayout::PAGE_TYPE_HOME
                        && (string)($data[ThemeScopeWorkspace::schema_fields_TARGET_TYPE] ?? 'global') === 'global'
                    ) {
                        $homeData = $data;
                        break;
                    }
                }
                if ($homeData === null) {
                    $sample = null;
                    foreach ($pageRows as $data) {
                        if ((string)$data[ThemeScopeWorkspace::schema_fields_SCOPE] === $scope) {
                            $sample = $data;
                            break;
                        }
                    }
                    if ($sample === null) {
                        continue;
                    }
                    $base = $this->editorContextFromWorkspaceRow($scopes, $sample);
                    if ($base === null) {
                        throw new \RuntimeException(
                            'theme_layout_entity_cutover_context_invalid: theme=' . $themeId . ' scope=' . $scope,
                        );
                    }
                    $homeContext = $base->withLayoutType(ThemeLayout::PAGE_TYPE_HOME);
                } else {
                    $homeContext = $this->editorContextFromWorkspaceRow($scopes, $homeData);
                    if ($homeContext === null) {
                        throw new \RuntimeException(
                            'theme_layout_entity_cutover_home_context_invalid: theme=' . $themeId . ' scope=' . $scope,
                        );
                    }
                }

                $snapshot = $workspaceService->readPublishedSnapshot($homeContext);
                $payload = \is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
                $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
                $coordinator->bakeChromeFromNodes($themeId, $scope, $nodes, true);
                $version = $scopeVersions->getCurrent($themeId, $scope);
                if ($version === null) {
                    throw new \RuntimeException(
                        'theme_layout_entity_cutover_chrome_version_missing: theme=' . $themeId . ' scope=' . $scope,
                    );
                }
                $scopeVersions->markPublished($version);
                $chromePath = $paths->chromePhtml($themeId, $scope, $version->getVersionId());
                if (!\is_file($chromePath)) {
                    throw new \RuntimeException(
                        'theme_layout_entity_cutover_chrome_missing: ' . $chromePath,
                    );
                }
                $pointers->rememberChromePointer(
                    $themeId,
                    $scope,
                    $version->getVersionId(),
                    $chromePath,
                    true,
                );
            }

            foreach ($pageRows as $data) {
                $context = $this->editorContextFromWorkspaceRow($scopes, $data);
                if ($context === null) {
                    throw new \RuntimeException(
                        'theme_layout_entity_cutover_page_context_invalid: '
                        . (string)($data[ThemeScopeWorkspace::schema_fields_IDENTITY_HASH] ?? ''),
                    );
                }
                $snapshot = $workspaceService->readPublishedSnapshot($context);
                $payload = \is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
                $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
                $releaseId = (int)($snapshot['release_id']
                    ?? $data[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID]
                    ?? 0);
                $coordinator->bakePageFromNodes(
                    $themeId,
                    (string)$data[ThemeScopeWorkspace::schema_fields_SCOPE],
                    (string)$data[ThemeScopeWorkspace::schema_fields_IDENTITY_HASH],
                    (string)($data[ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE] ?? 'default'),
                    $nodes,
                    true,
                    $releaseId > 0 ? $releaseId : null,
                    0,
                );
            }
        }

        foreach (\array_keys($forceInheritThemes) as $themeId) {
            $scope = $scopes->contextFromIdentity(ScopeIdentity::global());
            $context = new ThemeEditorContext(
                scope: $scope,
                area: 'frontend',
                resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                themeId: $themeId,
                layoutType: ThemeLayout::PAGE_TYPE_HOME,
                layoutOption: 'default',
            );
            $chrome->forceInheritAndPublishNonCarriers(
                $context,
                null,
                null,
                'setup:upgrade',
                'Weline_Theme layout entity cutover inherit',
            );
        }
    }

    /**
     * Recover chrome payload + baked chrome.phtml when homepage carrier has footer/header
     * widgets but ThemeScopeVersion payload was truncated (config-only partial write).
     */
    private function migrateReconcileChromePayloadFromHomepageCarrier(): void
    {
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $themes = $themeModel->clear()->clearQuery()->select()->fetch();
        $themeItems = $this->iterateRows($themes);
        if ($themeItems === []) {
            $fallback = clone $themeModel;
            $fallback->clearData()->clearQuery()->load(1);
            if ((int)$fallback->getId() > 0) {
                $themeItems[] = $fallback;
            }
        }

        /** @var ThemeScopeWorkspace $workspaceModel */
        $workspaceModel = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        /** @var ThemeScopedWorkspaceInterface $workspaceService */
        $workspaceService = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var ThemeLayoutEntityBakeCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
        /** @var ThemeScopeVersionService $scopeVersions */
        $scopeVersions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        /** @var ThemeLayoutEntityPointerResolver $pointers */
        $pointers = ObjectManager::getInstance(ThemeLayoutEntityPointerResolver::class);
        /** @var ScopeHierarchyInterface $scopes */
        $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths $paths */
        $paths = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths::class,
        );

        foreach ($themeItems as $theme) {
            $themeId = 0;
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                $themeId = (int)$theme->getId();
            } elseif (\is_array($theme)) {
                $themeId = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
            }
            if ($themeId <= 0) {
                continue;
            }

            $rows = $this->iterateRows(
                $workspaceModel->clear()->clearQuery()
                    ->where(ThemeScopeWorkspace::schema_fields_THEME_ID, $themeId)
                    ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, ThemeEditorContext::RESOURCE_LAYOUT)
                    ->where(ThemeScopeWorkspace::schema_fields_AREA, 'frontend')
                    ->select()
                    ->fetch(),
            );

            $scopesSeen = [];
            $pageRows = [];
            foreach ($rows as $row) {
                $data = \is_object($row) && \method_exists($row, 'getData')
                    ? (array)$row->getData()
                    : (\is_array($row) ? $row : []);
                $scope = \trim((string)($data[ThemeScopeWorkspace::schema_fields_SCOPE] ?? ''));
                $releaseId = (int)($data[ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID] ?? 0);
                if ($scope === '' || $releaseId < 1) {
                    continue;
                }
                $scopesSeen[$scope] = true;
                $pageRows[] = $data;
            }

            foreach (\array_keys($scopesSeen) as $scope) {
                $homeData = null;
                foreach ($pageRows as $data) {
                    if ((string)$data[ThemeScopeWorkspace::schema_fields_SCOPE] !== $scope) {
                        continue;
                    }
                    if ((string)$data[ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE] === ThemeLayout::PAGE_TYPE_HOME
                        && (string)($data[ThemeScopeWorkspace::schema_fields_TARGET_TYPE] ?? 'global') === 'global'
                    ) {
                        $homeData = $data;
                        break;
                    }
                }
                if ($homeData === null) {
                    continue;
                }

                $homeContext = $this->editorContextFromWorkspaceRow($scopes, $homeData);
                if ($homeContext === null) {
                    continue;
                }

                $snapshot = $workspaceService->readPublishedSnapshot($homeContext);
                $payload = \is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
                $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
                if ($nodes === []) {
                    continue;
                }

                $coordinator->bakeChromeFromNodes($themeId, $scope, $nodes, true);
                $version = $scopeVersions->getCurrent($themeId, $scope);
                if ($version === null) {
                    continue;
                }
                $scopeVersions->markPublished($version);
                $chromePath = $paths->chromePhtml($themeId, $scope, $version->getVersionId());
                if (!\is_file($chromePath)) {
                    throw new \RuntimeException(
                        'theme_layout_entity_chrome_reconcile_missing: ' . $chromePath,
                    );
                }
                $pointers->rememberChromePointer(
                    $themeId,
                    $scope,
                    $version->getVersionId(),
                    $chromePath,
                    true,
                );
            }
        }
    }

    /**
     * Active storefront themes (e.g. child hanfu) may have unpublished chrome / missing
     * page workspaces after hard-cut — category filters / PDP slots stay as placeholders.
     * Apply required defaults for homepage+category+product, publish, mark chrome published.
     */
    private function migratePublishActiveThemeCategoryFilters(): void
    {
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        $active = clone $themeModel;
        $active->clearData()->clearQuery()
            ->where('is_active', 1)
            ->select()
            ->fetch();
        $themes = $this->iterateRows($active);
        if ($themes === []) {
            return;
        }

        /** @var ScopeHierarchyInterface $scopes */
        $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        /** @var ThemeScopedWorkspaceInterface $workspace */
        $workspace = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
        /** @var \Weline\Theme\Service\WidgetDefaultInjectionService $injection */
        $injection = ObjectManager::getInstance(
            \Weline\Theme\Service\WidgetDefaultInjectionService::class,
        );
        /** @var ThemeScopeVersionService $scopeVersions */
        $scopeVersions = ObjectManager::getInstance(ThemeScopeVersionService::class);
        /** @var ThemeLayoutEntityPointerResolver $pointers */
        $pointers = ObjectManager::getInstance(ThemeLayoutEntityPointerResolver::class);
        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths $paths */
        $paths = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths::class,
        );

        $scopeCtx = $scopes->contextFromIdentity(ScopeIdentity::global());
        $identity = [
            'layout_option' => 'default',
            'scope' => 'default.default.default',
            'target_type' => 'global',
            'target_id' => 0,
            'locale_code' => 'default',
        ];
        $pageTypes = [
            ThemeLayout::PAGE_TYPE_HOME,
            ThemeLayout::PAGE_TYPE_CATEGORY,
            ThemeLayout::PAGE_TYPE_PRODUCT,
        ];

        foreach ($themes as $theme) {
            $themeId = 0;
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                $themeId = (int)$theme->getId();
            } elseif (\is_array($theme)) {
                $themeId = (int)($theme['theme_id'] ?? $theme['id'] ?? 0);
            }
            if ($themeId < 1) {
                continue;
            }

            foreach ($pageTypes as $pageType) {
                try {
                    $injection->applyRequiredMissingForIdentity(
                        $themeId,
                        $pageType,
                        $identity,
                        'frontend',
                        ThemeLayout::STATUS_DRAFT,
                    );
                    $context = new ThemeEditorContext(
                        scope: $scopeCtx,
                        area: 'frontend',
                        resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                        themeId: $themeId,
                        layoutType: $pageType,
                        layoutOption: 'default',
                    );
                    $state = $workspace->load($context, true);
                    $revision = (int)($state['revision'] ?? 0);
                    if ($revision < 1) {
                        continue;
                    }
                    $parent = isset($state['expected_parent_release_id'])
                        ? (int)$state['expected_parent_release_id']
                        : null;
                    if ($parent !== null && $parent < 1) {
                        $parent = null;
                    }
                    $workspace->publish(
                        $context,
                        $revision,
                        $parent,
                        'setup:upgrade',
                        'Weline_Theme',
                        'Active theme required layout publish (home/category/product)',
                    );
                } catch (\Throwable $e) {
                    w_log_warning(
                        'theme_active_required_layout_migrate_failed: theme=' . $themeId
                        . ' page=' . $pageType . ' ' . $e->getMessage(),
                    );
                }
            }

            try {
                $scope = 'default.default.default';
                $version = $scopeVersions->getCurrent($themeId, $scope);
                if ($version === null || $version->getVersionId() < 1) {
                    continue;
                }
                if (!$version->isPublished()) {
                    $scopeVersions->markPublished($version);
                }
                $chromePath = $paths->chromePhtml($themeId, $scope, $version->getVersionId());
                if (\is_file($chromePath)) {
                    $pointers->rememberChromePointer(
                        $themeId,
                        $scope,
                        $version->getVersionId(),
                        $chromePath,
                        true,
                    );
                }
            } catch (\Throwable $e) {
                w_log_warning(
                    'theme_active_chrome_publish_migrate_failed: theme=' . $themeId
                    . ' ' . $e->getMessage(),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function editorContextFromWorkspaceRow(ScopeHierarchyInterface $scopes, array $data): ?ThemeEditorContext
    {
        try {
            $decoded = $scopes->fromStorageScope(
                (string)($data[ThemeScopeWorkspace::schema_fields_SCOPE] ?? ''),
                false,
            );
            if (!$decoded instanceof ScopeIdentity) {
                return null;
            }
            $websiteId = isset($data[ThemeScopeWorkspace::schema_fields_WEBSITE_ID])
                ? (int)$data[ThemeScopeWorkspace::schema_fields_WEBSITE_ID]
                : 0;
            $storeMode = (string)($data[ThemeScopeWorkspace::schema_fields_STORE_MODE] ?? ScopeIdentity::MODE_NORMAL);
            $identity = match ($decoded->scopeKind) {
                ScopeIdentity::KIND_GLOBAL => ScopeIdentity::global(),
                ScopeIdentity::KIND_WEBSITE => ScopeIdentity::website($websiteId, (string)$decoded->websiteCode),
                ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                    $websiteId,
                    (string)$decoded->websiteCode,
                    (string)$decoded->storeCode,
                    $storeMode,
                ),
                ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                    $websiteId,
                    (string)$decoded->websiteCode,
                    (string)$decoded->storeCode,
                    (string)$decoded->channelCode,
                    $storeMode,
                ),
                default => null,
            };
            if (!$identity instanceof ScopeIdentity) {
                return null;
            }

            return new ThemeEditorContext(
                scope: $scopes->contextFromIdentity($identity),
                area: (string)($data[ThemeScopeWorkspace::schema_fields_AREA] ?? 'frontend'),
                resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
                themeId: (int)($data[ThemeScopeWorkspace::schema_fields_THEME_ID] ?? 0),
                layoutType: (string)($data[ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE] ?? 'default'),
                layoutOption: (string)($data[ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION] ?? 'default'),
                locale: (string)($data[ThemeScopeWorkspace::schema_fields_LOCALE] ?? 'default'),
                targetType: (string)($data[ThemeScopeWorkspace::schema_fields_TARGET_TYPE] ?? 'global'),
                targetId: (int)($data[ThemeScopeWorkspace::schema_fields_TARGET_ID] ?? 0),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $rows
     * @return list<mixed>
     */
    private function iterateRows(mixed $rows): array
    {
        if (\is_array($rows)) {
            return \array_values($rows);
        }
        if ($rows instanceof \Traversable) {
            $out = [];
            foreach ($rows as $row) {
                $out[] = $row;
            }

            return $out;
        }

        return [];
    }

    /**
     * Hard-cut legacy page_type/layout_type product_list → products (matches /products).
     */
    private function migrateProductListPageTypeToProducts(): void
    {
        try {
            $this->migrateThemeLayoutProductListRows();
            $this->migrateThemeLayoutVersionProductListRows();
            $this->migrateThemeWidgetDefaultInjectionProductListRows();
            $this->migrateThemeVirtualLayoutProductListRows();
            $this->migrateThemeScopeWorkspaceProductListRows();
        } catch (\Throwable $e) {
            w_log_warning('theme_product_list_to_products_migrate_failed: ' . $e->getMessage());
        }
    }

    private function migrateThemeLayoutProductListRows(): void
    {
        /** @var ThemeLayout $model */
        $model = ObjectManager::getInstance(ThemeLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, 'product_list')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayout)) {
                $row = $this->hydrateLayout($model, $row);
                if ($row === null) {
                    continue;
                }
            }
            $themeId = $row->getThemeId();
            $nodeUid = $row->getNodeUid();
            $status = $row->getStatus();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType(ThemeLayout::PAGE_TYPE_PRODUCT_LIST);
            $row->setData(
                ThemeLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::node(
                    $themeId,
                    ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
                    $identity,
                    $status,
                    $nodeUid,
                ),
            );
            $row->save();
        }
    }

    private function migrateThemeLayoutVersionProductListRows(): void
    {
        /** @var ThemeLayoutVersion $model */
        $model = ObjectManager::getInstance(ThemeLayoutVersion::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, 'product_list')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayoutVersion)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType(ThemeLayout::PAGE_TYPE_PRODUCT_LIST);
            $row->setData(
                ThemeLayoutVersion::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::base($themeId, ThemeLayout::PAGE_TYPE_PRODUCT_LIST, $identity),
            );
            $row->save();
        }
    }

    private function migrateThemeWidgetDefaultInjectionProductListRows(): void
    {
        /** @var ThemeWidgetDefaultInjection $model */
        $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, 'product_list')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeWidgetDefaultInjection)) {
                continue;
            }
            $themeId = (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_THEME_ID);
            $componentArea = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA);
            $injectionKey = (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY);
            $identity = new LayoutIdentity(
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_SCOPE),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE),
                (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID),
                (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE),
            );
            $row->setData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, ThemeLayout::PAGE_TYPE_PRODUCT_LIST);
            $row->setData(
                ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::injection(
                    $themeId,
                    $componentArea,
                    ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
                    $identity,
                    $injectionKey,
                ),
            );
            $row->save();
        }
    }

    private function migrateThemeVirtualLayoutProductListRows(): void
    {
        /** @var ThemeVirtualLayout $model */
        $model = ObjectManager::getInstance(ThemeVirtualLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, 'product_list')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeVirtualLayout)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $area = $row->getArea();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setLayoutType(ThemeLayout::PAGE_TYPE_PRODUCT_LIST);
            $row->setData(
                ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::virtual($themeId, $area, ThemeLayout::PAGE_TYPE_PRODUCT_LIST, $identity),
            );
            $row->save();
        }
    }

    private function migrateThemeScopeWorkspaceProductListRows(): void
    {
        /** @var ThemeScopeWorkspace $model */
        $model = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, 'product_list')
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeScopeWorkspace)) {
                continue;
            }
            $row->setData(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, ThemeLayout::PAGE_TYPE_PRODUCT_LIST);
            $row->save();
        }
    }

    /**
     * Nested layout paths: checkout_success → checkout/success (layouts/checkout/success/default.phtml).
     */
    private function migrateCheckoutSuccessFailureLayoutPaths(): void
    {
        $map = [
            'checkout_success' => ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS,
            'checkout_failure' => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
            'checkout_failer' => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
        ];
        try {
            foreach ($map as $from => $to) {
                $this->renameThemeLayoutPageType($from, $to);
                $this->renameThemeLayoutVersionPageType($from, $to);
                $this->renameThemeWidgetDefaultInjectionPageType($from, $to);
                $this->renameThemeVirtualLayoutType($from, $to);
                $this->renameThemeScopeWorkspaceLayoutType($from, $to);
            }
        } catch (\Throwable $e) {
            w_log_warning('theme_checkout_success_failure_path_migrate_failed: ' . $e->getMessage());
        }
    }

    private function renameThemeLayoutPageType(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        /** @var ThemeLayout $model */
        $model = ObjectManager::getInstance(ThemeLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, $from)
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayout)) {
                $row = $this->hydrateLayout($model, $row);
                if ($row === null) {
                    continue;
                }
            }
            $themeId = $row->getThemeId();
            $nodeUid = $row->getNodeUid();
            $status = $row->getStatus();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType($to);
            $row->setData(
                ThemeLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::node(
                    $themeId,
                    $to,
                    $identity,
                    $status,
                    $nodeUid,
                ),
            );
            $row->save();
        }
    }

    private function renameThemeLayoutVersionPageType(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        /** @var ThemeLayoutVersion $model */
        $model = ObjectManager::getInstance(ThemeLayoutVersion::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $from)
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeLayoutVersion)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setPageType($to);
            $row->setData(
                ThemeLayoutVersion::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::base($themeId, $to, $identity),
            );
            $row->save();
        }
    }

    private function renameThemeWidgetDefaultInjectionPageType(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        /** @var ThemeWidgetDefaultInjection $model */
        $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $from)
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeWidgetDefaultInjection)) {
                continue;
            }
            $row->setData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $to);
            $row->save();
        }
    }

    private function renameThemeVirtualLayoutType(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        /** @var ThemeVirtualLayout $model */
        $model = ObjectManager::getInstance(ThemeVirtualLayout::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeVirtualLayout::schema_fields_LAYOUT_TYPE, $from)
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeVirtualLayout)) {
                continue;
            }
            $themeId = $row->getThemeId();
            $area = $row->getArea();
            $identity = new LayoutIdentity(
                $row->getLayoutOption(),
                $row->getScope(),
                $row->getTargetType(),
                $row->getTargetId(),
                $row->getLocaleCode(),
            );
            $row->setLayoutType($to);
            $row->setData(
                ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                LayoutIdentityHasher::virtual($themeId, $area, $to, $identity),
            );
            $row->save();
        }
    }

    private function renameThemeScopeWorkspaceLayoutType(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        /** @var ThemeScopeWorkspace $model */
        $model = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        $rows = $model->clear()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $from)
            ->select()
            ->fetch();
        foreach ($this->iterateRows($rows) as $row) {
            if (!\is_object($row) || !($row instanceof ThemeScopeWorkspace)) {
                continue;
            }
            $row->setData(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE, $to);
            $row->save();
        }
    }

    private function hydrateLayout(ThemeLayout $prototype, mixed $row): ?ThemeLayout
    {
        if (!\is_array($row)) {
            return null;
        }
        $id = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
        if ($id < 1) {
            return null;
        }
        $item = clone $prototype;
        $item->clearData()->clearQuery()->load($id);

        return (int)$item->getId() > 0 ? $item : null;
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }
}
