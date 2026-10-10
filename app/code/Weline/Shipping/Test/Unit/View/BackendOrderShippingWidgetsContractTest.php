<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderShippingWidgetsContractTest extends TestCase
{
    public function testWidgetRegistrationPinsOrderShipmentSlots(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $shipmentsTpl = 'Weline_Shipping::templates/Backend/widgets/backend-order-shipments.phtml';
        $listTpl = 'Weline_Shipping::templates/Backend/widgets/backend-order-list-shipping.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $shipmentsTpl));
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $listTpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/widgets/backend-order-shipments.phtml');
        self::assertStringContainsString('@widget.code {backend-order-shipments}', $src);
        self::assertStringContainsString('"required":true', $src);
        $listSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/widgets/backend-order-list-shipping.phtml');
        self::assertStringContainsString('@widget.code {backend-order-list-shipping}', $listSrc);
    }

    public function testHookTemplatesDelegateToShippingWidgets(): void
    {
        $root = dirname(__DIR__, 3);
        $detailHook = (string)file_get_contents(
            $root . '/view/hooks/Weline_Order/backend/order/view/shipments.phtml'
        );
        $listHook = (string)file_get_contents(
            $root . '/view/hooks/Weline_Order/backend/order/list/shipping.phtml'
        );
        self::assertStringContainsString(
            'Weline_Shipping::templates/Backend/widgets/backend-order-shipments.phtml',
            $detailHook
        );
        self::assertStringContainsString(
            'Weline_Shipping::templates/Backend/widgets/backend-order-list-shipping.phtml',
            $listHook
        );
    }

    public function testWidgetTemplatesUseShipmentsService(): void
    {
        $root = dirname(__DIR__, 3);
        $detail = (string)file_get_contents(
            $root . '/view/templates/Backend/widgets/backend-order-shipments.phtml'
        );
        $list = (string)file_get_contents(
            $root . '/view/templates/Backend/widgets/backend-order-list-shipping.phtml'
        );
        self::assertStringContainsString('BackendOrderShipmentsService', $detail);
        self::assertStringContainsString('@widget.default_injections', $detail);
        self::assertStringContainsString('data-testid="backend-order-shipments"', $detail);
        self::assertStringContainsString('data-testid="backend-order-shipments-count"', $detail);
        self::assertStringContainsString('本单发货记录', $detail);
        self::assertStringContainsString('backend-order-shipment-contents', $detail);
        self::assertStringContainsString('包裹内商品', $detail);
        self::assertStringContainsString('本子订单暂无发货记录', $detail);
        self::assertStringContainsString('同单多笔发货，非结账组其他子单', $detail);
        self::assertStringContainsString('物流进程记录', $detail);
        self::assertStringContainsString('OrderShipmentTrackingQueryService', $detail);
        self::assertStringContainsString('data-testid="backend-order-shipment-progress"', $detail);
        self::assertStringContainsString('showOpsLink', $detail);
        self::assertStringNotContainsString('查看当前位置', $detail);
        self::assertStringNotContainsString('shippingOrderShipmentTracking', $detail);
        self::assertStringContainsString('BackendOrderShipmentsService', $list);
        self::assertStringContainsString('data-testid="backend-order-list-shipping"', $list);
        self::assertStringContainsString('order/backend/shipment/index', $list);
    }
}
