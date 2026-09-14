<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ContentEncodingNegotiator;
use Weline\Framework\Http\Response;

final class ContentEncodingNegotiatorTest extends TestCase
{
    public function testPrefersBrotliWhenAvailableAndAccepted(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('brotli extension is not loaded');
        }

        self::assertSame(
            ContentEncodingNegotiator::ENCODING_BROTLI,
            ContentEncodingNegotiator::negotiate('gzip, deflate, br'),
        );
        self::assertSame(
            ContentEncodingNegotiator::ENCODING_GZIP,
            ContentEncodingNegotiator::negotiate('gzip'),
        );
    }

    public function testBrotliBeatsGzipAtEqualQuality(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('brotli extension is not loaded');
        }

        self::assertSame(
            ContentEncodingNegotiator::ENCODING_BROTLI,
            ContentEncodingNegotiator::negotiate('br;q=1.0, gzip;q=1.0'),
        );
        self::assertSame(
            ContentEncodingNegotiator::ENCODING_GZIP,
            ContentEncodingNegotiator::negotiate('br;q=0.5, gzip;q=1.0'),
        );
    }

    public function testEncodeRoundTrip(): void
    {
        $body = \str_repeat('<div class="w-layout">critical css</div>', 80);
        $gzip = ContentEncodingNegotiator::encode($body, ContentEncodingNegotiator::ENCODING_GZIP);
        self::assertNotNull($gzip);
        self::assertSame($body, ContentEncodingNegotiator::decode($gzip, ContentEncodingNegotiator::ENCODING_GZIP));

        if (!ContentEncodingNegotiator::brotliAvailable()) {
            return;
        }
        $br = ContentEncodingNegotiator::encode($body, ContentEncodingNegotiator::ENCODING_BROTLI);
        self::assertNotNull($br);
        self::assertLessThan(\strlen($gzip), \strlen($br));
        self::assertSame($body, ContentEncodingNegotiator::decode($br, ContentEncodingNegotiator::ENCODING_BROTLI));
    }

    public function testResponseCompressPrefersBrotli(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('brotli extension is not loaded');
        }

        $body = \str_repeat('layout-critical-css-rule{display:block;}', 60);
        $response = Response::fromContent($body, 200, 'text/html; charset=utf-8');
        $response->compress('br, gzip');

        self::assertSame('br', $response->getHeader('Content-Encoding'));
        self::assertSame($body, \brotli_uncompress($response->getBody()));
    }

    public function testResponseCompressFallsBackToGzip(): void
    {
        $body = \str_repeat('layout-critical-css-rule{display:block;}', 60);
        $response = Response::fromContent($body, 200, 'text/html; charset=utf-8');
        $response->compress('gzip');

        self::assertSame('gzip', $response->getHeader('Content-Encoding'));
        self::assertSame($body, \gzdecode($response->getBody()));
    }
}
