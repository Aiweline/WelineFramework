<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceDevPreview;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMaintenanceControllerContractsPass().
 */
final class MaintenanceControllerContractsTest extends TestCase
{
    public function testAllMaintenanceControllerContractsPass(): void
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
            'MaintenanceDevPreview::testDevPreviewMarkerIsStable' => function () {
            $this->assert_MaintenanceDevPreview_testDevPreviewMarkerIsStable();
        },
            'MaintenanceDevPreview::testDevPreviewControllerGatesProduction' => function () {
            $this->assert_MaintenanceDevPreview_testDevPreviewControllerGatesProduction();
        },
            'MaintenanceDevPreview::testMaintenanceInterceptorWhitelistsDevPreviewInDev' => function () {
            $this->assert_MaintenanceDevPreview_testMaintenanceInterceptorWhitelistsDevPreviewInDev();
        },
            'MaintenanceDevPreview::testMaintenanceTemplateUsesDynamicLanguageUrlsForDevPreview' => function () {
            $this->assert_MaintenanceDevPreview_testMaintenanceTemplateUsesDynamicLanguageUrlsForDevPreview();
        },
            'MaintenanceDevPreview::testStandaloneTemplateHomeLinksAreRootRelative' => function () {
            $this->assert_MaintenanceDevPreview_testStandaloneTemplateHomeLinksAreRootRelative();
        },
        ];
    }

private function assert_MaintenanceDevPreview_testDevPreviewMarkerIsStable(): void
{
    self::assertSame('/maintenance/frontend/dev-preview', MaintenanceDevPreview::URI_MARKER);
}

private function assert_MaintenanceDevPreview_testDevPreviewControllerGatesProduction(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Controller/Frontend/DevPreview.php');
    self::assertStringContainsString('MaintenanceDevPreview::isAvailable()', $source);
    self::assertStringContainsString('ResponseTerminateException', $source);
    self::assertStringContainsString('404', $source);
    self::assertStringContainsString('renderDevPreviewHtml', $source);
}

private function assert_MaintenanceDevPreview_testMaintenanceInterceptorWhitelistsDevPreviewInDev(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString('MaintenanceDevPreview::matchesRequestUri', $source);
}

private function assert_MaintenanceDevPreview_testMaintenanceTemplateUsesDynamicLanguageUrlsForDevPreview(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    $generator = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Service/MaintenanceStaticGenerator.php');
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

private function assert_MaintenanceDevPreview_testStandaloneTemplateHomeLinksAreRootRelative(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    // PHP include path — Taglib @url is never compiled on this surface.
    self::assertStringNotContainsString("@url{'/'}", $template);
    self::assertStringNotContainsString('@url{', $template);
    self::assertStringContainsString('class="maintenance-brand" href="/"', $template);
    self::assertStringContainsString('href="/" class="back-btn"', $template);
}
}
