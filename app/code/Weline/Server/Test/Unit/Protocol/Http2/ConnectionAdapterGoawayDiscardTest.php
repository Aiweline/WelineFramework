<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;
use Weline\Server\Protocol\Http2\HpackDecoder;

final class ConnectionAdapterGoawayDiscardTest extends TestCase
{
    public function testInFlightHeadersAboveGoawayLastDoNotEmitRefusedReset(): void
    {
        $adapter = $this->adapter();
        $goaway = FrameCodec::decodeOne($adapter->initiateGoaway());
        self::assertSame(0, \unpack('Nlast', $goaway['payload'])['last']);
        $result = $adapter->receive($this->headers(1, true) . $this->headers(3, true));
        self::assertSame('', $result['write']);
        self::assertSame([], $result['reset_streams']);
        self::assertSame([], $result['requests']);
        self::assertFalse($adapter->isStreamActive(1));
        self::assertFalse($adapter->isStreamActive(3));
    }

    public function testDiscardedFragmentMaintainsRealHpackTableAndContinuationGuard(): void
    {
        $decoder = new HpackDecoder();
        $adapter = $this->adapter($decoder);
        $adapter->initiateGoaway();
        // 增量索引字面量 x-drain:a；随后用动态表第62项证明真实decoder已消费。
        $block = "\x40\x07x-drain\x01a";
        $first = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, 0, 1, \substr($block, 0, 4)));
        self::assertSame('incomplete', $first['status']);
        self::assertTrue($adapter->hasIncompleteRequestInput());
        $last = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_CONTINUATION, FrameCodec::FLAG_END_HEADERS, 1, \substr($block, 4)));
        self::assertSame('incomplete', $last['status']);
        self::assertSame('', $last['write']);
        self::assertSame([], $last['requests']);
        self::assertFalse($adapter->hasIncompleteRequestInput());
        self::assertSame([['name' => 'x-drain', 'value' => 'a']], $decoder->decode("\xbe"));
    }

    public function testPaddedDiscardedDataReturnsOnlyConnectionCreditAndKeepsPing(): void
    {
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $result = $adapter->receive($this->headers(1, false)
            . FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_PADDED, 1, "\x02x\0\0")
            . FrameCodec::encode(FrameCodec::TYPE_WINDOW_UPDATE, 0, 1, "\0\0\0\0")
            . FrameCodec::rstStream(1, FrameCodec::ERROR_CANCEL)
            . FrameCodec::encode(FrameCodec::TYPE_PING, 0, 0, '12345678'));
        $frames = $this->frames($result['write']);
        self::assertSame([FrameCodec::TYPE_WINDOW_UPDATE, FrameCodec::TYPE_PING], \array_column($frames, 'type'));
        self::assertSame(0, $frames[0]['stream_id']);
        self::assertSame(4, \unpack('Ncredit', $frames[0]['payload'])['credit']);
        self::assertSame([], $result['requests']);
        self::assertSame([], $result['reset_streams']);
        self::assertFalse($adapter->isStreamActive(1));
    }

    public function testDrainReleasesUnemittedBodyAndResumesAlreadyStartedContinuation(): void
    {
        $decoder = new HpackDecoder();
        $adapter = $this->adapter($decoder);
        $adapter->receive($this->headers(1, false) . FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, 'body'));
        $block = "\x40\x07x-drain\x01a";
        $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, 0, 3, \substr($block, 0, 4)));
        $adapter->initiateGoaway();
        self::assertFalse($adapter->isStreamActive(1));
        self::assertFalse($adapter->isStreamActive(3));
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_CONTINUATION, FrameCodec::FLAG_END_HEADERS, 3, \substr($block, 4)));
        self::assertSame('incomplete', $result['status']);
        self::assertSame([], $result['requests']);
        self::assertFalse($adapter->hasIncompleteRequestInput());
        self::assertSame([['name' => 'x-drain', 'value' => 'a']], $decoder->decode("\xbe"));
    }

    public function testStreamsBelowFrozenLastStillCompleteAndLastCannotIncrease(): void
    {
        $adapter = $this->adapter();
        $adapter->receive($this->headers(1, false) . $this->headers(3, true));
        $goaway = FrameCodec::decodeOne($adapter->initiateGoaway());
        self::assertSame(3, \unpack('Nlast', $goaway['payload'])['last']);
        $result = $adapter->receive($this->headers(5, true)
            . FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_END_STREAM, 1, 'ok'));
        self::assertSame([1], \array_column($result['requests'], 'stream_id'));
        self::assertSame([], $result['reset_streams']);
        self::assertNotSame('', $adapter->encodeSimpleResponse(1, 200, [], 'one'));
        self::assertNotSame('', $adapter->encodeSimpleResponse(3, 200, [], 'three'));
        self::assertFalse($adapter->isStreamActive(5));
        self::assertSame('', $adapter->initiateGoaway());
    }

    public function testDiscardStillEnforcesHeaderLimitPaddingAndInterleaving(): void
    {
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, 0, 1, 'x'));
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_PING, 0, 0, '12345678'));
        self::assertSame('error', $result['status']);
        self::assertSame('interleaved_header_block', $result['error']);
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_PADDED | FrameCodec::FLAG_END_HEADERS, 1, "\x05x"));
        self::assertSame('error', $result['status']);
        self::assertSame('Invalid HEADERS padding.', $result['error']);
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_PADDED, 1, "\x05x"));
        self::assertSame('error', $result['status']);
        self::assertSame('Invalid DATA padding.', $result['error']);
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $chunk = \str_repeat('x', 16384);
        $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, 0, 1, $chunk));
        for ($i = 0; $i < 3; ++$i) {
            $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_CONTINUATION, 0, 1, $chunk));
        }
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_CONTINUATION, 0, 1, 'x'));
        self::assertSame('header_block_too_large', $result['error']);
    }

    public function testDiscardDoesNotHideFrameSizeOrHpackError(): void
    {
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_WINDOW_UPDATE, 0, 1, 'x'));
        self::assertSame(FrameCodec::ERROR_FRAME_SIZE_ERROR, $result['error_code']);
        $adapter = $this->adapter();
        $adapter->initiateGoaway();
        $result = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS, 1, "\xbe"));
        self::assertSame(FrameCodec::ERROR_COMPRESSION_ERROR, $result['error_code']);
    }

    public function testWithoutGoawayOriginalConcurrentRefusalStillResets(): void
    {
        $adapter = $this->adapter();
        for ($stream = 1; $stream <= 127; $stream += 2) {
            $adapter->receive($this->headers($stream, false));
        }
        $result = $adapter->receive($this->headers(129, true));
        self::assertSame([129], $result['reset_streams']);
        $frame = FrameCodec::decodeOne($result['write']);
        self::assertSame(FrameCodec::TYPE_RST_STREAM, $frame['type']);
        self::assertSame(FrameCodec::ERROR_REFUSED_STREAM, \unpack('Ncode', $frame['payload'])['code']);
    }

    private function adapter(?HpackDecoder $decoder = null): ConnectionAdapter
    {
        $adapter = new ConnectionAdapter($decoder);
        $adapter->receive(FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings());
        return $adapter;
    }

    private function headers(int $stream, bool $end): string
    {
        return FrameCodec::encode(FrameCodec::TYPE_HEADERS,
            FrameCodec::FLAG_END_HEADERS | ($end ? FrameCodec::FLAG_END_STREAM : 0),
            $stream, "\x82\x84\x87\x01\x09localhost");
    }

    private function frames(string $bytes): array
    {
        $frames = [];
        while ($bytes !== '') {
            $frame = FrameCodec::decodeOne($bytes);
            self::assertSame('frame', $frame['status']);
            $frames[] = $frame;
            $bytes = \substr($bytes, $frame['consumed']);
        }
        return $frames;
    }
}
