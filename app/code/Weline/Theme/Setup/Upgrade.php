<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Backend\Setup\Ui\IconDataMigrator;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ProductPageLayoutNormalizer;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\Scoped\ThemeScopeMigrationService;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.1.1';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->backfillDefaultAreaThemes();
        $this->relocateProductBestsellersFromSidebar();
        $this->migrateSemanticIcons();
        $this->migrateScopedThemeInheritance();
    }

    private function migrateScopedThemeInheritance(): void
    {
        try {
            $result = ObjectManager::getInstance(ThemeScopeMigrationService::class)->apply();
            Env::log_info(
                'theme_scope_migration',
                (string)(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            );
        } catch (\Throwable $e) {
            throw new Exception(__('Theme 2.1.1 Scope 继承迁移失败：%{1}', [$e->getMessage()]), 0, $e);
        }
    }

    private function migrateSemanticIcons(): void
    {
        try {
            ObjectManager::getInstance(IconDataMigrator::class)->migrate();
        } catch (\Throwable $e) {
            throw new Exception(__('Weline UI 2.0 语义图标迁移失败：%{1}', [$e->getMessage()]), 0, $e);
        }
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }

    public function collectDefaultThemeActivationUpdates(
        WelineTheme $theme,
        ThemeContextService $themeContext,
        callable $hasActiveThemeForField
    ): array {
        if (!$theme->getId()) {
            return [];
        }

        $updates = [];
        $activationFields = [
            ThemeContextService::AREA_FRONTEND => $this->getFrontendActiveField(),
            ThemeContextService::AREA_BACKEND => $this->getBackendActiveField(),
        ];

        foreach ($activationFields as $area => $field) {
            if ((int)$theme->getData($field) === 1) {
                continue;
            }
            if (!$themeContext->themeSupportsArea($theme, $area)) {
                continue;
            }
            if ($hasActiveThemeForField($field)) {
                continue;
            }
            $updates[$field] = 1;
        }

        if (!empty($updates) && (int)$theme->getData($this->getLegacyActiveField()) !== 1) {
            $updates[$this->getLegacyActiveField()] = 1;
        }

        return $updates;
    }

    private function relocateProductBestsellersFromSidebar(): void
    {
        try {
            ObjectManager::getInstance(ProductPageLayoutNormalizer::class)
                ->relocateBestsellersInDatabase();
        } catch (\Throwable $e) {
            throw new Exception(__(
                '主题布局迁移失败（bestsellers 侧栏归位）：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function backfillDefaultAreaThemes(): void
    {
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery();
            $theme->load($this->getModuleNameField(), 'Weline_Theme');
            if (!$theme->getId()) {
                return;
            }
            $themeId = (int)$theme->getId();

            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $updates = $this->collectDefaultThemeActivationUpdates(
                $theme,
                $themeContext,
                function (string $field): bool {
                    // 禁止复用共享单例：clearData/load 会冲掉外层已加载的默认主题行，导致 save 变成无 name 的 INSERT。
                    /** @var WelineTheme $activeTheme */
                    $activeTheme = ObjectManager::create(WelineTheme::class, [], false);
                    $activeTheme->clearData()->clearQuery();
                    $activeTheme->load($field, 1);
                    return (bool)$activeTheme->getId();
                }
            );

            if (empty($updates)) {
                return;
            }

            $theme->clearData()->clearQuery()->load($themeId);
            if (!$theme->getId()) {
                throw new Exception(__('默认主题区域激活回填失败：主题 #%{1} 在探测后无法重新加载', [(string)$themeId]));
            }
            foreach ($updates as $field => $value) {
                $theme->setData($field, $value);
            }
            $theme->save();
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Exception(__(
                '默认主题区域激活回填失败：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function getModuleNameField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_MODULE_NAME')
            ? WelineTheme::schema_fields_MODULE_NAME
            : 'module_name';
    }

    private function getLegacyActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE')
            ? WelineTheme::schema_fields_IS_ACTIVE
            : 'is_active';
    }

    private function getFrontendActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE_FRONTEND')
            ? WelineTheme::schema_fields_IS_ACTIVE_FRONTEND
            : 'is_active_frontend';
    }

    private function getBackendActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE_BACKEND')
            ? WelineTheme::schema_fields_IS_ACTIVE_BACKEND
            : 'is_active_backend';
    }
}
