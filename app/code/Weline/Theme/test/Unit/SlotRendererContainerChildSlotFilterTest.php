<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 发布态 filterWidgetsForHtmlSlots 必须保留容器子槽，否则预览有内容、发布后嵌套槽空白。
 */
final class SlotRendererContainerChildSlotFilterTest extends TestCase
{
    private function readService(): string
    {
        $path = dirname(__DIR__, 2) . '/Service/SlotRendererService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }

    public function testPublishedFilterExpandsContainerChildSlotsBeforeIntersect(): void
    {
        $src = $this->readService();

        self::assertStringContainsString('function filterWidgetsForHtmlSlots(', $src);
        self::assertStringContainsString('function expandSlotIdsWithContainerChildSlots(', $src);
        self::assertStringContainsString('extractSlotIdsFromHtml($html)', $src);
        self::assertStringContainsString('expandSlotIdsWithContainerChildSlots($slotWidgets, $slotIds)', $src);
        self::assertStringContainsString('STATUS_PUBLISHED', $src);
        self::assertStringContainsString('filterWidgetsForHtmlSlots', $src);
    }

    public function testFooterContainerExtensionSlotsRetainedEvenWithoutDefinitionSlots(): void
    {
        $src = $this->readService();

        self::assertStringContainsString("\$widgetCode === 'footer-container'", $src);
        self::assertStringContainsString('FooterDefaultLinksHelper::standardExtensionSlotIds()', $src);
        self::assertStringContainsString('发布态 filter 仍须保留这些子槽', $src);
    }
}
