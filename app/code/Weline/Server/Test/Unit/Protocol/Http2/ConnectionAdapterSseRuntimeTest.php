<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class ConnectionAdapterSseRuntimeTest extends TestCase
{
    public function testStreamingResponseFlushesIncrementalDataBeforeBusinessCompletion(): void
    {
        $adapter = new ConnectionAdapter();
        $this->openGetStream($adapter, 1);

        $headers = $adapter->beginStreamingResponse(1, $this->sseHeaders());
        $headerFrames = $this->decodeFrames($headers);

        self::assertNotSame([], $headerFrames);
        self::assertSame(FrameCodec::TYPE_HEADERS, $headerFrames[0]['type']);
        self::assertSame(0, $headerFrames[0]['flags'] & FrameCodec::FLAG_END_STREAM);
        $headerBlock = \implode('', \array_column($headerFrames, 'payload'));
        self::assertStringContainsString('content-type', $headerBlock);
        self::assertStringNotContainsString('connection', \strtolower($headerBlock));
        self::assertTrue($adapter->isStreamingResponse(1));

        $first = $adapter->appendStreamingData(1, "data: first\n\n");
        $firstFrames = $this->decodeFrames($first);
        self::assertSame("data: first\n\n", $this->dataPayload($firstFrames));
        self::assertSame(0, $firstFrames[0]['flags'] & FrameCodec::FLAG_END_STREAM);

        $second = $adapter->appendStreamingData(1, "data: second\n\n");
        $secondFrames = $this->decodeFrames($second);
        self::assertSame("data: second\n\n", $this->dataPayload($secondFrames));
        self::assertSame(0, $secondFrames[0]['flags'] & FrameCodec::FLAG_END_STREAM);

        $end = $adapter->endStreamingResponse(1);
        $endFrames = $this->decodeFrames($end);
        self::assertCount(1, $endFrames);
        self::assertSame(FrameCodec::TYPE_DATA, $endFrames[0]['type']);
        self::assertSame(FrameCodec::FLAG_END_STREAM, $endFrames[0]['flags'] & FrameCodec::FLAG_END_STREAM);
        self::assertSame('', $endFrames[0]['payload']);
        self::assertFalse($adapter->isStreamActive(1));
    }

    public function testStreamingDataHonoursStreamAndConnectionFlowControl(): void
    {
        $streamLimited = new ConnectionAdapter();
        $this->openGetStream($streamLimited, 1, [
            FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 5,
        ]);
        $streamLimited->beginStreamingResponse(1, $this->sseHeaders());

        $first = $this->decodeFrames($streamLimited->appendStreamingData(1, 'abcdefghij'));
        self::assertSame('abcde', $this->dataPayload($first));
        self::assertSame(5, $streamLimited->pendingResponseBytes(1));

        $windowResult = $streamLimited->receive(FrameCodec::windowUpdate(1, 5));
        $second = $this->decodeFrames((string)$windowResult['write']);
        self::assertSame('fghij', $this->dataPayload($second));
        self::assertSame(0, $streamLimited->pendingResponseBytes(1));
        self::assertTrue($streamLimited->isStreamingResponse(1));

        $connectionLimited = new ConnectionAdapter();
        $this->openGetStream($connectionLimited, 1, [
            FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 100000,
        ]);
        $connectionLimited->beginStreamingResponse(1, $this->sseHeaders());

        $payload = \str_repeat('x', 70000);
        $initial = $this->decodeFrames($connectionLimited->appendStreamingData(1, $payload));
        self::assertSame(65535, \strlen($this->dataPayload($initial)));
        self::assertSame(4465, $connectionLimited->pendingResponseBytes(1));

        $connectionWindow = $connectionLimited->receive(FrameCodec::windowUpdate(0, 4465));
        $released = $this->decodeFrames((string)$connectionWindow['write']);
        self::assertSame(4465, \strlen($this->dataPayload($released)));
        self::assertSame(0, $connectionLimited->pendingResponseBytes(1));
    }

    public function testEndStreamWaitsBehindFlowControlledData(): void
    {
        $adapter = new ConnectionAdapter();
        $this->openGetStream($adapter, 1, [
            FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 5,
        ]);
        $adapter->beginStreamingResponse(1, $this->sseHeaders());

        $initial = $this->decodeFrames($adapter->appendStreamingData(1, 'abcdefghij'));
        self::assertSame('abcde', $this->dataPayload($initial));
        self::assertSame('', $adapter->endStreamingResponse(1));
        self::assertTrue($adapter->isStreamActive(1));

        $result = $adapter->receive(FrameCodec::windowUpdate(1, 5));
        $released = $this->decodeFrames((string)$result['write']);
        self::assertSame('fghij', $this->dataPayload($released));
        self::assertSame(
            FrameCodec::FLAG_END_STREAM,
            $released[\count($released) - 1]['flags'] & FrameCodec::FLAG_END_STREAM,
        );
        self::assertFalse($adapter->isStreamActive(1));
    }

    public function testFlowControlledSseDoesNotBlockAConcurrentNormalStream(): void
    {
        $adapter = new ConnectionAdapter();
        $this->openGetStream($adapter, 1, [
            FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 5,
        ]);
        $secondRequest = $adapter->receive($this->getHeadersFrame(3));
        self::assertSame('ok', $secondRequest['status']);
        self::assertSame(3, $secondRequest['requests'][0]['stream_id']);

        $adapter->beginStreamingResponse(1, $this->sseHeaders());
        $adapter->appendStreamingData(1, 'abcdefghij');
        self::assertSame(5, $adapter->pendingResponseBytes(1));

        $normalFrames = $this->decodeFrames($adapter->encodeSimpleResponse(
            3,
            200,
            ['content-type' => 'text/plain'],
            'ok',
        ));
        self::assertSame('ok', $this->dataPayload($normalFrames));
        self::assertSame(
            FrameCodec::FLAG_END_STREAM,
            $normalFrames[\count($normalFrames) - 1]['flags'] & FrameCodec::FLAG_END_STREAM,
        );
        self::assertTrue($adapter->isStreamActive(1));
        self::assertFalse($adapter->isStreamActive(3));
    }

    public function testClientResetDropsQueuedStreamingDataAndMarksStreamCancelled(): void
    {
        $adapter = new ConnectionAdapter();
        $this->openGetStream($adapter, 1, [
            FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 0,
        ]);
        $adapter->beginStreamingResponse(1, $this->sseHeaders());

        self::assertSame('', $adapter->appendStreamingData(1, 'queued-event'));
        self::assertSame(12, $adapter->pendingResponseBytes(1));

        $result = $adapter->receive(FrameCodec::rstStream(1, FrameCodec::ERROR_CANCEL));

        self::assertSame([1], $result['reset_streams']);
        self::assertFalse($adapter->isStreamActive(1));
        self::assertSame(0, $adapter->pendingResponseBytes(1));
        self::assertSame('', $adapter->appendStreamingData(1, 'must-not-leak'));
    }

    public function testGoawayRefusesNewStreamsWhileExistingSseCanFinish(): void
    {
        $adapter = new ConnectionAdapter();
        $this->openGetStream($adapter, 1);
        $adapter->beginStreamingResponse(1, $this->sseHeaders());

        $goaway = $this->decodeFrames($adapter->initiateGoaway());
        self::assertCount(1, $goaway);
        self::assertSame(FrameCodec::TYPE_GOAWAY, $goaway[0]['type']);

        $newStream = $adapter->receive($this->getHeadersFrame(3));
        self::assertSame([3], $newStream['reset_streams']);
        $resetFrames = $this->decodeFrames((string)$newStream['write']);
        self::assertSame(FrameCodec::TYPE_RST_STREAM, $resetFrames[0]['type']);

        $existing = $this->decodeFrames($adapter->appendStreamingData(1, "data: still-alive\n\n"));
        self::assertSame("data: still-alive\n\n", $this->dataPayload($existing));
        self::assertNotSame('', $adapter->endStreamingResponse(1));
        self::assertFalse($adapter->isStreamActive(1));
    }

    /** @param array<int,int> $settings */
    private function openGetStream(ConnectionAdapter $adapter, int $streamId, array $settings = []): void
    {
        $result = $adapter->receive(
            FrameCodec::CLIENT_CONNECTION_PREFACE
            . FrameCodec::settings($settings)
            . $this->getHeadersFrame($streamId),
        );

        self::assertSame('ok', $result['status']);
        self::assertCount(1, $result['requests']);
        self::assertSame($streamId, $result['requests'][0]['stream_id']);
    }

    private function getHeadersFrame(int $streamId): string
    {
        $authority = 'example.test';
        $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;

        return FrameCodec::encode(
            FrameCodec::TYPE_HEADERS,
            FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM,
            $streamId,
            $headerBlock,
        );
    }

    private function sseHeaders(): string
    {
        return "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream; charset=utf-8\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Connection: keep-alive\r\n"
            . "X-Accel-Buffering: no\r\n\r\n";
    }

    /**
     * @return list<array{status:string,consumed:int,type:int,flags:int,stream_id:int,payload:string}>
     */
    private function decodeFrames(string $bytes): array
    {
        $frames = [];
        while ($bytes !== '') {
            $frame = FrameCodec::decodeOne($bytes, 0x00ffffff);
            self::assertSame('frame', $frame['status']);
            $frames[] = $frame;
            $bytes = \substr($bytes, (int)$frame['consumed']);
        }

        return $frames;
    }

    /** @param list<array{type:int,payload:string}> $frames */
    private function dataPayload(array $frames): string
    {
        $payload = '';
        foreach ($frames as $frame) {
            if ($frame['type'] === FrameCodec::TYPE_DATA) {
                $payload .= $frame['payload'];
            }
        }

        return $payload;
    }
}
