<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;

final class PromotionLayoutResolveSampleContractTest extends TestCase
{
    public function testPromotionObserversClaimShellAndSampleRoutes(): void
    {
        $resolve = dirname(__DIR__, 3) . '/Observer/LayoutResolveObserver.php';
        $sample = dirname(__DIR__, 3) . '/Observer/LayoutPreviewSampleObserver.php';
        $events = dirname(__DIR__, 3) . '/etc/event.xml';

        self::assertFileExists($resolve);
        self::assertFileExists($sample);
        self::assertFileExists($events);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/Observer/LayoutCanvasBodyObserver.php');

        $resolveSource = (string)file_get_contents($resolve);
        $sampleSource = (string)file_get_contents($sample);
        $eventsSource = (string)file_get_contents($events);

        self::assertStringContainsString("layout_path', 'promotion'", $resolveSource);
        self::assertStringContainsString('entity_slug', $resolveSource);
        self::assertStringContainsString('promotion/', $sampleSource);
        self::assertStringContainsString('preview_entity_route', $sampleSource);
        self::assertStringContainsString('Weline_Theme::layout_resolve', $eventsSource);
        self::assertStringContainsString('Weline_Theme::layout_preview_sample', $eventsSource);
        self::assertStringNotContainsString('Weline_Theme::layout_canvas_body', $eventsSource);
        self::assertStringNotContainsString('layout_path\', \'deals\'', $resolveSource);
    }

    public function testPageTypeDropdownHasPromotionShellNotDealsLayout(): void
    {
        $themeLayout = dirname(__DIR__, 4) . '/Theme/Model/ThemeLayout.php';
        self::assertFileExists($themeLayout);
        $source = (string)file_get_contents($themeLayout);
        self::assertStringContainsString("PAGE_TYPE_PROMOTION = 'promotion'", $source);
        self::assertStringNotContainsString("PAGE_TYPE_DEALS", $source);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts/deals/default.phtml'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts/promotion/default.phtml'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/promotion/default.phtml'
        );
    }
}
