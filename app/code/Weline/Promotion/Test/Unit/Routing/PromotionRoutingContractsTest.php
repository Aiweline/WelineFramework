<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionRoutingContractsPass().
 */
final class PromotionRoutingContractsTest extends TestCase
{
    public function testAllPromotionRoutingContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PromotionLayoutResolveSample::testPromotionClaimsLayoutFromPublicPathWithoutReverseSample' => function () {
            $this->assert_PromotionLayoutResolveSample_testPromotionClaimsLayoutFromPublicPathWithoutReverseSample();
        },
            'PromotionLayoutResolveSample::testPageTypeDropdownHasPromotionShellNotDealsLayout' => function () {
            $this->assert_PromotionLayoutResolveSample_testPageTypeDropdownHasPromotionShellNotDealsLayout();
        },
            'PromotionStorefrontLayout::testPromotionControllerUsesItsDedicatedThemeLayout' => function () {
            $this->assert_PromotionStorefrontLayout_testPromotionControllerUsesItsDedicatedThemeLayout();
        },
        ];
    }

private function assert_PromotionLayoutResolveSample_testPromotionClaimsLayoutFromPublicPathWithoutReverseSample(): void
{
    $resolve = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Observer/LayoutResolveObserver.php';
    $events = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/etc/event.xml';
    self::assertFileExists($resolve);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Observer/LayoutPreviewSampleObserver.php');
    self::assertFileExists($events);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Observer/LayoutCanvasBodyObserver.php');
    $resolveSource = (string) file_get_contents($resolve);
    $eventsSource = (string) file_get_contents($events);
    self::assertStringContainsString("layout_path', 'promotion'", $resolveSource);
    self::assertStringContainsString('entity_slug', $resolveSource);
    self::assertStringContainsString('Weline_Theme::layout_resolve', $eventsSource);
    self::assertStringNotContainsString('layout_preview_sample', $eventsSource);
    self::assertStringNotContainsString('Weline_Theme::layout_canvas_body', $eventsSource);
    self::assertStringNotContainsString('layout_path\', \'deals\'', $resolveSource);
}

private function assert_PromotionLayoutResolveSample_testPageTypeDropdownHasPromotionShellNotDealsLayout(): void
{
    $themeLayout = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Model/ThemeLayout.php';
    self::assertFileExists($themeLayout);
    $source = (string) file_get_contents($themeLayout);
    self::assertStringContainsString("PAGE_TYPE_PROMOTION = 'promotion'", $source);
    self::assertStringNotContainsString("PAGE_TYPE_DEALS", $source);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/layouts/deals/default.phtml');
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/layouts/promotion/default.phtml');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/theme/frontend/layouts/promotion/default.phtml');
}

private function assert_PromotionStorefrontLayout_testPromotionControllerUsesItsDedicatedThemeLayout(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Controller/Index.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("protected ?string \$layoutType = 'promotion.default';", $source);
    self::assertStringNotContainsString("protected ?string \$layoutType = 'default.default';", $source);
    self::assertStringNotContainsString('theme_public_route', $source);
    $layout = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/theme/frontend/layouts/promotion/default.phtml';
    self::assertFileExists($layout);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/layouts/promotion/default.phtml');
    $source = (string) \file_get_contents($layout);
    self::assertStringContainsString('<w:slot id="content"', $source);
    self::assertStringContainsString('<w:slot id="promotion-bottom"', $source);
    self::assertStringContainsString('Weline_Theme::frontend::layouts::promotion::content-after', $source);
}
}
