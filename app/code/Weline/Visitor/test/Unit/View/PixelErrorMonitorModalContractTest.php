<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use Weline\Framework\Test\TestCore;

class PixelErrorMonitorModalContractTest extends TestCore
{
    public function testIndexUsesDialogInsteadOfDetailPageLink(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/PixelErrorMonitor/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('pixel-error-detail-dialog', $src);
        self::assertStringContainsString('data-w-component="dialog"', $src);
        self::assertStringContainsString('data-pixel-error-open', $src);
        self::assertStringContainsString('format=json', $src);
        self::assertStringContainsString('requestJson', $src);
        self::assertStringContainsString('weline-pixel-error-glance', $src);
        self::assertStringContainsString('error_type_label', $src);
        self::assertStringContainsString('<details', $src);
        self::assertStringContainsString('glanceCell', $src);
        self::assertStringNotContainsString('adminRequest', $src);
        self::assertStringNotContainsString('pixel-error-monitor/detail?id=', $src);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/view/templates/Backend/PixelErrorMonitor/detail.phtml');
    }

    public function testControllerServesJsonDetailAndRedirectsHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/PixelErrorMonitor.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('wantsJson()', $src);
        self::assertStringContainsString("fetchJson([", $src);
        self::assertStringContainsString("'open' => \$id", $src);
        self::assertStringContainsString('buildIncidentPayload', $src);
        self::assertStringContainsString('error_type_label', $src);
        self::assertStringContainsString('disposition_label', $src);
        self::assertStringContainsString('typeLabel', $src);
        self::assertStringContainsString('dispositionLabel', $src);
    }
}
