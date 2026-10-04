<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class ConnectionAdapterDrainPrefaceTest extends TestCase
{
    public function testDrainBeforeClientPrefaceStillStartsWithServerSettings(): void
    {
        $adapter = new ConnectionAdapter();
        // 真实 TLS read owner 在 drain 时先排 GOAWAY，再交当前 client bytes。
        $wire = $adapter->initiateGoaway();
        $received = $adapter->receive(FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings());
        $wire .= $received['write'];

        $frames = $this->frames($wire);
        self::assertSame(FrameCodec::TYPE_SETTINGS, $frames[0]['type']);
        self::assertCount(1, \array_filter($frames, static fn(array $frame): bool =>
            $frame['type'] === FrameCodec::TYPE_SETTINGS && $frame['flags'] === 0,
        ));
        self::assertCount(1, \array_filter($frames, static fn(array $frame): bool =>
            $frame['type'] === FrameCodec::TYPE_WINDOW_UPDATE && $frame['stream_id'] === 0,
        ));
        self::assertSame([], $received['requests']);
        self::assertSame('', $adapter->initiateGoaway());
    }

    public function testDrainAfterEstablishedPrefaceDoesNotRepeatServerSettings(): void
    {
        $adapter = new ConnectionAdapter();
        $adapter->receive(FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings());
        $frames = $this->frames($adapter->initiateGoaway());
        self::assertCount(1, $frames);
        self::assertSame(FrameCodec::TYPE_GOAWAY, $frames[0]['type']);
        self::assertSame('', $adapter->initiateGoaway());
    }

    public function testFragmentedClientPrefaceDoesNotRepeatEarlyServerPreface(): void
    {
        $adapter = new ConnectionAdapter();
        $partial = $adapter->receive(\substr(FrameCodec::CLIENT_CONNECTION_PREFACE, 0, 10));
        self::assertSame('', $partial['write']);
        $early = $this->frames($adapter->initiateGoaway());
        self::assertSame(FrameCodec::TYPE_SETTINGS, $early[0]['type']);

        $received = $adapter->receive(\substr(FrameCodec::CLIENT_CONNECTION_PREFACE, 10) . FrameCodec::settings());
        $late = $this->frames($received['write']);
        self::assertCount(1, $late);
        self::assertSame(FrameCodec::TYPE_SETTINGS, $late[0]['type']);
        self::assertSame(FrameCodec::FLAG_ACK, $late[0]['flags']);
        self::assertSame([], $received['requests']);
    }

    public function testInvalidClientPrefaceStillReportsOriginalProtocolError(): void
    {
        $adapter = new ConnectionAdapter();
        $received = $adapter->receive(\str_repeat('x', \strlen(FrameCodec::CLIENT_CONNECTION_PREFACE)));
        self::assertSame('error', $received['status']);
        self::assertSame('invalid_client_preface', $received['error']);
        self::assertSame(FrameCodec::ERROR_PROTOCOL_ERROR, $received['error_code']);
        $frames = $this->frames($received['write']);
        self::assertSame(FrameCodec::TYPE_SETTINGS, $frames[0]['type']);
        $goaway = $frames[\count($frames) - 1];
        self::assertSame(FrameCodec::TYPE_GOAWAY, $goaway['type']);
        self::assertSame(FrameCodec::ERROR_PROTOCOL_ERROR, \unpack('Ncode', \substr($goaway['payload'], 4, 4))['code']);
    }

    private function frames(string $bytes): array
    {
        $frames = [];
        while ($bytes !== '') {
            $frame = FrameCodec::decodeOne($bytes);
            self::assertSame('frame', $frame['status']);
            $frames[] = $frame;
            $bytes = \substr($bytes, (int)$frame['consumed']);
        }
        return $frames;
    }
}
