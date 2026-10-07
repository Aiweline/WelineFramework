<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\CheckoutTaxAdvisor;
use Weline\Tax\Service\TaxDestinationCheckoutPolicy;

final class CheckoutTaxAdvisorPricesIncludeTaxTest extends TestCase
{
    public function testSameCountrySuppressesSalesTaxWhenPricesIncludeTax(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: true);
        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 73606,
            ]],
        ]];

        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'CN', 'region_code' => ''],
            'USD',
            ['shipping_amount_minor' => 477, 'origin_country' => 'CN'],
        );

        self::assertSame(0, (int)$tax['tax_amount_minor']);
        self::assertSame(0, (int)$tax['sales_tax_amount_minor']);
        self::assertSame(0, (int)$tax['duty_amount_minor']);
        self::assertSame(CheckoutTaxAdvisor::NOTE_PRICES_INCLUDE_TAX, (string)$tax['note']);
    }

    public function testExclusivePricingStillChargesHomeSalesTax(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: false);
        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 73606,
            ]],
        ]];

        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'CN', 'region_code' => ''],
            'USD',
            [],
        );

        self::assertSame(9569, (int)$tax['tax_amount_minor']);
        self::assertSame(9569, (int)$tax['sales_tax_amount_minor']);
        self::assertSame('server_calculated_tax', (string)$tax['note']);
    }

    public function testCrossBorderDeUsesDutyNotDestinationSalesTax(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: true);
        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 100000,
            ]],
        ]];

        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'DE'],
            'USD',
            [
                'shipping_amount_minor' => 500,
                'origin_country' => 'CN',
                'duty_notice' => 'duties_taxes_not_included_in_shipping',
                'goods_subtotal_minor' => 100000,
            ],
        );

        self::assertSame(0, (int)$tax['sales_tax_amount_minor']);
        self::assertGreaterThan(0, (int)$tax['duty_amount_minor']);
        self::assertGreaterThan(0, (int)$tax['import_tax_amount_minor']);
        self::assertSame(
            (int)$tax['duty_amount_minor'] + (int)$tax['import_tax_amount_minor'],
            (int)$tax['tax_amount_minor'],
        );
        self::assertSame('import_vat_via_duty_estimate', (string)$tax['note']);
        self::assertSame(
            TaxDestinationCheckoutPolicy::PROFILE_IMPORT_AT_BORDER,
            (string)($tax['destination_tax_profile'] ?? ''),
        );
    }

    public function testCrossBorderUsDutyOnlyNoSalesTax(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: true);
        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 100000,
            ]],
        ]];

        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'US', 'region_code' => 'CA'],
            'USD',
            [
                'shipping_amount_minor' => 500,
                'origin_country' => 'CN',
                'duty_notice' => 'duties_taxes_not_included_in_shipping',
                'goods_subtotal_minor' => 100000,
            ],
        );

        self::assertSame(0, (int)$tax['sales_tax_amount_minor']);
        self::assertGreaterThan(0, (int)$tax['duty_amount_minor']);
        self::assertSame(0, (int)$tax['import_tax_amount_minor']);
        self::assertSame('us_no_nexus_duty_only', (string)$tax['note']);
    }

    public function testGbDoesNotBlockCheckout(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: true);
        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 100000,
            ]],
        ]];

        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'GB'],
            'USD',
            [
                'shipping_amount_minor' => 500,
                'origin_country' => 'CN',
                'duty_notice' => 'duties_taxes_not_included_in_shipping',
                'goods_subtotal_minor' => 100000,
            ],
        );

        self::assertSame(0, (int)$tax['sales_tax_amount_minor']);
        self::assertGreaterThan(0, (int)$tax['tax_amount_minor']);
    }

    public function testCollectAllowlistChargesUsSalesTax(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(
            pricesIncludeTax: true,
            collectSalesTaxCountries: 'US',
        );

        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 10000,
            ]],
        ]];
        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'US', 'region_code' => 'CA'],
            'USD',
            ['origin_country' => 'CN', 'duty_notice' => ''],
        );

        self::assertSame(725, (int)$tax['sales_tax_amount_minor']);
        self::assertSame('server_calculated_tax', (string)$tax['note']);
    }

    public function testCollectAllowlistExemptClearsSalesTaxKeepsDuty(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(
            pricesIncludeTax: true,
            collectSalesTaxCountries: 'US',
        );
        $ship = [
            'origin_country' => 'CN',
            'duty_notice' => 'duties_taxes_not_included_in_shipping',
            'shipping_amount_minor' => 500,
            'goods_subtotal_minor' => 10000,
        ];
        $stdOrders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 10000,
            ]],
        ]];
        $exOrders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'exempt',
                'row_total_minor' => 10000,
            ]],
        ]];
        $addr = ['country_code' => 'US', 'region_code' => 'CA'];
        $scope = ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0];
        $std = $advisor->quoteTax($stdOrders, $scope, $addr, 'USD', $ship);
        $ex = $advisor->quoteTax($exOrders, $scope, $addr, 'USD', $ship);

        self::assertSame(725, (int)$std['sales_tax_amount_minor']);
        self::assertSame(0, (int)$ex['sales_tax_amount_minor']);
        self::assertGreaterThan(0, (int)$std['duty_amount_minor']);
        self::assertSame((int)$std['duty_amount_minor'], (int)$ex['duty_amount_minor']);
    }

    public function testCollectAllowlistMapsCaliforniaAliasToUsCaRate(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(
            pricesIncludeTax: true,
            collectSalesTaxCountries: 'US',
        );

        $orders = [[
            'items' => [[
                'line_uuid' => 'line-1',
                'tax_class_code' => 'standard',
                'row_total_minor' => 10000,
            ]],
        ]];
        $tax = $advisor->quoteTax(
            $orders,
            ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            ['country_code' => 'US', 'region' => 'California'],
            'USD',
            ['origin_country' => 'CN', 'duty_notice' => ''],
        );

        self::assertSame(725, (int)$tax['sales_tax_amount_minor']);
        self::assertSame('US|CA', (string)$tax['jurisdiction_key']);
    }

    public function testConfigFieldDeclaresPricesIncludeTax(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/tax.phtml'
        );
        self::assertStringContainsString('tax/general/prices_include_tax', $src);
        self::assertStringContainsString('tax/general/collect_sales_tax_countries', $src);
        self::assertStringContainsString('default="1"', $src);
    }
}
