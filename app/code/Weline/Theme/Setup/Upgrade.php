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
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SharedChromeService;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.2.327';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->migrateSemanticIcons();
        $this->purgeLegacyLocalSharedChrome();
        $this->migrateHelpPageTypeToFaq();
        $this->migrateFooterHelpCenterLinkToFaq();
        $this->migrateScopedFooterHelpCenterLinkNodes();
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
