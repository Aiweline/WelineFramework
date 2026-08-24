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

    public function testDetectsSseWhenSecondAcceptHeaderIsEventStream(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /api/updates HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\nAccept: text/event-stream\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-1-header', $detected['layer']);
    }

    public function testDetectsSseWithEventStreamMediaTypeParameters(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /x HTTP/1.1\r\nHost: example.com\r\nAccept: text/event-stream; charset=utf-8\r\n\r\n";

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

    public function testFallsBackToPathForPostStreamRouteWithoutEventStreamAccept(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "POST /pagebuilder/backend/ai-generate/component-config-stream HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\nX-Requested-With: XMLHttpRequest\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-3-path-fallback', $detected['layer']);
    }

    public function testFallsBackToPathForPostTaskPlanSseRouteWithoutEventStreamAccept(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "GET /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/pagebuilder/backend/ai-site-agent/post-task-plan-sse?public_id=test&prompt_mode=detect_bootstrap_task_plan HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\nX-Requested-With: XMLHttpRequest\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-3-path-fallback', $detected['layer']);
    }

    public function testFallsBackToPathForPostPlanSseRouteWithoutEventStreamAccept(): void
    {
        $resolver = new ProtocolResolver();
        $raw = "POST /pagebuilder/backend/ai-site-agent/post-plan-sse HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\nX-Requested-With: XMLHttpRequest\r\n\r\n";

        $detected = $resolver->detect($raw);

        self::assertTrue($detected['is_long_lived']);
        self::assertSame('sse', $detected['protocol']);
        self::assertSame('layer-3-path-fallback', $detected['layer']);
    }
}
