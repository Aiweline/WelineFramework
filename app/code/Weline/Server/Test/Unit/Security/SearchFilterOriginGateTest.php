<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\GlobalRateLimiter;
use Weline\Server\Security\SearchFilterOriginGate;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

final class SearchFilterOriginGateTest extends TestCase
{
    protected function tearDown(): void
    {
        SearchFilterOriginGate::resetLimiterForTests();
        \putenv('WLS_ALLOW_LOOPBACK_SHARED_BAN');
        parent::tearDown();
    }

    public function testCountsSearchAndFilteredListingOnly(): void
    {
        self::assertTrue(SearchFilterOriginGate::isCountedBinQuery('search', 'search'));
        self::assertTrue(SearchFilterOriginGate::isCountedBinQuery('Weline_Search', 'search'));
        self::assertTrue(SearchFilterOriginGate::isCountedBinQuery('product_storefront', 'filteredListingPage'));
        self::assertFalse(SearchFilterOriginGate::isCountedBinQuery('cart', 'getCart'));
        self::assertFalse(SearchFilterOriginGate::isCountedBinQuery('search', 'hotWords'));
    }

    public function testSixthRequestWithinWindowBlocksNonLoopbackIp(): void
    {
        $ip = '203.0.113.' . \random_int(10, 250);
        SearchFilterOriginGate::resetLimiterForTests();
        for ($i = 0; $i < SearchFilterOriginGate::MAX_REQUESTS; $i++) {
            $v = SearchFilterOriginGate::checkClientIp($ip);
            self::assertTrue($v['allowed'], 'request ' . ($i + 1) . ' should pass');
        }
        $blocked = SearchFilterOriginGate::checkClientIp($ip);
        self::assertFalse($blocked['allowed']);
        self::assertTrue($blocked['blocked']);
        self::assertStringContainsString('search_filter_origin', $blocked['reason']);
    }

    public function testNormalizeClientIpPublic(): void
    {
        self::assertSame('203.0.113.9', GlobalRateLimiter::normalizeClientIp('203.0.113.9'));
        self::assertSame('', GlobalRateLimiter::normalizeClientIp('not-an-ip'));
    }
}
