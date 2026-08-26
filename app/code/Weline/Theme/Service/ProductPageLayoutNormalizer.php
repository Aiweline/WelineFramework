<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;

/**
 * 商品详情页布局校正：热销产品不应占用右侧栏；相关产品 / 经常一起买归属 Product 模块。
 */
final class ProductPageLayoutNormalizer
{
    private const WIDGET_CODE_BESTSELLERS = 'bestsellers';
    private const WIDGET_CODE_RELATED_PRODUCTS = 'related-products';
    private const WIDGET_CODE_CROSS_SELL = 'cross-sell';
    private const SLOT_PRODUCT_RELATED = 'product-related';
    private const SLOT_PRODUCT_RELATED_PRODUCTS = 'product-related-products';
    private const SLOT_PRODUCT_CROSS_SELL = 'product-cross-sell';
    private const MODULE_THEME = 'Weline_Theme';
    private const MODULE_PRODUCT = 'Weline_Product';

    public function relocateBestsellersInDatabase(): void
    {
        try {
            /** @var ThemeLayout $themeLayout */
            $themeLayout = ObjectManager::getInstance(ThemeLayout::class);
            $rows = $themeLayout->reset()
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, ThemeLayout::PAGE_TYPE_PRODUCT)
                ->where(ThemeLayout::schema_fields_WIDGET_CODE, self::WIDGET_CODE_BESTSELLERS)
                ->where(ThemeLayout::schema_fields_AREA, ThemeLayout::AREA_RIGHT_SIDEBAR)
                ->select()
                ->fetchArray();

            if (!is_array($rows) || $rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $layoutId = (int)($row[ThemeLayout::schema_fields_ID] ?? 0);
                if ($layoutId <= 0) {
                    continue;
                }

                $config = $this->normalizeBestsellersConfig(
                    $row[ThemeLayout::schema_fields_CONFIG] ?? []
                );

                $themeLayout->clearQuery()->clearData()->load($layoutId);
                if (!$themeLayout->getLayoutId()) {
                    continue;
                }

                $themeLayout
                    ->setArea(ThemeLayout::AREA_CONTENT)
                    ->setSlotId(self::SLOT_PRODUCT_RELATED)
                    ->setSortOrder(2)
                    ->setWidgetConfig($config)
                    ->save();
            }
        } catch (\Throwable) {
        }
    }

    public function reassignRelatedProductsModuleInDatabase(): void
    {
        $this->reassignProductOwnedWidgetInDatabase(
            self::WIDGET_CODE_RELATED_PRODUCTS,
            self::SLOT_PRODUCT_RELATED_PRODUCTS
        );
    }

    public function reassignCrossSellModuleInDatabase(): void
    {
        $this->reassignProductOwnedWidgetInDatabase(
            self::WIDGET_CODE_CROSS_SELL,
            self::SLOT_PRODUCT_CROSS_SELL
        );
    }

    private function reassignProductOwnedWidgetInDatabase(string $widgetCode, string $slotId): void
    {
        try {
            /** @var ThemeLayout $themeLayout */
            $themeLayout = ObjectManager::getInstance(ThemeLayout::class);
            $rows = $themeLayout->reset()
                ->where(ThemeLayout::schema_fields_PAGE_TYPE, ThemeLayout::PAGE_TYPE_PRODUCT)
                ->where(ThemeLayout::schema_fields_WIDGET_CODE, $widgetCode)
                ->where(ThemeLayout::schema_fields_WIDGET_MODULE, self::MODULE_THEME)
                ->select()
                ->fetchArray();

            if (!is_array($rows) || $rows === []) {
                return;
            }

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
                    ->setWidgetModule(self::MODULE_PRODUCT)
                    ->setSlotId($slotId)
                    ->save();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * 渲染前将仍配置在右侧栏的热销产品挪到全宽推荐区（与 DB 种子/迁移目标一致）。
     */
    public function normalizeLayoutForRender(string $pageType, array $layout): array
    {
        if ($pageType !== ThemeLayout::PAGE_TYPE_PRODUCT) {
            return $layout;
        }

        $layout = $this->reassignRelatedProductsModuleInLayout($layout);
        $layout = $this->reassignCrossSellModuleInLayout($layout);

        $rightWidgets = $layout[ThemeLayout::AREA_RIGHT_SIDEBAR]['widgets'] ?? null;
        if (!is_array($rightWidgets) || $rightWidgets === []) {
            return $layout;
        }

        $remaining = [];
        $moved = [];

        foreach ($rightWidgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (($widget['widget_code'] ?? '') === self::WIDGET_CODE_BESTSELLERS) {
                $widget['slot_id'] = self::SLOT_PRODUCT_RELATED;
                $widget['sort_order'] = (int)($widget['sort_order'] ?? 2);
                $widget['config'] = $this->normalizeBestsellersConfig($widget['config'] ?? []);
                $moved[] = $widget;
                continue;
            }
            $remaining[] = $widget;
        }

        if ($moved === []) {
            return $layout;
        }

        $layout[ThemeLayout::AREA_RIGHT_SIDEBAR]['widgets'] = $remaining;

        if (!isset($layout[ThemeLayout::AREA_CONTENT]['widgets'])
            || !is_array($layout[ThemeLayout::AREA_CONTENT]['widgets'])) {
            $layout[ThemeLayout::AREA_CONTENT]['widgets'] = [];
        }

        $layout[ThemeLayout::AREA_CONTENT]['widgets'] = array_merge(
            $layout[ThemeLayout::AREA_CONTENT]['widgets'],
            $moved
        );

        usort(
            $layout[ThemeLayout::AREA_CONTENT]['widgets'],
            static fn(array $a, array $b): int => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0)
        );

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function reassignRelatedProductsModuleInLayout(array $layout): array
    {
        return $this->reassignProductOwnedWidgetInLayout(
            $layout,
            self::WIDGET_CODE_RELATED_PRODUCTS,
            self::SLOT_PRODUCT_RELATED_PRODUCTS
        );
    }

    private function reassignCrossSellModuleInLayout(array $layout): array
    {
        return $this->reassignProductOwnedWidgetInLayout(
            $layout,
            self::WIDGET_CODE_CROSS_SELL,
            self::SLOT_PRODUCT_CROSS_SELL
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function reassignProductOwnedWidgetInLayout(array $layout, string $widgetCode, string $slotId): array
    {
        foreach ([ThemeLayout::AREA_CONTENT, ThemeLayout::AREA_RIGHT_SIDEBAR] as $area) {
            $widgets = $layout[$area]['widgets'] ?? null;
            if (!is_array($widgets) || $widgets === []) {
                continue;
            }
            $changed = false;
            foreach ($widgets as $index => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                if (($widget['widget_code'] ?? '') !== $widgetCode) {
                    continue;
                }
                if (($widget['widget_module'] ?? '') !== self::MODULE_THEME) {
                    continue;
                }
                $widget['widget_module'] = self::MODULE_PRODUCT;
                if (($widget['slot_id'] ?? '') === '') {
                    $widget['slot_id'] = $slotId;
                }
                $widgets[$index] = $widget;
                $changed = true;
            }
            if ($changed) {
                $layout[$area]['widgets'] = $widgets;
            }
        }

        return $layout;
    }

    /**
     * @param array|string $config
     */
    private function normalizeBestsellersConfig(array|string $config): array
    {
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        if (!is_array($config)) {
            $config = [];
        }

        $config['columns'] = $config['columns'] ?? '4';
        $config['layout'] = $config['layout'] ?? 'carousel';
        $config['limit'] = $config['limit'] ?? 4;

        return $config;
    }
}
