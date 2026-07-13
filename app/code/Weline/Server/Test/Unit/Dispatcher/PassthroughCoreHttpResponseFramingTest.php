<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

final class PassthroughCoreHttpResponseFramingTest extends TestCase
{
    public function testContentLengthResponseCompletesOnlyAfterFullBody(): void
    {
        [$core, $socket, $connId] = $this->createCoreWithConnection('GET / HTTP/1.1');

        $this->track($core, $connId, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhe");
        self::assertFalse($core->isHttpResponseComplete($socket));

        $this->track($core, $connId, 'llo');
        self::assertTrue($core->isHttpResponseComplete($socket));

        @\socket_close($socket);
    }

    public function testChunkedResponseCompletesAcrossSplitControlFrames(): void
    {
        [$core, $socket, $connId] = $this->createCoreWithConnection('GET /stream HTTP/1.1');

        $this->track($core, $connId, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nWi");
        $this->track($core, $connId, "ki\r\n5\r\npedia\r\n0\r\n");
        self::assertFalse($core->isHttpResponseComplete($socket));

        $this->track($core, $connId, "\r\n");
        self::assertTrue($core->isHttpResponseComplete($socket));

        @\socket_close($socket);
    }

    public function testNoBodyResponseCompletesAtHeaderBoundary(): void
    {
        [$core, $socket, $connId] = $this->createCoreWithConnection('GET /health HTTP/1.1');

        $this->track($core, $connId, "HTTP/1.1 204 No Content\r\nConnection: keep-alive\r\n\r\n");
        self::assertTrue($core->isHttpResponseComplete($socket));

        @\socket_close($socket);
    }

    public function testTlsResponseRemainsOpaque(): void
    {
        [$core, $socket, $connId] = $this->createCoreWithConnection('GET / HTTP/1.1', true);

        $this->track($core, $connId, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        self::assertFalse($core->isHttpResponseComplete($socket));

        @\socket_close($socket);
    }

    public function testKeepAliveStartsFreshFramingForNextResponse(): void
    {
        [$core, $socket, $connId] = $this->createCoreWithConnection('GET /first HTTP/1.1');

        $this->track($core, $connId, "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\na");
        self::assertTrue($core->isHttpResponseComplete($socket));

        $this->track($core, $connId, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nb");
        self::assertFalse($core->isHttpResponseComplete($socket));
        $this->track($core, $connId, 'c');
        self::assertTrue($core->isHttpResponseComplete($socket));

        @\socket_close($socket);
    }

    /** @return array{PassthroughCore, \Socket, int} */
    private function createCoreWithConnection(string $requestLine, bool $tls = false): array
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1, $tls);
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        $connId = \spl_object_id($socket);

        $property = new \ReflectionProperty($core, 'connections');
        $property->setAccessible(true);
        $property->setValue($core, [
            $connId => [
                'request_line' => $requestLine,
            ],
        ]);

        return [$core, $socket, $connId];
    }

    private function track(PassthroughCore $core, int $connId, string $data): void
    {
        $method = new \ReflectionMethod($core, 'trackHttpResponseProgress');
        $method->setAccessible(true);
        $method->invoke($core, $connId, $data);
    }
}
