<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Product layout option / schedule / editor product-mode contracts (greenfield, no virtual content tables).
 */
final class ProductLayoutFeatureContractTest extends TestCase
{
    public function testOptionServiceClonesFileShellWithoutVirtualTables(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ProductLayoutOptionService.php',
        );
        self::assertStringContainsString('class ProductLayoutOptionService', $source);
        self::assertStringContainsString('function createOption', $source);
        self::assertStringContainsString("'/layouts/' . self::LAYOUT_TYPE", $source);
        self::assertStringContainsString('initDraftFromPublished', $source);
        self::assertStringContainsString('no theme_virtual_layout table', $source);
        self::assertStringNotContainsString('theme_virtual_layout_content', $source);
        self::assertStringNotContainsString('INSERT INTO theme_virtual', $source);
    }

    public function testResolvePriorityIsScheduleThenProductThenCategoryThenDefault(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ProductLayoutResolveService.php',
        );
        $schedulePos = \strpos($source, 'schedule:product');
        $productPos = \strpos($source, 'selection:product');
        $categoryPos = \strpos($source, 'selection:category_product_default');
        $defaultPos = \strpos($source, 'file:default');
        self::assertNotFalse($schedulePos);
        self::assertNotFalse($productPos);
        self::assertNotFalse($categoryPos);
        self::assertNotFalse($defaultPos);
        self::assertLessThan($productPos, $schedulePos);
        self::assertLessThan($categoryPos, $productPos);
        self::assertLessThan($defaultPos, $categoryPos);
    }

    public function testScheduleNeverRewritesPermanentSelectionOnResolve(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ProductLayoutScheduleService.php',
        );
        self::assertStringContainsString('function resolveActive', $source);
        self::assertStringContainsString('function processBoundariesNear', $source);
        self::assertStringNotContainsString('saveLayoutSelection', $source);
        self::assertStringContainsString('bustForScheduleTarget', $source);
    }

    public function testCacheBustExpandsCategoryMembers(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ProductLayoutCacheBustService.php',
        );
        self::assertStringContainsString('TARGET_CATEGORY_PRODUCT_DEFAULT', $source);
        self::assertStringContainsString('listByCategoryIds', $source);
        self::assertStringContainsString('fingerprintKey', $source);
        self::assertStringContainsString('bustIfScheduleMembershipChanged', $source);
    }

    public function testThemeEditorProductModeHidesChrome(): void
    {
        $controller = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        self::assertStringContainsString('product_layout_chrome_editing_forbidden', $controller);
        self::assertStringContainsString('isProductLayoutEditorMode', $controller);

        $css = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.css',
        );
        self::assertStringContainsString('theme-editor-hide-chrome', $css);
        self::assertStringContainsString('theme-editor-product-layout', $css);

        $js = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        );
        self::assertStringContainsString('applyProductLayoutChromeHide', $js);
    }

    public function testScheduleModelAndCronExist(): void
    {
        self::assertFileExists(BP . 'app/code/Weline/Theme/Model/ThemeLayoutSchedule.php');
        self::assertFileExists(BP . 'app/code/Weline/Theme/Cron/ProductLayoutScheduleBoundary.php');
        $cron = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Cron/ProductLayoutScheduleBoundary.php',
        );
        self::assertStringContainsString('theme_product_layout_schedule_boundary', $cron);
        self::assertStringContainsString('processBoundariesNear', $cron);
    }
}
