<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

final class DeliveryAddressPurposeFlagsContractTest extends TestCase
{
    public function testModelDeclaresPurposeColumns(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/DeliveryAddress.php');
        self::assertStringContainsString("schema_fields_PURPOSE_CHECKOUT = 'purpose_checkout'", $src);
        self::assertStringContainsString("schema_fields_PURPOSE_RECEIVING = 'purpose_receiving'", $src);
    }

    public function testUpgradeMigratesPurposeFlags(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('migrateDeliveryAddressPurposeFlags', $src);
        self::assertStringContainsString('DeliveryAddress::class', $src);
    }

    public function testCheckoutCreateDualByDefaultCheckbox(): void
    {
        $flags = DeliveryAddressService::resolvePurposeWriteFlags([
            'purpose_source' => 'checkout',
            'also_use_receiving' => 1,
        ], true, null);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
    }

    public function testCheckoutCreateCheckoutOnlyWhenUnchecked(): void
    {
        $flags = DeliveryAddressService::resolvePurposeWriteFlags([
            'purpose_source' => 'checkout',
            'also_use_receiving' => 0,
        ], true, null);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
        self::assertSame(0, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
    }

    public function testUpdateDoesNotUntagReceivingWhenUnchecked(): void
    {
        $existing = $this->createMock(DeliveryAddress::class);
        $existing->method('hasPurposeCheckout')->willReturn(true);
        $existing->method('hasPurposeReceiving')->willReturn(true);

        $flags = DeliveryAddressService::resolvePurposeWriteFlags([
            'purpose_source' => 'checkout',
            'also_use_receiving' => 0,
        ], false, $existing);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
    }

    public function testReceivingCreateDualWhenAlsoCheckoutChecked(): void
    {
        $flags = DeliveryAddressService::resolvePurposeWriteFlags([
            'purpose_source' => 'receiving',
            'also_use_checkout' => 1,
        ], true, null);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
    }

    public function testReceivingCreateReceivingOnlyWhenUnchecked(): void
    {
        $flags = DeliveryAddressService::resolvePurposeWriteFlags([
            'purpose_source' => 'receiving',
            'also_use_checkout' => 0,
        ], true, null);
        self::assertSame(0, $flags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
        self::assertSame(1, $flags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
    }

    public function testCheckoutWidgetExposesAlsoUseReceivingCheckbox(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml'
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/checkout-shipping-address.js'
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('data-also-use-receiving', $tpl);
        self::assertStringContainsString('同时用作收货地址', $tpl);
        self::assertStringContainsString("address_purpose: 'checkout'", $js);
        self::assertStringContainsString('purpose_source: \'checkout\'', $js);
        self::assertStringContainsString('20260916-purpose-tags2', $modules);
    }

    public function testAccountDeliveryFormExposesAlsoUseCheckoutCheckbox(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/account.sidebar.content.phtml'
        );
        self::assertStringContainsString('purpose_receiving', $tpl);
        self::assertStringContainsString('data-also-use-checkout', $tpl);
        self::assertStringContainsString('同时用作结账地址', $tpl);
        self::assertStringContainsString("purpose_source\" value=\"receiving\"", $tpl);
        // 收货新建默认不打结账标（禁止 checked）。
        self::assertDoesNotMatchRegularExpression(
            '/name="also_use_checkout"[^>]*\bchecked\b/',
            $tpl
        );
    }
}
