<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerWriteBufferTest extends TestCase
{
    public function testLargePartialResponseDoesNotCopyItsUnsentRemainder(): void
    {
        [$server, $writer, $reader] = $this->connectedServer();
        $payload = str_repeat('0123456789abcdef', 524288);
        $method = new \ReflectionMethod($server, 'sendToClient');
        memory_reset_peak_usage();
        $baseline = memory_get_usage(false);
        try {
            self::assertTrue($method->invoke($server, (int) $writer, $payload));
            $extra = memory_get_peak_usage(false) - $baseline;
            self::assertLessThan(2 * 1024 * 1024, $extra, 'Flushing an 8 MiB response must only allocate a bounded write chunk.');
            self::assertSame(hash('sha256', $payload), hash('sha256', $this->drain($server, $writer, $reader, strlen($payload))));
        } finally {
            $this->close($server, $writer, $reader);
        }
    }

    public function testQueuedResponsePreservesOrderAcrossBackpressure(): void
    {
        [$server, $writer, $reader] = $this->connectedServer();
        $first = str_repeat('first:', 350000);
        $second = str_repeat('second:', 170000);
        $method = new \ReflectionMethod($server, 'sendToClient');
        try {
            self::assertTrue($method->invoke($server, (int) $writer, $first));
            self::assertTrue($method->invoke($server, (int) $writer, $second));
            self::assertSame($first . $second, $this->drain($server, $writer, $reader, strlen($first) + strlen($second)));
            self::assertTrue($method->invoke($server, (int) $writer, 'tail'));
            self::assertSame('tail', $this->drain($server, $writer, $reader, 4));
        } finally {
            $this->close($server, $writer, $reader);
        }
    }

    public function testOversizedPendingResponseIsRejectedBeforeConcatenation(): void
    {
        [$server, $writer, $reader] = $this->connectedServer();
        $method = new \ReflectionMethod($server, 'sendToClient');
        $first = str_repeat('a', 8 * 1024 * 1024);
        $second = str_repeat('b', 10 * 1024 * 1024);
        try {
            self::assertTrue($method->invoke($server, (int) $writer, $first));
            memory_reset_peak_usage();
            $baseline = memory_get_usage(false);
            self::assertFalse($method->invoke($server, (int) $writer, $second));
            self::assertLessThan(2 * 1024 * 1024, memory_get_peak_usage(false) - $baseline);
        } finally {
            $this->close($server, $writer, $reader);
        }
    }

    private function connectedServer(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$writer, $reader] = $sockets;
        stream_set_blocking($writer, false);
        stream_set_blocking($reader, false);
        $server = new SessionServer(['persist_enabled' => false, 'auth_enabled' => false,
            'memory_high_watermark_bytes' => PHP_INT_MAX, 'memory_low_watermark_bytes' => PHP_INT_MAX - 1]);
        (new \ReflectionProperty($server, 'clients'))->setValue($server, [(int) $writer => [
            'socket' => $writer, 'buffer' => '', 'write_buffer' => '', 'addr' => 'write-buffer-unit',
        ]]);
        return [$server, $writer, $reader];
    }

    private function drain(SessionServer $server, $writer, $reader, int $length): string
    {
        $flush = new \ReflectionMethod($server, 'flushClientWriteBuffer');
        $result = '';
        $deadline = hrtime(true) + 5_000_000_000;
        while (strlen($result) < $length && hrtime(true) < $deadline) {
            $result .= stream_get_contents($reader);
            self::assertTrue($flush->invoke($server, (int) $writer));
        }
        self::assertSame($length, strlen($result), 'A pending response must finish without a new request.');
        return $result;
    }

    private function close(SessionServer $server, $writer, $reader): void
    {
        (new \ReflectionProperty($server, 'clients'))->setValue($server, []);
        foreach ([$writer, $reader] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
