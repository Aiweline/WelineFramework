<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PayPalJsSdkUrlBuilder;

final class PayPalJsSdkFundingContractTest extends TestCase
{
    public function testBothEnabledWritesEnableFundingOnly(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'client_id' => 'CLIENT',
            'google_pay_enabled' => true,
            'apple_pay_enabled' => true,
            'default_currency' => 'USD',
        ]);
        self::assertStringContainsString('client-id=CLIENT', $url);
        self::assertStringContainsString('enable-funding=', $url);
        self::assertStringContainsString('googlepay', $url);
        self::assertStringContainsString('applepay', $url);
        self::assertStringNotContainsString('disable-funding=', $url);
    }

    public function testGoogleOffDisablesGooglepay(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'client_id' => 'CLIENT',
            'google_pay_enabled' => false,
            'apple_pay_enabled' => true,
        ]);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertSame('applepay', $q['enable-funding'] ?? null);
        self::assertSame('googlepay', $q['disable-funding'] ?? null);
    }

    public function testAppleOffDisablesApplepay(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'client_id' => 'CLIENT',
            'google_pay_enabled' => true,
            'apple_pay_enabled' => '0',
        ]);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertSame('googlepay', $q['enable-funding'] ?? null);
        self::assertSame('applepay', $q['disable-funding'] ?? null);
    }

    public function testBothOffDisablesBoth(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'client_id' => 'CLIENT',
            'google_pay_enabled' => false,
            'apple_pay_enabled' => false,
        ]);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertArrayNotHasKey('enable-funding', $q);
        $disable = explode(',', (string) ($q['disable-funding'] ?? ''));
        self::assertContains('googlepay', $disable);
        self::assertContains('applepay', $disable);
    }

    public function testMissingClientIdReturnsEmpty(): void
    {
        self::assertSame('', (new PayPalJsSdkUrlBuilder())->build([
            'google_pay_enabled' => true,
            'apple_pay_enabled' => true,
        ]));
    }

    public function testSandboxClientIdFallback(): void
    {
        $url = (new PayPalJsSdkUrlBuilder())->build([
            'environment' => 'sandbox',
            'sandbox_client_id' => 'SB',
            'google_pay_enabled' => true,
            'apple_pay_enabled' => false,
        ]);
        self::assertStringContainsString('client-id=SB', $url);
    }
}
