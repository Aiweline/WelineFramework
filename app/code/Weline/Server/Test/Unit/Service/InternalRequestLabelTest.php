<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\InternalRequestLabel;

final class InternalRequestLabelTest extends TestCase
{
    public function testBuildHeaderLineUsesConfiguredHeaderName(): void
    {
        self::assertSame(
            "X-WLS-Internal-Request: health-probe\r\n",
            InternalRequestLabel::buildHeaderLine(InternalRequestLabel::HEALTH_PROBE)
        );
    }

    public function testDetectFromRawRequestExtractsKnownInternalLabel(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-WLS-Internal-Request: homepage-warmup\r\n"
            . "Connection: close\r\n\r\n";

        self::assertSame(
            InternalRequestLabel::HOMEPAGE_WARMUP,
            InternalRequestLabel::detectFromRawRequest($rawRequest)
        );
    }

    public function testBuildLogPrefixReturnsEmptyStringWhenHeaderMissing(): void
    {
        self::assertSame('', InternalRequestLabel::buildLogPrefix("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
    }

    public function testBuildLogPrefixFormatsInternalRequestContext(): void
    {
        $rawRequest = "GET /_wls/health HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-WLS-Internal-Request: health-probe\r\n\r\n";

        self::assertSame('[internal:health-probe] ', InternalRequestLabel::buildLogPrefix($rawRequest));
    }
}
