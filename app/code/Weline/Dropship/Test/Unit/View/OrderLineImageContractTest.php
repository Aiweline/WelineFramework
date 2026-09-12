<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Contract: 履约订单商品行须带回 image_url 并在模板中预留缩略图样式。 */
final class OrderLineImageContractTest extends TestCase
{
    public function testOrderLinesExposeImageUrlFromListingThumb(): void
    {
        $root = dirname(__DIR__, 3);
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Order.php');
        $js = (string)file_get_contents($root . '/view/statics/backend/order-accordion.js');
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/Order/index.phtml');
        $mod = (string)file_get_contents($root . '/view/statics/backend/weline.modules.js');

        self::assertStringContainsString('enrichLinesWithImages', $ctrl);
        self::assertStringContainsString('DropshipListing', $ctrl);
        self::assertStringContainsString("'image_url'", $ctrl);
        self::assertStringContainsString('schema_fields_THUMB_URL', $ctrl);

        self::assertStringContainsString('ds-order-line__thumb', $js);
        self::assertStringContainsString('ds-order-line__thumb-ph', $js);
        self::assertStringContainsString('dropship-order-line-thumb', $js);
        self::assertStringContainsString('renderLines: renderLines', $js);

        self::assertStringContainsString('ds-order-line__thumb', $tpl);
        self::assertStringContainsString('ds-order-line__product', $tpl);
        self::assertStringContainsString('--weline-space-2', $tpl);

        self::assertStringContainsString('order-accordion.js?v=1.0.69', $mod);
        self::assertStringContainsString('order-accordion.js?v=1.0.69', $tpl);
    }
}
