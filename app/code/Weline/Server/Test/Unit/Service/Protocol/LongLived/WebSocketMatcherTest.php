<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Protocol\LongLived;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Protocol\LongLived\WebSocketMatcher;

final class WebSocketMatcherTest extends TestCase
{
    private WebSocketMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new WebSocketMatcher();
    }

    public function testDoesNotMatchPlainHttpRequest(): void
    {
        $raw = "GET /admin HTTP/1.1\r\nHost: example.com\r\nAccept: text/html\r\n\r\n";

        self::assertNull($this->matcher->match($raw));
    }

    public function testDoesNotMatchWhenOnlyConnectionUpgradeHeaderPresent(): void
    {
        $raw = "GET /admin HTTP/1.1\r\nHost: example.com\r\nConnection: upgrade\r\nAccept: text/html\r\n\r\n";

        self::assertNull($this->matcher->match($raw));
    }

    public function testDoesNotMatchWhenOnlySecWebSocketKeyPresent(): void
    {
        $raw = "GET /ws HTTP/1.1\r\nHost: example.com\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        self::assertNull($this->matcher->match($raw));
    }

    public function testMatchesFullWebSocketHandshake(): void
    {
        $raw = "GET /ws HTTP/1.1\r\nHost: example.com\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        $matched = $this->matcher->match($raw);

        self::assertNotNull($matched);
        self::assertTrue($matched['is_long_lived']);
        self::assertSame('websocket', $matched['protocol']);
        self::assertSame('layer-1-header', $matched['layer']);
    }

    public function testMatchesWhenUpgradeWebsocketAndSecWebSocketKeyWithoutExplicitConnectionUpgrade(): void
    {
        $raw = "GET /ws HTTP/1.1\r\nHost: example.com\r\nUpgrade: websocket\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        $matched = $this->matcher->match($raw);

        self::assertNotNull($matched);
        self::assertSame('websocket', $matched['protocol']);
    }
}
