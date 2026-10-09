<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Taglib @backend-url{|['id'=>$x]} cannot bind local PHP vars at bake time → id=0 → panel AJAX 403.
 * Query must be appended with <?= (int)$orderId ?> (same pattern as cancel/view links).
 */
final class BackendOrderEditOpsPanelSrcContractTest extends TestCase
{
    public function testOpsPanelSrcAppendsOrderIdQueryViaPhpEcho(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("data-order-ops-async=\"1\"", $src);
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=shipment&amp;isAjax=1",
            $src
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=refund&amp;isAjax=1",
            $src
        );
        self::assertStringContainsString(
            "@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=comms&amp;isAjax=1",
            $src
        );
        self::assertStringNotContainsString(
            "['id' => \$orderId, 'tab' => 'shipment'",
            $src
        );
        self::assertStringNotContainsString(
            "['id' => \$orderData[Order::schema_fields_ID]",
            $src
        );
    }
}
