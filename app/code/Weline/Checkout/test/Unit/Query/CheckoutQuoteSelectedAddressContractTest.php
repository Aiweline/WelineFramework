<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Currency full-page reload must not quote CN domestic lanes for a selected US card.
 * Express/freeze must share CheckoutShippingAddressResolver with getData.
 */
final class CheckoutQuoteSelectedAddressContractTest extends TestCase
{
    public function testGetDataResolvesShippingAddressBeforeQuote(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString('CheckoutShippingAddressResolver', $src);
        self::assertStringContainsString(
            '$shippingAddress = $this->shippingAddressResolver->resolve($shippingAddress, $params);',
            $src,
        );
        self::assertStringContainsString(
            '$address = $this->shippingAddressResolver->resolve($address, $params);',
            $src,
        );
        self::assertStringContainsString('shipping_quote_diagnostics', $src);
    }

    public function testResolverPrefersSelectedOverCascadeCnDefault(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutShippingAddressResolver.php',
        );
        self::assertStringContainsString('class CheckoutShippingAddressResolver', $src);
        self::assertStringContainsString("\$clientCc === 'CN' && \$selCc !== 'CN'", $src);
        self::assertStringContainsString('shipping_address_id', $src);
    }

    public function testExpressFlowUsesSharedAddressResolver(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
        self::assertStringContainsString('CheckoutShippingAddressResolver', $src);
        self::assertStringContainsString('addressResolver->resolve', $src);
        self::assertGreaterThanOrEqual(3, substr_count($src, 'CheckoutShippingAddressResolver'));
    }

    public function testCheckoutFormAddressPrefersWidgetResolveQuoteAddress(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml',
        );
        self::assertStringContainsString('resolveQuoteAddress', $src);
        self::assertStringContainsString('WelineShippingCheckoutAddress', $src);
        self::assertStringContainsString('shipping_address_id', $src);
        self::assertStringContainsString(
            'text(shipping.country_code).trim().toUpperCase()',
            $src,
        );
    }

    public function testSelectAddressLooksUpAcrossCountries(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php',
        );
        // 意图：跨国家查找（首参恒 ''），禁止按当前国家过滤；后续加了 purpose 维度不违此意。
        self::assertMatchesRegularExpression(
            "/foreach \(\\\$this->listAddresses\(''/",
            $src,
        );
        self::assertStringNotContainsString(
            'foreach ($this->listAddresses($this->currentCountryCode()) as $address)',
            $src,
        );
    }
}
