<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 航线列表：承运商 / 费用模板 / 发货锚点须显示名称，禁止裸数字 ID。
 */
final class ShippingServiceLaneListLabelsContractTest extends TestCase
{
    private function template(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/ShippingService/index.phtml'
        );
    }

    public function testLaneTableMapsIdsToReadableNames(): void
    {
        $tpl = $this->template();
        self::assertStringContainsString('data-testid="shipping-service-lane-table"', $tpl);
        self::assertStringContainsString('carrier_names_by_id', $tpl);
        self::assertStringContainsString('template_names_by_id', $tpl);
        self::assertStringContainsString('origin_names_by_id', $tpl);
        self::assertStringContainsString('data-testid="lane-carrier-label"', $tpl);
        self::assertStringContainsString('data-testid="lane-template-label"', $tpl);
        self::assertStringContainsString('data-testid="lane-origin-label"', $tpl);
        self::assertStringContainsString('schema_fields_CARRIER_NAME', $tpl);
        self::assertStringContainsString('schema_fields_TEMPLATE_NAME', $tpl);
        self::assertStringContainsString('ShippingAddress::schema_fields_NAME', $tpl);

        self::assertDoesNotMatchRegularExpression(
            '/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_CARRIER_ID\)\s*\?>\s*<\/td>/',
            $tpl,
        );
        self::assertDoesNotMatchRegularExpression(
            '/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_RATE_TEMPLATE_ID\)/',
            $tpl,
        );
        self::assertDoesNotMatchRegularExpression(
            '/<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID\)/',
            $tpl,
        );
    }
}
