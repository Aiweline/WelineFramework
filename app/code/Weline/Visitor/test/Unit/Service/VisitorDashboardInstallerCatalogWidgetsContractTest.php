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
        $src = (string)\file_get_contents($root . '/Service/VisitorDashboardPageInstaller.php');

        foreach ([
            'pixel_overview',
            'pixel_event_trend',
            'pixel_realtime',
            'pixel_top_events',
            'pixel_engagement',
            'pixel_pages',
        ] as $legacy) {
            self::assertStringContainsString("'{$legacy}'", $src, "legacy {$legacy}");
        }

        foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
            self::assertStringContainsString("'{$code}'", $src, "catalog {$code}");
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
        /** @var array<string, array<string, mixed>> $widgets */
        $widgets = require $root . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';

        foreach (VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES as $code) {
            self::assertArrayHasKey($code, $widgets);
            $injection = $widgets[$code]['default_injections'][0] ?? [];
            self::assertSame('weline_visitor_event_statistics', $injection['default_view'] ?? null);
            self::assertFalse((bool)($injection['required'] ?? true), $code . ' must stay optional');
        }
    }
}
