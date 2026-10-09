<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\BinQueryCachePolicy;

final class BinQueryCachePolicyContractTest extends TestCase
{
    public function testPublicExternalReadWithCdnIsCacheableAndEmitsSharedHeaders(): void
    {
        $policy = new BinQueryCachePolicy();
        $operation = [
            'name' => 'getCountryFlags',
            'external' => true,
            'mode' => 'read',
            'cache' => [
                'cdn' => true,
                'ttl' => '7d',
                'visibility' => 'public',
                'key_params' => ['country_codes', 'ratio'],
                'vary' => ['area'],
            ],
        ];

        self::assertTrue($policy->isCacheableOperation($operation));

        $params = ['country_codes' => ['CN', 'US'], 'ratio' => '4x3'];
        $marker = $policy->buildMarker('frontend', 'i18n', 'getCountryFlags', $params, $operation);
        self::assertStringStartsWith('wq1.frontend.i18n.getCountryFlags.', $marker);

        $headers = $policy->cacheHeaders($operation, $marker);
        self::assertSame('public, max-age=604800, s-maxage=604800', $headers['Cache-Control']);
        self::assertSame('cdn', $headers['X-Weline-BinQuery-Cache']);
        self::assertSame($marker, $headers['X-Weline-BinQuery-Cache-Marker']);
    }

    public function testMissingExternalOrCdnFalseIsNotCacheable(): void
    {
        $policy = new BinQueryCachePolicy();

        self::assertFalse($policy->isCacheableOperation([
            'external' => false,
            'mode' => 'read',
            'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public'],
        ]));
        self::assertFalse($policy->isCacheableOperation([
            'external' => true,
            'mode' => 'read',
            'cache' => ['cdn' => false, 'ttl' => '5m', 'visibility' => 'public'],
        ]));
        self::assertFalse($policy->isCacheableOperation([
            'external' => true,
            'mode' => 'write',
            'cache' => ['cdn' => true, 'ttl' => '5m', 'visibility' => 'public'],
        ]));
        self::assertFalse($policy->isCacheableOperation([
            'external' => true,
            'mode' => 'read',
            'cache' => ['cdn' => true, 'ttl' => '0s', 'visibility' => 'public'],
        ]));
    }

    public function testParseTtlSecondsSupportsUnitSuffixes(): void
    {
        $policy = new BinQueryCachePolicy();
        self::assertSame(300, $policy->parseTtlSeconds('5m'));
        self::assertSame(1800, $policy->parseTtlSeconds('30m'));
        self::assertSame(3600, $policy->parseTtlSeconds('1h'));
        self::assertSame(604800, $policy->parseTtlSeconds('7d'));
        self::assertSame(0, $policy->parseTtlSeconds('0s'));
    }

    public function testExplicitParamsOverrideScopeVaryBindings(): void
    {
        $policy = new BinQueryCachePolicy();
        $operation = [
            'external' => true,
            'mode' => 'read',
            'cache' => [
                'cdn' => true,
                'ttl' => '5m',
                'visibility' => 'public',
                'key_params' => ['limit'],
                'vary' => ['area', 'website_id', 'channel_id'],
            ],
        ];
        $a = $policy->buildMarker('frontend', 'search', 'hotWords', [
            'limit' => 8,
            'website_id' => 1,
            'channel_id' => 2,
        ], $operation);
        $b = $policy->buildMarker('frontend', 'search', 'hotWords', [
            'limit' => 8,
            'website_id' => 9,
            'channel_id' => 2,
        ], $operation);
        self::assertNotSame($a, $b);
        self::assertStringStartsWith('wq1.frontend.search.hotWords.', $a);
    }

    public function testEmptyParamsMarkerMatchesJsObjectEncoding(): void
    {
        $policy = new BinQueryCachePolicy();
        $operation = [
            'external' => true,
            'mode' => 'read',
            'cache' => [
                'cdn' => true,
                'ttl' => '5m',
                'visibility' => 'public',
                'key_params' => ['limit'],
                'vary' => ['area'],
            ],
        ];
        $marker = $policy->buildMarker('frontend', 'search', 'hotWords', [], $operation);
        $jsJson = '{"area":"frontend","provider":"search","operation":"hotWords","params":{},"vary":{"area":"frontend"}}';
        $expected = 'wq1.frontend.search.hotWords.' . \substr(\hash('sha256', $jsJson), 0, 24);
        self::assertSame($expected, $marker);
    }
}
