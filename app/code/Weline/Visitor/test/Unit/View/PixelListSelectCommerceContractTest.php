<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** msg-37: view_item_list / select_item / hero CTA link_* / remove line meta. */
final class PixelListSelectCommerceContractTest extends TestCase
{
    public function testPixelJsEmitsListSelectAndCtaLinkParams(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.09.23-list-select1'", $src);
            self::assertStringContainsString('function __getProductListMeta', $src);
            self::assertStringContainsString('function __getCtaLinkMeta', $src);
            self::assertStringContainsString('function __getRemoveFromCartMeta', $src);
            self::assertStringContainsString("return 'view_item_list'", $src);
            self::assertStringContainsString("track('select_item'", $src);
            self::assertStringContainsString("track('view_item_list'", $src);
            self::assertStringContainsString("return ['link_url', 'link_text']", $src);
        }
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260923-list-select1'", $bootstrap);
    }
}
