<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Integration;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemeLayoutService;

final class ThemeLayoutServiceSortOrderTest extends TestCore
{
    private const THEME_ID = 987654;
    private const PAGE_TYPE = 'codex_sort_order_contract';

    protected function tearDown(): void
    {
        $this->cleanupLayouts();
        parent::tearDown();
    }

    public function testGetDraftLayoutOrdersWidgetsBySortOrderAscending(): void
    {
        $this->cleanupLayouts();
        $this->insertLayout('basic/card', 20);
        $this->insertLayout('basic/button', 10);

        /** @var ThemeLayoutService $service */
        $service = ObjectManager::getInstance(ThemeLayoutService::class);
        $layout = $service->getDraftLayout(self::THEME_ID, self::PAGE_TYPE);

        $this->assertSame(
            ['basic/button', 'basic/card'],
            array_column($layout['content']['widgets'] ?? [], 'widget_code')
        );
    }

    private function insertLayout(string $widgetCode, int $sortOrder): void
    {
        /** @var ThemeLayout $layout */
        $layout = clone ObjectManager::getInstance(ThemeLayout::class);
        $layout->clearData()->clearQuery();
        $layout->setData([
            ThemeLayout::schema_fields_THEME_ID => self::THEME_ID,
            ThemeLayout::schema_fields_PAGE_TYPE => self::PAGE_TYPE,
            ThemeLayout::schema_fields_AREA => ThemeLayout::AREA_CONTENT,
            ThemeLayout::schema_fields_SLOT_ID => ThemeLayout::AREA_CONTENT,
            ThemeLayout::schema_fields_WIDGET_CODE => $widgetCode,
            ThemeLayout::schema_fields_WIDGET_MODULE => 'Weline_Theme',
            ThemeLayout::schema_fields_WIDGET_TYPE => 'theme_component',
            ThemeLayout::schema_fields_CONFIG => '[]',
            ThemeLayout::schema_fields_SORT_ORDER => $sortOrder,
            ThemeLayout::schema_fields_IS_ACTIVE => 1,
            ThemeLayout::schema_fields_STATUS => ThemeLayout::STATUS_DRAFT,
        ])->save();
    }

    private function cleanupLayouts(): void
    {
        /** @var ThemeLayout $layout */
        $layout = ObjectManager::getInstance(ThemeLayout::class);
        $layout->reset()
            ->where(ThemeLayout::schema_fields_THEME_ID, self::THEME_ID)
            ->where(ThemeLayout::schema_fields_PAGE_TYPE, self::PAGE_TYPE)
            ->delete()
            ->fetch();
    }
}
