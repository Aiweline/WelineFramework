<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class ConnectionAdapterRequestCancellationTest extends TestCase
{
    public function testConfiguredBodyLimitRejectsBeforeEndStreamAndKeepsControlFramesWorking(): void
    {
        $adapter = new ConnectionAdapter(null, 1024);
        $adapter->receive($this->preface() . $this->headers(1));
        $accepted = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, \str_repeat('x', 1024)));
        self::assertSame([], $accepted['reset_streams']);
        self::assertTrue($adapter->isStreamActive(1));

        $result = $adapter->receive(
            FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, 'x')
            . FrameCodec::encode(FrameCodec::TYPE_PING, 0, 0, '12345678')
            . $this->headers(3, true),
        );
        self::assertSame([1], $result['reset_streams']);
        self::assertFalse($adapter->isStreamActive(1));
        self::assertSame([3], \array_column($result['requests'], 'stream_id'));
        $frames = $this->frames($result['write']);
        self::assertContains(FrameCodec::TYPE_RST_STREAM, \array_column($frames, 'type'));
        self::assertContains(FrameCodec::TYPE_PING, \array_column($frames, 'type'));
        $credits = \array_values(\array_filter($frames, static fn (array $f): bool => $f['type'] === FrameCodec::TYPE_WINDOW_UPDATE));
        self::assertCount(1, $credits);
        self::assertSame(0, $credits[0]['stream_id']);
        self::assertSame("\0\0\0\x01", $credits[0]['payload']);
    }

    public function testExactBodyBoundaryAndPaddedPayloadRemainLegal(): void
    {
        $adapter = new ConnectionAdapter(null, 1024);
        $adapter->receive($this->preface() . $this->headers(1));
        $result = $adapter->receive(FrameCodec::encode(
            FrameCodec::TYPE_DATA,
            FrameCodec::FLAG_PADDED | FrameCodec::FLAG_END_STREAM,
            1,
            "\x02" . \str_repeat('x', 1024) . "\0\0",
        ));
        self::assertSame([], $result['reset_streams']);
        self::assertCount(1, $result['requests']);
        self::assertStringEndsWith("\r\n\r\n" . \str_repeat('x', 1024), $result['requests'][0]['raw_request']);
    }

    public function testDataAlreadyInFlightAfterResetReturnsConnectionCredit(): void
    {
        $adapter = new ConnectionAdapter(null, 1024);
        $adapter->receive($this->preface() . $this->headers(1));
        $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, \str_repeat('x', 1025)));
        $result = $adapter->receive(
            FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, \str_repeat('x', 2048))
            . $this->headers(3, true),
        );
        self::assertSame([3], \array_column($result['requests'], 'stream_id'));
        $credits = \array_values(\array_filter($this->frames($result['write']), static fn (array $f): bool => $f['type'] === FrameCodec::TYPE_WINDOW_UPDATE));
        self::assertCount(1, $credits);
        self::assertSame(0, $credits[0]['stream_id']);
        self::assertSame("\0\0\x08\0", $credits[0]['payload']);
    }

    public function testRemoteClosedStreamCreditsPaddingAndDropsItsPreviouslyEmittedRequest(): void
    {
        $adapter = new ConnectionAdapter();
        $result = $adapter->receive(
            $this->preface() . $this->headers(1, true)
            . FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_PADDED, 1, "\x02x\0\0"),
        );
        self::assertSame([1], $result['reset_streams']);
        self::assertSame([], $result['requests']);
        $credits = \array_values(\array_filter($this->frames($result['write']), static fn (array $f): bool => $f['type'] === FrameCodec::TYPE_WINDOW_UPDATE));
        self::assertCount(2, $credits);
        self::assertSame(0, $credits[1]['stream_id']);
        self::assertSame("\0\0\0\x04", $credits[1]['payload']);

        $empty = $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 1, ''));
        self::assertNotContains(FrameCodec::TYPE_WINDOW_UPDATE, \array_column($this->frames($empty['write']), 'type'));
    }

    public function testDeclaredOversizedBodyIsRejectedAtEndHeadersIncludingContinuation(): void
    {
        foreach ([false, true] as $continued) {
            $adapter = new ConnectionAdapter(null, 1024);
            $headers = $this->headerBlock() . "\x0f\x0d\x04" . '1025';
            $input = $this->preface() . FrameCodec::encode(FrameCodec::TYPE_HEADERS, $continued ? 0 : FrameCodec::FLAG_END_HEADERS, 1, $headers);
            if ($continued) {
                $input .= FrameCodec::encode(FrameCodec::TYPE_CONTINUATION, FrameCodec::FLAG_END_HEADERS, 1, '');
            }
            $result = $adapter->receive($input);
            self::assertSame([1], $result['reset_streams']);
            self::assertFalse($adapter->isStreamActive(1));
            self::assertSame([], $result['requests']);
        }
    }

    public function testDefaultLimitStillAcceptsDeclared512MiBWithoutAllocatingTheBody(): void
    {
        $adapter = new ConnectionAdapter();
        $headerBlock = $this->headerBlock() . "\x0f\x0d\x09" . '536870912';
        $result = $adapter->receive($this->preface() . FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS, 1, $headerBlock));
        self::assertSame([], $result['reset_streams']);
        self::assertTrue($adapter->isStreamActive(1));
    }

    public function testSameBatchResetsCannotReturnCancelledRequestsAndRetainAnAdjacentLiveStream(): void
    {
        $adapter = new ConnectionAdapter();
        $batch = $this->preface();
        for ($stream = 1; $stream < 200; $stream += 2) {
            $batch .= $this->headers($stream, true) . FrameCodec::rstStream($stream, FrameCodec::ERROR_CANCEL);
        }
        $result = $adapter->receive($batch . $this->headers(201, true));
        self::assertSame([201], \array_column($result['requests'], 'stream_id'));
        self::assertCount(100, $result['reset_streams']);
        self::assertFalse($adapter->isStreamActive(199));
        self::assertTrue($adapter->isStreamActive(201));
    }

    private function preface(): string
    {
        return FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings();
    }

    private function headerBlock(): string
    {
        return "\x83\x84\x87\x01\x09localhost";
    }

    private function headers(int $streamId, bool $endStream = false): string
    {
        return FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS | ($endStream ? FrameCodec::FLAG_END_STREAM : 0), $streamId, $this->headerBlock());
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
