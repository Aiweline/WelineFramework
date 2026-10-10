<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelReportCatalog;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

/**
 * E05：Installer 增量合并 catalog 六个部件（不查库；不整页 replace）。
 */
final class VisitorDashboardInstallerCatalogWidgetsContractTest extends TestCase
{
    public function testInstallerKeepsReplaceLayoutFalse(): void
    {
        $root = dirname(__DIR__, 3);
        $src = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');
        self::assertStringContainsString("'replace_layout' => false", $src);
        self::assertStringNotContainsString("'replace_layout' => true", $src);
    }

    public function testInstallerSeedContainsLegacyAndCatalogWidgets(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetPhp = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        foreach (\Weline\Visitor\Service\VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
            $tpl = null;
            foreach (\Weline\Widget\Test\Support\SlimWidgetPhpListing::templatePaths($widgetPhp) as $path) {
                $view = \Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($path);
                if (!is_file($view)) {
                    continue;
                }
                $src = (string) file_get_contents($view);
                if (str_contains($src, '@widget.code {' . $code . '}')) {
                    $tpl = $path;
                    self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src, $code);
                    self::assertStringContainsString('"required":false', $src, $code);
                    break;
                }
            }
            self::assertNotNull($tpl, $code . ' missing from slim listing/templates');
        }
    }

    public function testCatalogCodesMatchReportCatalogEnabled(): void
    {
        $enabled = (new PixelReportCatalog())->codes(true);
        self::assertSame(
            VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES,
            $enabled
        );
    }

    public function testWidgetPhpInjectionsRemainOptional(): void
    {
        $root = dirname(__DIR__, 3);
        $widgetPhp = $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
            $matched = false;
            foreach (\Weline\Widget\Test\Support\SlimWidgetPhpListing::templatePaths($widgetPhp) as $path) {
                $view = \Weline\Widget\Test\Support\SlimWidgetPhpListing::resolveViewPath($path);
                if (!is_file($view)) {
                    continue;
                }
                $src = (string) file_get_contents($view);
                if (!str_contains($src, '@widget.code {' . $code . '}')) {
                    continue;
                }
                $matched = true;
                self::assertStringContainsString('"default_view":"weline_visitor_event_statistics"', $src, $code);
                self::assertStringContainsString('"required":false', $src, $code . ' must stay optional');
                break;
            }
            self::assertTrue($matched, $code . ' missing from slim listing/templates');
        }
    }
}
