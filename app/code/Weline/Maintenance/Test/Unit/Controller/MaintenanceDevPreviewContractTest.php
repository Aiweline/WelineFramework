<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceDevPreview;

final class MaintenanceDevPreviewContractTest extends TestCase
{
    public function testDevPreviewMarkerIsStable(): void
    {
        self::assertSame('/maintenance/frontend/dev-preview', MaintenanceDevPreview::URI_MARKER);
    }

    public function testDevPreviewControllerGatesProduction(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/DevPreview.php',
        );

        self::assertStringContainsString('MaintenanceDevPreview::isAvailable()', $source);
        self::assertStringContainsString('ResponseTerminateException', $source);
        self::assertStringContainsString('404', $source);
        self::assertStringContainsString('renderDevPreviewHtml', $source);
    }

    public function testMaintenanceInterceptorWhitelistsDevPreviewInDev(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/MaintenanceInterceptor.php',
        );

        self::assertStringContainsString('MaintenanceDevPreview::matchesRequestUri', $source);
    }

    public function testMaintenanceTemplateUsesDynamicLanguageUrlsForDevPreview(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/maintenance.phtml',
        );
        $generator = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/MaintenanceStaticGenerator.php',
        );

        self::assertStringContainsString('data-lang=', $template);
        self::assertStringContainsString('buildLocalePath', $template);
        self::assertStringContainsString('data-default-lang', $template);
        self::assertStringContainsString('devPreviewMode', $template);
        self::assertStringContainsString('maintenance-dev-preview-banner', $template);
        self::assertStringContainsString('renderDevPreviewHtml', $generator);
        self::assertStringContainsString('$defaultLang = MaintenanceStaticPage::DEFAULT_LANG', $generator);
        self::assertStringNotContainsString('MaintenanceStaticPage::publicHtmlUrl', $template);
        self::assertStringNotContainsString('$maintenanceLanguageUrl', $generator);
    }
}
