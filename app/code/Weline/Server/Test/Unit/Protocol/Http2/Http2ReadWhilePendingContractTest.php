<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;

/**
 * Contract: H2 socket reads must not be gated on pending application queue.
 */
final class Http2ReadWhilePendingContractTest extends TestCase
{
    public function testWorkerSslAlwaysReadsHttp2SocketIndependentOfPendingQueue(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/bin/worker_ssl.php'
        );

        self::assertStringContainsString(
            'HTTP/2 must ALWAYS read the TLS socket while the connection is selected',
            $source
        );
        self::assertStringContainsString(
            'Backpressure must use socket-facing writeBytes only',
            $source
        );
        self::assertStringNotContainsString(
            '$http2QueuedResponseBytes < $http2AdmissionWriteHighWatermark',
            $source
        );
        self::assertStringNotContainsString(
            '$http2QueuedResponseBytes >= $http2AdmissionWriteHighWatermark',
            $source
        );
        self::assertStringNotContainsString(
            '!$hasPendingHttp2Request || $http2NeedsFlowControlRead',
            $source
        );
        self::assertStringContainsString(
            '$http2TimeoutAdapter->hasActiveStreams()',
            $source
        );
        self::assertStringContainsString(
            'But already-parsed streams in http2PendingRequests still need',
            $source
        );
        self::assertStringContainsString(
            'HTTP/2 must stay readable even when a false long-lived mark',
            $source
        );
        self::assertStringContainsString(
            '($connectionProtocols[$connId] ?? \'\') !== \'h2\'',
            $source
        );
        self::assertStringContainsString(
            'Application gate may pause new Fiber admission, but HTTP/2 still needs',
            $source
        );
        self::assertStringContainsString(
            'HTTP/2 `$response` is binary frames',
            $source
        );
    }
}
