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
}
