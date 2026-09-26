<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 快捷智能支付入口不得被配送预检堵死：
 * guest/无可信国家时禁止盲目 CN 兜底报价，须直通支付商带回地址（deferred shipping）。
 */
final class ExpressStartDeferredShippingContractTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
    }

    public function testStartNeverFallsBackToBlindCnCountry(): void
    {
        self::assertStringNotContainsString("\$address['country_code'] = 'CN';", $this->src);
    }

    public function testShippingPreflightOnlyWithTrustedCountry(): void
    {
        self::assertMatchesRegularExpression(
            '/\$serviceCode = \'\';\s*if \(\$requiresShipping && \$trustedCountry !== \'\'\)/',
            $this->src,
        );
    }

    public function testFreezeRejectionRetriesOnceAsDeferredShipping(): void
    {
        self::assertStringContainsString('$deferred = $freezeQuote([', $this->src);
        self::assertMatchesRegularExpression(
            '/if \(\$requiresShipping && \$trustedCountry === \'\'\) \{\s*\$deferred = \$freezeQuote/',
            $this->src,
        );
        // deferred 重冻必须清空地址与 service_code，交由 review 回跳后重估。
        self::assertMatchesRegularExpression(
            "/'address' => \[\],\s*'billing_address' => \[\],/",
            $this->src,
        );
    }
}
