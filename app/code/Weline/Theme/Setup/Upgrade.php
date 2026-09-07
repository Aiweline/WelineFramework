<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Backend\Setup\Ui\IconDataMigrator;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SharedChromeService;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.2.189';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->migrateSemanticIcons();
        $this->purgeLegacyLocalSharedChrome();
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

                $chrome->restoreNonCarrierLayouts(
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

    public function getVersion(): string
    {
        return self::VERSION;
    }
}
