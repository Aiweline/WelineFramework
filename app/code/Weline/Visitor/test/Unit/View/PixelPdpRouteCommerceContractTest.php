<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * PDP must auto-track view_item via route-scoped commerce events after page_view;
 * bootstrap CTA clicks before pixel.js ready must enqueue declared tracks (add_to_cart).
 */
final class PixelPdpRouteCommerceContractTest extends TestCase
{
    public function testPixelRuntimeAutoTracksProductPathViewItem(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');

        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString('function __trackRouteScopedCommerceEvents', $src);
            self::assertStringContainsString("path.indexOf('/product/') === 0", $src);
            self::assertStringContainsString("return 'view_item'", $src);
            self::assertStringContainsString('__trackRouteScopedCommerceEvents(', $src);
            self::assertStringContainsString('__WelinePixelRouteCommerceDomWait', $src);
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.23-r2d-param2'", $src);
        }
    }

    public function testBootstrapQueuesDeclaredCtaBeforePixelReady(): void
    {
        $root = \dirname(__DIR__, 3);
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
        $bodyEnd = (string) \file_get_contents(
            $root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );

        foreach ([$bootstrap, $bodyEnd] as $src) {
            self::assertStringContainsString('enqueuePendingDeclaredTrack', $src);
            self::assertStringContainsString('__WelinePixelPending', $src);
            self::assertStringContainsString('bootstrap_queued', $src);
            self::assertStringContainsString("loadWelinePixel('cta-click')", $src);
        }
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260923-r2d-param2'", $bootstrap);
    }

    public function testPdpTemplateHasExactViewItemMarker(): void
    {
        $tpl = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Product/view/templates/frontend/widgets/product-info.phtml'
        );
        self::assertStringContainsString('weline-pixel::view_item', $tpl);
        self::assertStringContainsString('data-pixel-event="view_item"', $tpl);
        self::assertStringContainsString('data-testid="pixel-view-item-marker"', $tpl);
    }

    public function testStorefrontPixelBootstrapInjectsProductLayout(): void
    {
        /** @var array<string, mixed> $widgets */
        $widgets = require \dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $injections = $widgets['storefront-pixel-bootstrap']['default_injections'] ?? [];
        $types = [];
        foreach ($injections as $row) {
            if (\is_array($row) && !empty($row['layout_type'])) {
                $types[] = (string) $row['layout_type'];
            }
        }
        self::assertContains('homepage', $types);
        self::assertContains('product', $types);
        self::assertContains('checkout', $types);
    }
}
