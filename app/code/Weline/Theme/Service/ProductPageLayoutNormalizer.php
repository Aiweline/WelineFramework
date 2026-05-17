<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;

/**
 * 商品详情页布局校正：热销产品不应占用右侧栏（4 列网格在窄侧栏会撑破页面）。
 */
final class ProductPageLayoutNormalizer
{
    private const WIDGET_CODE_BESTSELLERS = 'bestsellers';
    private const SLOT_PRODUCT_RELATED = 'product-related';

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

    /**
     * 渲染前将仍配置在右侧栏的热销产品挪到全宽推荐区（与 DB 种子/迁移目标一致）。
     */
    public function normalizeLayoutForRender(string $pageType, array $layout): array
    {
        if ($pageType !== ThemeLayout::PAGE_TYPE_PRODUCT) {
            return $layout;
        }

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
