<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WorkerResponseMemoryGuardTest extends TestCase
{
    public function testForcesCloseForLargeKeepAliveResponse(): void
    {
        self::assertTrue(
            WorkerResponseMemoryGuard::shouldForceConnectionClose(
                true,
                false,
                WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES
            )
        );
    }

    public function testForcesCloseWhenBufferedBytesAreAlreadyHigh(): void
    {
        self::assertTrue(
            WorkerResponseMemoryGuard::shouldForceConnectionClose(
                true,
                false,
                1024,
                WorkerResponseMemoryGuard::LARGE_BUFFER_BYTES
            )
        );
    }

    public function testKeepsSmallShortResponseAlive(): void
    {
        self::assertFalse(
            WorkerResponseMemoryGuard::shouldForceConnectionClose(
                true,
                false,
                4096,
                0
            )
        );
    }

    public function testDoesNotOverrideAlreadyClosingOrLongLivedResponses(): void
    {
        self::assertFalse(
            WorkerResponseMemoryGuard::shouldForceConnectionClose(
                false,
                false,
                WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES
            )
        );

        self::assertFalse(
            WorkerResponseMemoryGuard::shouldForceConnectionClose(
                true,
                true,
                WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES
            )
        );
    }

    public function testForceConnectionCloseHeaderRewritesExistingHeader(): void
    {
        $response = "HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nContent-Length: 2\r\n\r\nOK";

        $rewritten = WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);

        self::assertStringContainsString("\r\nConnection: close\r\n", $rewritten);
        self::assertStringNotContainsString('Connection: keep-alive', $rewritten);
    }

    public function testForceConnectionCloseHeaderAppendsHeaderWhenMissing(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";

        $rewritten = WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);

        self::assertStringContainsString("\r\nConnection: close\r\n\r\nOK", $rewritten);
    }

    public function testDetectsExplicitConnectionCloseResponseHeader(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";

        self::assertTrue(WorkerResponseMemoryGuard::responseRequestsConnectionClose($response));
    }

    public function testIgnoresResponsesWithoutExplicitConnectionClose(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nOK";

        self::assertFalse(WorkerResponseMemoryGuard::responseRequestsConnectionClose($response));
    }

    public function testShortHttpDrainWaitsForPeerCloseAcknowledgement(): void
    {
        self::assertTrue(WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(
            true,
            false,
        ));
    }

    public function testNormalLongLivedAndMultiplexedResponsesDoNotUsePeerCloseWait(): void
    {
        self::assertFalse(WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(
            false,
            false,
        ));
        self::assertFalse(WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(
            true,
            true,
        ));
        self::assertFalse(WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(
            true,
            false,
            true,
        ));
    }

    public function testLinuxSharedEventListenerDrainsBoundedAcceptBatch(): void
    {
        self::assertSame(
            64,
            WorkerResponseMemoryGuard::listenerAcceptBatchLimit(true, 'Linux', 'event')
        );
    }

    public function testDarwinSharedEventListenerDrainsBoundedAcceptBatch(): void
    {
        self::assertSame(
            64,
            WorkerResponseMemoryGuard::listenerAcceptBatchLimit(true, 'Darwin', 'event')
        );
    }

    public function testSharedListenerFairnessRemainsSingleAcceptForSelect(): void
    {
        self::assertSame(
            1,
            WorkerResponseMemoryGuard::listenerAcceptBatchLimit(true, 'Linux', 'select')
        );
        self::assertSame(
            1,
            WorkerResponseMemoryGuard::listenerAcceptBatchLimit(true, 'Darwin', 'select')
        );
    }

    public function testIndependentListenerKeepsFullAcceptBatch(): void
    {
        self::assertSame(
            64,
            WorkerResponseMemoryGuard::listenerAcceptBatchLimit(false, 'Linux', 'event')
        );
    }

    public function testSseWriteBufferWouldExceedWhenOverLimit(): void
    {
        $max = WorkerResponseMemoryGuard::SSE_MAX_PENDING_WRITE_BYTES;
        self::assertTrue(WorkerResponseMemoryGuard::sseWriteBufferWouldExceed($max, 1));
    }

    public function testSseWriteBufferWouldNotExceedWhenWithinLimit(): void
    {
        $max = WorkerResponseMemoryGuard::SSE_MAX_PENDING_WRITE_BYTES;
        self::assertFalse(WorkerResponseMemoryGuard::sseWriteBufferWouldExceed($max - 100, 50));
    }

    public function testSseWriteBufferIgnoresZeroAppend(): void
    {
        self::assertFalse(WorkerResponseMemoryGuard::sseWriteBufferWouldExceed(999999999, 0));
    }
}
