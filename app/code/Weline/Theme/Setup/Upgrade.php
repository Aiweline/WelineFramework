<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Backend\Setup\Ui\IconDataMigrator;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ProductPageLayoutNormalizer;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\Scoped\ThemeScopeMigrationService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.1.6';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->backfillDefaultAreaThemes();
        $this->relocateProductBestsellersFromSidebar();
        $this->reassignProductRelatedProductsModule();
        $this->reassignProductCrossSellModule();
        $this->reassignCategoryRecommendedProducts();
        $this->reassignProductListRecommendedProducts();
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

    private function reassignProductRelatedProductsModule(): void
    {
        try {
            ObjectManager::getInstance(ProductPageLayoutNormalizer::class)
                ->reassignRelatedProductsModuleInDatabase();
        } catch (\Throwable $e) {
            throw new Exception(__(
                '主题布局迁移失败（related-products 归属 Product）：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function reassignProductCrossSellModule(): void
    {
        try {
            ObjectManager::getInstance(ProductPageLayoutNormalizer::class)
                ->reassignCrossSellModuleInDatabase();
        } catch (\Throwable $e) {
            throw new Exception(__(
                '主题布局迁移失败（cross-sell 归属 Product）：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function reassignCategoryRecommendedProducts(): void
    {
        try {
            /** @var \Weline\Theme\Model\ThemeLayout $themeLayout */
            $themeLayout = ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class);
            $rows = $themeLayout->reset()
                ->where(\Weline\Theme\Model\ThemeLayout::schema_fields_PAGE_TYPE, \Weline\Theme\Model\ThemeLayout::PAGE_TYPE_CATEGORY)
                ->where(\Weline\Theme\Model\ThemeLayout::schema_fields_SLOT_ID, 'category-recommendations')
                ->select()
                ->fetchArray();
            if (!is_array($rows) || $rows === []) {
                return;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $layoutId = (int)($row[\Weline\Theme\Model\ThemeLayout::schema_fields_ID] ?? 0);
                if ($layoutId <= 0) {
                    continue;
                }
                $themeLayout->clearQuery()->clearData()->load($layoutId);
                if (!$themeLayout->getLayoutId()) {
                    continue;
                }
                $themeLayout
                    ->setWidgetCode('recommended-products')
                    ->setWidgetModule('Weline_Product')
                    ->setWidgetType('product')
                    ->setWidgetConfig([
                        'title' => '推荐产品',
                        'limit' => 8,
                        'columns' => '4',
                        'layout' => 'grid',
                    ])
                    ->save();
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '主题布局迁移失败（category recommended-products）：%{1}',
                [$e->getMessage()]
            ), 0, $e);
        }
    }

    private function reassignProductListRecommendedProducts(): void
    {
        try {
            /** @var ThemeLayout $themeLayout */
            $themeLayout = ObjectManager::getInstance(ThemeLayout::class);
            $rows = $themeLayout->reset()
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, ThemeLayout::PAGE_TYPE_PRODUCT_LIST)
                ->where(ThemeLayout::schema_fields_SLOT_ID, 'list-recommendations')
                ->select()
                ->fetchArray();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
                    if ($layoutId <= 0) {
                        continue;
                    }
                    $themeLayout->clearQuery()->clearData()->load($layoutId);
                    if (!$themeLayout->getLayoutId()) {
                        continue;
                    }
                    $themeLayout
                        ->setWidgetCode('recommended-products')
                        ->setWidgetModule('Weline_Product')
                        ->setWidgetType('product')
                        ->setWidgetConfig([
                            'title' => '推荐产品',
                            'limit' => 8,
                            'columns' => '4',
                            'layout' => 'grid',
                        ])
                        ->save();
                }
            }

            ObjectManager::getInstance(\Weline\Widget\Service\WidgetRegistry::class)->refresh();
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeComponentCatalog::class)->clearCache();
            /** @var WidgetDefaultInjectionService $injectionService */
            $injectionService = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->reset()->select()->fetchArray();
            if (!is_array($themes)) {
                return;
            }

            $identity = [
                'layout_option' => 'default',
                'scope' => 'default.default.default',
                'locale_code' => '',
                'target_type' => 'global',
                'target_id' => 0,
            ];
            foreach ($themes as $themeRow) {
                if (!is_array($themeRow)) {
                    continue;
                }
                $themeId = (int)($themeRow[WelineTheme::schema_fields_ID] ?? 0);
                if ($themeId <= 0) {
                    continue;
                }
                $injectionService->initSlotDefaultInjections(
                    $themeId,
                    ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
                    $identity,
                    'list-recommendations',
                    PreviewContextService::AREA_FRONTEND,
                    ThemeLayout::STATUS_PUBLISHED,
                );
            }
        } catch (\Throwable $e) {
            throw new Exception(__(
                '主题布局迁移失败（product_list recommended-products）：%{1}',
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
