<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

/**
 * Large peer windows must keep draining without waiting for another WINDOW_UPDATE.
 */
final class Http2DrainWithoutWindowUpdateContractTest extends TestCase
{
    public function testHtmlDocumentDrainsBeyondQuantumEvenWhenOtherStreamsCompete(): void
    {
        $bodyBytes = 600000;
        $adapter = $this->openAdapterWithWindow($bodyBytes * 2);
        $this->openRequestStream($adapter, 3);
        $adapter->beginStreamingResponse(3, "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n");
        $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));
        self::assertTrue($adapter->hasPendingResponseData());

        $frames = $adapter->encodeSimpleResponse(
            1,
            200,
            ['content-type' => 'text/html; charset=utf-8'],
            \str_repeat('h', $bodyBytes)
        );
        self::assertGreaterThan(
            262144,
            $this->dataPayloadBytes($frames),
            'text/html must keep draining past one quantum even with competing streams'
        );
        if ($adapter->pendingResponseBytes(1) > 0) {
            self::assertSame(
                1,
                $adapter->exclusiveDocumentStreamId(),
                'unfinished HTML remains connection response state for continued scheduling'
            );
        } else {
            self::assertNull($adapter->exclusiveDocumentStreamId());
        }
    }

    public function testEncodeKeepsLargeBodyBoundedForMultiplexFairness(): void
    {
        $bodyBytes = 600000;
        $adapter = $this->openAdapterWithWindow($bodyBytes * 2);
        $this->openRequestStream($adapter, 3);
        // Keep a competing body in pendingResponses so non-HTML cannot solo-drain.
        $adapter->beginStreamingResponse(3, "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n");
        $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));
        self::assertTrue($adapter->hasPendingResponseData());

        $frames = $adapter->encodeSimpleResponse(
            1,
            200,
            ['content-type' => 'text/plain'],
            \str_repeat('z', $bodyBytes)
        );
        self::assertLessThanOrEqual(
            262144,
            $this->dataPayloadBytes($frames),
            'one encode pass must stay within the bounded H2 drain quantum when other streams compete'
        );
        self::assertTrue(
            $adapter->hasPendingResponseData(),
            'the remaining body must stay in the adapter so multiplexed streams can be admitted'
        );
        self::assertNull($adapter->exclusiveDocumentStreamId());
    }

    public function testSoloPendingStreamDrainsBeyondOneQuantumToAvoidHalfRenderedHtml(): void
    {
        $bodyBytes = 600000;
        $adapter = $this->openAdapterWithWindow($bodyBytes);
        $frames = $adapter->encodeSimpleResponse(
            1,
            200,
            ['content-type' => 'text/html'],
            \str_repeat('h', $bodyBytes)
        );
        self::assertGreaterThan(
            262144,
            $this->dataPayloadBytes($frames),
            'a lone document body must not stop after the first drain quantum'
        );
        self::assertFalse(
            $adapter->hasPendingResponseData(),
            'solo homepage-sized bodies should finish inside encode when window allows'
        );
    }

    public function testDrainPendingResponseDataContinuesWithoutExtraWindowUpdate(): void
    {
        $bodyBytes = 600000;
        $adapter = $this->openAdapterWithWindow($bodyBytes);
        $adapter->beginStreamingResponse(1, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n");
        $adapter->appendStreamingData(1, \str_repeat('q', $bodyBytes));
        self::assertTrue($adapter->hasPendingResponseData());

        $batches = 0;
        while ($adapter->hasPendingResponseData() && $batches < 16) {
            $batch = $adapter->drainPendingResponseData();
            self::assertNotSame('', $batch, 'drain must progress while send window remains');
            $batches++;
        }
        self::assertFalse($adapter->hasPendingResponseData());
        self::assertGreaterThanOrEqual(2, $batches);
    }

    public function testDrainRotatesPendingStreamsBeforeUsingTheWholeQuantum(): void
    {
        $bodyBytes = 600000;
        $adapter = $this->openAdapterWithWindow(1200000);
        $this->openRequestStream($adapter, 3);

        self::assertNotSame(
            '',
            $adapter->beginStreamingResponse(
                1,
                "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n"
            )
        );
        self::assertNotSame(
            '',
            $adapter->beginStreamingResponse(
                3,
                "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n"
            )
        );
        $adapter->appendStreamingData(1, \str_repeat('h', $bodyBytes));
        $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));

        $batch = $adapter->drainPendingResponseData();
        self::assertContains(1, $this->dataStreamIds($batch));
        self::assertContains(
            3,
            $this->dataStreamIds($batch),
            'a later asset stream must receive a DATA frame before the HTML stream consumes the full drain quantum'
        );
    }

    public function testExhaustedConnectionWindowQueuesLaterResponseInsteadOfRefusingIt(): void
    {
        $adapter = new ConnectionAdapter();
        $authority = 'example.test';
        $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
        $adapter->receive(
            FrameCodec::CLIENT_CONNECTION_PREFACE
            . FrameCodec::settings([])
            . FrameCodec::encode(
                FrameCodec::TYPE_HEADERS,
                FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM,
                1,
                $headerBlock,
            )
        );

        // The browser's default connection window is 65535 bytes. A large
        // document consumes it before the next asset response is encoded.
        $adapter->encodeSimpleResponse(
            1,
            200,
            ['content-type' => 'text/html'],
            \str_repeat('h', 100000),
        );
        $this->openRequestStream($adapter, 3);

        $assetFrames = $adapter->encodeSimpleResponse(
            3,
            200,
            ['content-type' => 'text/javascript'],
            'asset',
        );

        self::assertNotSame('', $assetFrames, 'the asset response headers must still be emitted');
        self::assertNotContains(FrameCodec::TYPE_RST_STREAM, $this->frameTypes($assetFrames));
        self::assertNotContains(FrameCodec::TYPE_GOAWAY, $this->frameTypes($assetFrames));
        self::assertTrue($adapter->hasPendingResponseData());
        self::assertSame(5, $adapter->pendingResponseBytes(3));

        $windowFrames = $adapter->receive(
            FrameCodec::windowUpdate(0, 65535)
            . FrameCodec::windowUpdate(1, 65535)
        );
        self::assertNotContains(FrameCodec::TYPE_RST_STREAM, $this->frameTypes($windowFrames['write']));
        self::assertNotContains(FrameCodec::TYPE_GOAWAY, $this->frameTypes($windowFrames['write']));
        self::assertFalse($adapter->hasPendingResponseData());
    }

    public function testWorkerSslArmsPendingHttp2WritesBeforeSelect(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/bin/worker_ssl.php'
        );
        self::assertStringContainsString('function wlsSslArmHttp2PendingResponseWrites', $source);
        self::assertStringContainsString('wlsSslArmHttp2PendingResponseWrites(', $source);
        self::assertStringContainsString(
            'peer with spare flow-control window will not send WINDOW_UPDATE',
            $source
        );
        self::assertStringContainsString(
            'Refill whenever the socket-facing buffer is below the watermark',
            $source
        );
        self::assertStringContainsString(
            'exclusiveDocumentStreamId',
            $source
        );
        self::assertStringContainsString(
            'Incomplete local bodies are connection response state',
            $source
        );
        self::assertStringContainsString(
            'But already-parsed streams in http2PendingRequests still need',
            $source
        );
        self::assertStringContainsString(
            'if (empty($http2PendingRequests[$connId]))',
            $source
        );
    }

    private function openAdapterWithWindow(int $windowBytes): ConnectionAdapter
    {
        $adapter = new ConnectionAdapter();
        $authority = 'example.test';
        $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
        $adapter->receive(
            FrameCodec::CLIENT_CONNECTION_PREFACE
            . FrameCodec::settings([
                FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => $windowBytes,
            ])
            . FrameCodec::encode(
                FrameCodec::TYPE_HEADERS,
                FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM,
                1,
                $headerBlock,
            )
        );
        $adapter->receive(FrameCodec::windowUpdate(0, $windowBytes));

        return $adapter;
    }

    private function openRequestStream(ConnectionAdapter $adapter, int $streamId): void
    {
        $authority = 'example.test';
        $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
        $adapter->receive(
            FrameCodec::encode(
                FrameCodec::TYPE_HEADERS,
                FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM,
                $streamId,
                $headerBlock,
            )
        );
    }

    /** @return list<int> */
    private function dataStreamIds(string $frames): array
    {
        $streamIds = [];
        for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
            $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
            $frameLength = ((int)($header['length1'] ?? 0) << 16)
                | ((int)($header['length2'] ?? 0) << 8)
                | (int)($header['length3'] ?? 0);
            if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
                break;
            }
            if ((int)($header['type'] ?? -1) === FrameCodec::TYPE_DATA) {
                $streamIds[] = ((int)($header['stream'] ?? 0)) & 0x7fffffff;
            }
            $offset += 9 + $frameLength;
        }

        return $streamIds;
    }

    private function dataPayloadBytes(string $frames): int
    {
        $bytes = 0;
        for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
            $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
            $frameLength = ((int)($header['length1'] ?? 0) << 16)
                | ((int)($header['length2'] ?? 0) << 8)
                | (int)($header['length3'] ?? 0);
            if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
                break;
            }
            if ((int)($header['type'] ?? -1) === FrameCodec::TYPE_DATA) {
                $bytes += $frameLength;
            }
            $offset += 9 + $frameLength;
        }

        return $bytes;
    }

    /** @return list<int> */
    private function frameTypes(string $frames): array
    {
        $types = [];
        for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
            $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
            $frameLength = ((int)($header['length1'] ?? 0) << 16)
                | ((int)($header['length2'] ?? 0) << 8)
                | (int)($header['length3'] ?? 0);
            if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
                break;
            }
            $types[] = (int)($header['type'] ?? -1);
            $offset += 9 + $frameLength;
        }

        return $types;
    }
}
