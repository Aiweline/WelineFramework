<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\AddressFormatter;

/**
 * 回归：国家选择器的「邮编命中地点」提示（"United States · San Francisco"）只属 UI，
 * 不得被当作 country 值提交并落库成脏国家名（country_code 才是权威）。
 *
 * 现场：order 176 / checkout_group_uuid=8e67b6fa-aad6-4dd3-9edd-9e790d9bb83f
 * 的 weline_order.shipping_address.country 落成了 "United States · San Francisco"。
 */
final class CheckoutAddressCountryHintContractTest extends TestCase
{
    public function testCanonicalCountryNameStripsPostalPlaceHint(): void
    {
        self::assertSame('United States', AddressFormatter::canonicalCountryName('United States · San Francisco'));
        self::assertSame('United States', AddressFormatter::canonicalCountryName('United States'));
        self::assertSame('United States', AddressFormatter::canonicalCountryName('  United   States  '));
        self::assertSame('United States', AddressFormatter::canonicalCountryName('United States · San Francisco · CA'));
        self::assertSame('', AddressFormatter::canonicalCountryName(''));
        self::assertSame('', AddressFormatter::canonicalCountryName('   '));
    }

    public function testCanonicalizeCountryFieldsTouchesOnlyCountryNames(): void
    {
        $address = [
            'country' => 'United States · San Francisco',
            'country_name' => 'Japan · Tokyo',
            'country_code' => 'US',
            'city' => 'City and County of San Francisco',
            'province' => 'California',
        ];

        $out = AddressFormatter::canonicalizeCountryFields($address);

        self::assertSame('United States', $out['country']);
        self::assertSame('Japan', $out['country_name']);
        self::assertSame('US', $out['country_code']);
        // 其它字段不得被改动
        self::assertSame('City and County of San Francisco', $out['city']);
        self::assertSame('California', $out['province']);
    }

    public function testCanonicalizeCountryFieldsIgnoresMissingAndNonString(): void
    {
        self::assertSame([], AddressFormatter::canonicalizeCountryFields([]));
        $out = AddressFormatter::canonicalizeCountryFields(['country' => ['nested']]);
        self::assertSame(['nested'], $out['country']);
    }

    public function testCheckoutResolveAndFreezeStripCountryHint(): void
    {
        // 两条提交路径都必须收口：legacy placeOrder / getData 走 resolver；
        // V2 freeze→quote 走 CheckoutGroupSubmitService::freezeAndQuote（含 billing_address）。
        $resolver = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutShippingAddressResolver.php',
        );
        self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields', $resolver);

        $submit = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php',
        );
        self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields($address)', $submit);
        self::assertStringContainsString('AddressFormatter::canonicalizeCountryFields($billingAddress)', $submit);
    }
}
