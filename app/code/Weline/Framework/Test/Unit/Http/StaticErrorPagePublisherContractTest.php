<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\StaticErrorPagePublisher;

final class StaticErrorPagePublisherContractTest extends TestCase
{
    public function testPublisherUsesFiberEventsAndAtomicHostMap(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/StaticErrorPagePublisher.php'
        );

        self::assertStringContainsString('FiberTaskRunner', $source);
        self::assertStringContainsString('FiberTaskBatch', $source);
        self::assertStringContainsString('settle(', $source);
        self::assertStringContainsString('writeHostMapAtomic', $source);
        self::assertStringContainsString('WELINE_STATIC_ERROR_CONCURRENCY', $source);
        self::assertStringContainsString('localesForWebsite', $source);
        // Must not claim multi-core parallelism in progress copy.
        self::assertStringNotContainsString('多核并行', $source);
    }

    public function testErrorPageRendererForwardsHost(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/ErrorPageRenderer.php'
        );

        self::assertStringContainsString('request_host', $source);
        self::assertStringContainsString('HTTP_HOST', $source);
        self::assertStringContainsString('loadHtml(', $source);
    }

    public function testDefaultConcurrencyIsFour(): void
    {
        $publisher = new StaticErrorPagePublisher();
        self::assertSame(4, $publisher->resolveConcurrency(null));
        self::assertSame(2, $publisher->resolveConcurrency(2));
    }
}
