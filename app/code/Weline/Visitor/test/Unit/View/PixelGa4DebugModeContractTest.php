<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * GA4 debug_mode must be omitted when off, re-applied after gtag.js load when on,
 * and use event-level debug_mode=true (ep.debug_mode) + beacon for keepalive navigations.
 */
final class PixelGa4DebugModeContractTest extends TestCase
{
    public function testGa4DebugModeHardeningPresentInPixelRuntime(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
        $tracking = (string) \file_get_contents(
            $root . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml'
        );

        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString('var ga4ConfigParams = { send_page_view: true };', $src);
            self::assertStringContainsString('ga4ConfigParams.debug_mode = true;', $src);
            self::assertStringContainsString("send_page_view: false,\n                                debug_mode: true", $src);
            self::assertStringContainsString('params.debug_mode = true;', $src);
            self::assertStringNotContainsString('params.debug_mode = 1;', $src);
            self::assertStringContainsString("params.transport_type = 'beacon';", $src);
            self::assertStringContainsString('params.send_to = runtime.measurementId;', $src);
            self::assertStringContainsString('保留 __keepalive 到 Forwarders', $src);
            self::assertStringNotContainsString('debug_mode: runtime.debugMode', $src);
        }

        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.22-param-shell1'", $pixel);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260922-param-shell1'", $bootstrap);
        self::assertStringContainsString('内部流量', $tracking);
        self::assertStringContainsString('DebugView', $tracking);
    }
}
