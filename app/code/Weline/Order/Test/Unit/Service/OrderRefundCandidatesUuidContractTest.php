<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OrderRefundCandidatesUuidContractTest extends TestCase
{
    public function testRefundCandidatesQueryItemsByOrderUuidFirst(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrderTradeAdminCommandService.php'
        );
        self::assertStringContainsString('schema_fields_ORDER_UUID, $orderUuid', $src);
        self::assertStringContainsString('schema_fields_ORDER_ID, $rowOrderId', $src);
    }

    public function testOrmStoreReloadsOrderIdWhenSaveDoesNotFillPk(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrmOrderFacadeStore.php'
        );
        self::assertStringContainsString('order_persist_missing_id', $src);
        self::assertStringContainsString("schema_fields_ORDER_UUID, (string)\$row['order_uuid']", $src);
        self::assertStringContainsString("'payment_method'", $src);
        self::assertStringContainsString("'payment_status'", $src);
    }

    public function testRefundCasesExposePaypalChannelHistory(): void
    {
        $svc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrderTradeAdminCommandService.php'
        );
        self::assertStringContainsString('provider_refund_id', $svc);
        self::assertStringContainsString('schema_fields_METHOD_CODE', $svc);
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/panel/refund.phtml'
        );
        self::assertStringContainsString('order-edit-refund-history', $tpl);
        self::assertStringContainsString('provider_refund_id', $tpl);
        self::assertStringContainsString('payment_method', $tpl);
    }

    public function testRefundCandidatesSubtractOccupiedQtyAndFilterByOrderId(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrderTradeAdminCommandService.php'
        );
        self::assertStringContainsString('occupiedRefundQtyByItem', $src);
        self::assertStringContainsString('function refundCandidates(int $limit = 50, int $orderId = 0)', $src);
        self::assertStringContainsString('function refundCases(int $limit = 50, int $orderId = 0)', $src);
        $coord = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrderRefundCoordinator.php'
        );
        self::assertStringContainsString('syncPersistedRefundProgress', $coord);
        self::assertStringContainsString('appendRefundHistory', $coord);
        self::assertStringContainsString('渠道退款号', $coord);
    }
}
