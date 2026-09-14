<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderShippingWidgetsContractTest extends TestCase
{
    public function testWidgetRegistrationPinsOrderShipmentSlots(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;

        self::assertArrayHasKey('backend-order-shipments', $widgets);
        $detail = $widgets['backend-order-shipments'];
        self::assertSame('backend', $detail['area'] ?? null);
        self::assertSame('backend-order-shipments', $detail['slot'] ?? null);
        self::assertSame(
            'Weline_Shipping::templates/Backend/widgets/backend-order-shipments.phtml',
            $detail['template'] ?? null,
        );
        $detailInjection = $detail['default_injections'][0] ?? [];
        self::assertSame('backend-order-shipments', $detailInjection['slot'] ?? null);
        self::assertSame('backend-order-view', $detailInjection['layout_type'] ?? null);
        self::assertTrue((bool)($detailInjection['required'] ?? false));

        self::assertArrayHasKey('backend-order-list-shipping', $widgets);
        $list = $widgets['backend-order-list-shipping'];
        self::assertSame('backend-order-list-shipping', $list['slot'] ?? null);
        $listInjection = $list['default_injections'][0] ?? [];
        self::assertSame('backend-order-list', $listInjection['layout_type'] ?? null);
        self::assertTrue((bool)($listInjection['required'] ?? false));
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
        self::assertStringContainsString('BackendOrderShipmentsService', $list);
        self::assertStringContainsString('data-testid="backend-order-list-shipping"', $list);
        self::assertStringContainsString('order/backend/shipment/index', $list);
    }
}
