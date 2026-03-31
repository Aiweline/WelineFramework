<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Protocol\LongLived;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Protocol\LongLived\ProtocolResolver;

final class ProtocolResolverTest extends TestCase
{
    public function testDetectsSseByAcceptHeader(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /stream HTTP/1.1\r\nHost: example.com\r\nAccept: text/event-stream\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-1-header', $detected['layer']);
    }

    public function testFallsBackToPathWhenAcceptHeaderMissing(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /api/sse/updates HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-3-path-fallback', $detected['layer']);
    }

    public function testFallsBackToPathForEventStreamSegmentWithoutSseKeyword(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /app/live/event-stream HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-3-path-fallback', $detected['layer']);
    }
}
