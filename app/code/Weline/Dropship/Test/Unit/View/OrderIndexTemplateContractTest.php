<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Contract: 履约订单页中文列、状态色、物流与推送队列。 */
final class OrderIndexTemplateContractTest extends TestCase
{
    public function testOrderIndexIsHumanReadable(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/Order/index.phtml');
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Order.php');
        $mod = (string)file_get_contents($root . '/view/statics/backend/weline.modules.js');

        self::assertStringContainsString('data-testid="dropship-orders"', $tpl);
        self::assertStringContainsString('w-card', $tpl);
        self::assertStringContainsString('货源', $tpl);
        self::assertStringContainsString('本站订单', $tpl);
        self::assertStringContainsString('货源单号', $tpl);
        self::assertStringContainsString('物流', $tpl);
        self::assertStringContainsString('更新时间', $tpl);
        self::assertStringContainsString('推送队列', $tpl);
        self::assertStringContainsString('data-testid="dropship-fulfillment-status"', $tpl);
        self::assertStringContainsString('data-tone=', $tpl);
        self::assertStringNotContainsString('<th>provider</th>', $tpl);
        self::assertStringNotContainsString('<th>order</th>', $tpl);
        self::assertStringNotContainsString('>Outbox<', $tpl);

        self::assertStringContainsString('presentFulfillments', $ctrl);
        self::assertStringContainsString('status_label', $ctrl);
        self::assertStringContainsString('OrderFacadeInterface', $ctrl);
        self::assertStringContainsString('已送达', $ctrl);
        self::assertStringContainsString('待推送', $ctrl);

        self::assertStringContainsString('data-dropship-order-accordion="1"', $tpl);
        self::assertStringContainsString('data-weline-load="dropshipOrderAccordion"', $tpl);
        self::assertStringContainsString('data-order-expandable', $tpl);
        self::assertStringContainsString('data-testid="dropship-order-detail"', $tpl);
        self::assertStringContainsString('点击展开商品', $tpl);
        self::assertStringContainsString('getLines', $ctrl);
        self::assertStringContainsString('buildOrderLinesPayload', $ctrl);
        self::assertStringContainsString('dropshipOrderAccordion', $mod);
        self::assertStringContainsString('order-accordion.js?v=1.0.69', $mod);
        self::assertStringContainsString('ds-order-line__thumb', $tpl);
        self::assertStringContainsString('enrichLinesWithImages', $ctrl);
        self::assertStringContainsString('ensureOrderAccordion', $tpl);
        self::assertStringContainsString('data-dropship-order-accordion-src', $tpl);
        self::assertFileExists($root . '/view/statics/backend/order-accordion.js');
    }
}
