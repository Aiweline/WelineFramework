<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 报价 config version：同请求稳定 + 作用范围感知（源码契约）。
 */
final class QuoteConfigVersionStabilityContractTest extends TestCase
{
    private function managerSrc(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php');
    }

    private function scopedSrc(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ScopedShippingQuoteService.php');
    }

    public function testActiveQuoteConfigVersionAcceptsContextAndMemosByLayer(): void
    {
        $src = $this->managerSrc();
        self::assertMatchesRegularExpression(
            '/function\s+activeQuoteConfigVersion\s*\(\s*\?array\s+\$context\s*=\s*null\s*\)/',
            $src,
        );
        self::assertStringContainsString('resolveNearestServiceLayer', $src);
        self::assertStringContainsString('quoteConfigVersionMemo', $src);
        self::assertStringContainsString('pickFactFields', $src);
        self::assertStringNotContainsString(
            "'service' => \$this->canonical((array)\$service->getData())",
            $src,
        );
    }

    public function testReachabilityFactsUseFreshModelsAndScopeChain(): void
    {
        $src = $this->managerSrc();
        self::assertStringContainsString('EmbargoRegion::class, [], false', $src);
        self::assertStringContainsString('quoteLayerChain', $src);
        self::assertStringContainsString('SCOPE_SYSTEM', $src);
        self::assertStringContainsString('service_lanes', $src);
        self::assertStringContainsString('serviceIds', $src);
    }

    public function testQuoteRatesAndAvailableServicesThreadContext(): void
    {
        $src = $this->managerSrc();
        self::assertMatchesRegularExpression(
            '/function\s+quoteRates\s*\([^)]*\?array\s+\$context\s*=\s*null/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/function\s+getAvailableServices\s*\([^)]*\?array\s+\$context\s*=\s*null/',
            $src,
        );
        $avail = strstr($src, 'function getAvailableServices');
        self::assertNotFalse($avail);
        $chunk = substr((string)$avail, 0, 1200);
        self::assertStringContainsString('coverageMatch()->getAvailableServices', $chunk);
        self::assertStringContainsString('$context', $chunk);
    }

    public function testScopedQuotePassesRequestScopeIntoVersionAndRates(): void
    {
        $src = $this->scopedSrc();
        self::assertStringContainsString('quoteContext', $src);
        self::assertStringContainsString('activeQuoteConfigVersion(', $src);
        self::assertStringContainsString('quoteRates(', $src);
        self::assertStringContainsString('$request->scope', $src);
        self::assertStringContainsString('website_id', $src);
        self::assertStringContainsString('store_id', $src);
        self::assertStringContainsString('channel_id', $src);
    }

    public function testShippingInfoProviderNormalizesScopeIntoRequest(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php',
        );
        self::assertStringContainsString('normalizeQuoteScope', $src);
        self::assertStringContainsString('website_id', $src);
    }
}
