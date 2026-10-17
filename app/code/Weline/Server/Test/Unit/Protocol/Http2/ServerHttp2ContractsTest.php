<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllServerHttp2ContractsPass().
 */
final class ServerHttp2ContractsTest extends TestCase
{
    public function testAllServerHttp2ContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'Http2DrainWithoutWindowUpdate::testHtmlDocumentDrainsBeyondQuantumEvenWhenOtherStreamsCompete' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testHtmlDocumentDrainsBeyondQuantumEvenWhenOtherStreamsCompete();
        },
            'Http2DrainWithoutWindowUpdate::testEncodeKeepsLargeBodyBoundedForMultiplexFairness' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testEncodeKeepsLargeBodyBoundedForMultiplexFairness();
        },
            'Http2DrainWithoutWindowUpdate::testSoloPendingStreamDrainsBeyondOneQuantumToAvoidHalfRenderedHtml' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testSoloPendingStreamDrainsBeyondOneQuantumToAvoidHalfRenderedHtml();
        },
            'Http2DrainWithoutWindowUpdate::testDrainPendingResponseDataContinuesWithoutExtraWindowUpdate' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testDrainPendingResponseDataContinuesWithoutExtraWindowUpdate();
        },
            'Http2DrainWithoutWindowUpdate::testDrainRotatesPendingStreamsBeforeUsingTheWholeQuantum' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testDrainRotatesPendingStreamsBeforeUsingTheWholeQuantum();
        },
            'Http2DrainWithoutWindowUpdate::testExhaustedConnectionWindowQueuesLaterResponseInsteadOfRefusingIt' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testExhaustedConnectionWindowQueuesLaterResponseInsteadOfRefusingIt();
        },
            'Http2DrainWithoutWindowUpdate::testWorkerSslArmsPendingHttp2WritesBeforeSelect' => function () {
            $this->assert_Http2DrainWithoutWindowUpdate_testWorkerSslArmsPendingHttp2WritesBeforeSelect();
        },
            'Http2ReadWhilePending::testWorkerSslAlwaysReadsHttp2SocketIndependentOfPendingQueue' => function () {
            $this->assert_Http2ReadWhilePending_testWorkerSslAlwaysReadsHttp2SocketIndependentOfPendingQueue();
        },
        ];
    }

private function assert_Http2DrainWithoutWindowUpdate_testHtmlDocumentDrainsBeyondQuantumEvenWhenOtherStreamsCompete(): void
{
    $bodyBytes = 600000;
    $adapter = $this->openAdapterWithWindow_Http2DrainWithoutWindowUpdate($bodyBytes * 2);
    $this->openRequestStream_Http2DrainWithoutWindowUpdate($adapter, 3);
    $adapter->beginStreamingResponse(3, "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n");
    $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));
    self::assertTrue($adapter->hasPendingResponseData());
    $frames = $adapter->encodeSimpleResponse(1, 200, ['content-type' => 'text/html; charset=utf-8'], \str_repeat('h', $bodyBytes));
    self::assertGreaterThan(262144, $this->dataPayloadBytes_Http2DrainWithoutWindowUpdate($frames), 'text/html must keep draining past one quantum even with competing streams');
    if ($adapter->pendingResponseBytes(1) > 0) {
        self::assertSame(1, $adapter->exclusiveDocumentStreamId(), 'unfinished HTML remains connection response state for continued scheduling');
    } else {
        self::assertNull($adapter->exclusiveDocumentStreamId());
    }
}

private function assert_Http2DrainWithoutWindowUpdate_testEncodeKeepsLargeBodyBoundedForMultiplexFairness(): void
{
    $bodyBytes = 600000;
    $adapter = $this->openAdapterWithWindow_Http2DrainWithoutWindowUpdate($bodyBytes * 2);
    $this->openRequestStream_Http2DrainWithoutWindowUpdate($adapter, 3);
    // Keep a competing body in pendingResponses so non-HTML cannot solo-drain.
    $adapter->beginStreamingResponse(3, "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n");
    $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));
    self::assertTrue($adapter->hasPendingResponseData());
    $frames = $adapter->encodeSimpleResponse(1, 200, ['content-type' => 'text/plain'], \str_repeat('z', $bodyBytes));
    self::assertLessThanOrEqual(262144, $this->dataPayloadBytes_Http2DrainWithoutWindowUpdate($frames), 'one encode pass must stay within the bounded H2 drain quantum when other streams compete');
    self::assertTrue($adapter->hasPendingResponseData(), 'the remaining body must stay in the adapter so multiplexed streams can be admitted');
    self::assertNull($adapter->exclusiveDocumentStreamId());
}

private function assert_Http2DrainWithoutWindowUpdate_testSoloPendingStreamDrainsBeyondOneQuantumToAvoidHalfRenderedHtml(): void
{
    $bodyBytes = 600000;
    $adapter = $this->openAdapterWithWindow_Http2DrainWithoutWindowUpdate($bodyBytes);
    $frames = $adapter->encodeSimpleResponse(1, 200, ['content-type' => 'text/html'], \str_repeat('h', $bodyBytes));
    self::assertGreaterThan(262144, $this->dataPayloadBytes_Http2DrainWithoutWindowUpdate($frames), 'a lone document body must not stop after the first drain quantum');
    self::assertFalse($adapter->hasPendingResponseData(), 'solo homepage-sized bodies should finish inside encode when window allows');
}

private function assert_Http2DrainWithoutWindowUpdate_testDrainPendingResponseDataContinuesWithoutExtraWindowUpdate(): void
{
    $bodyBytes = 600000;
    $adapter = $this->openAdapterWithWindow_Http2DrainWithoutWindowUpdate($bodyBytes);
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

private function assert_Http2DrainWithoutWindowUpdate_testDrainRotatesPendingStreamsBeforeUsingTheWholeQuantum(): void
{
    $bodyBytes = 600000;
    $adapter = $this->openAdapterWithWindow_Http2DrainWithoutWindowUpdate(1200000);
    $this->openRequestStream_Http2DrainWithoutWindowUpdate($adapter, 3);
    self::assertNotSame('', $adapter->beginStreamingResponse(1, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n"));
    self::assertNotSame('', $adapter->beginStreamingResponse(3, "HTTP/1.1 200 OK\r\nContent-Type: text/javascript\r\n\r\n"));
    $adapter->appendStreamingData(1, \str_repeat('h', $bodyBytes));
    $adapter->appendStreamingData(3, \str_repeat('j', $bodyBytes));
    $batch = $adapter->drainPendingResponseData();
    self::assertContains(1, $this->dataStreamIds_Http2DrainWithoutWindowUpdate($batch));
    self::assertContains(3, $this->dataStreamIds_Http2DrainWithoutWindowUpdate($batch), 'a later asset stream must receive a DATA frame before the HTML stream consumes the full drain quantum');
}

private function assert_Http2DrainWithoutWindowUpdate_testExhaustedConnectionWindowQueuesLaterResponseInsteadOfRefusingIt(): void
{
    $adapter = new ConnectionAdapter();
    $authority = 'example.test';
    $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
    $adapter->receive(FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings([]) . FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM, 1, $headerBlock));
    // The browser's default connection window is 65535 bytes. A large
    // document consumes it before the next asset response is encoded.
    $adapter->encodeSimpleResponse(1, 200, ['content-type' => 'text/html'], \str_repeat('h', 100000));
    $this->openRequestStream_Http2DrainWithoutWindowUpdate($adapter, 3);
    $assetFrames = $adapter->encodeSimpleResponse(3, 200, ['content-type' => 'text/javascript'], 'asset');
    self::assertNotSame('', $assetFrames, 'the asset response headers must still be emitted');
    self::assertNotContains(FrameCodec::TYPE_RST_STREAM, $this->frameTypes_Http2DrainWithoutWindowUpdate($assetFrames));
    self::assertNotContains(FrameCodec::TYPE_GOAWAY, $this->frameTypes_Http2DrainWithoutWindowUpdate($assetFrames));
    self::assertTrue($adapter->hasPendingResponseData());
    self::assertSame(5, $adapter->pendingResponseBytes(3));
    $windowFrames = $adapter->receive(FrameCodec::windowUpdate(0, 65535) . FrameCodec::windowUpdate(1, 65535));
    self::assertNotContains(FrameCodec::TYPE_RST_STREAM, $this->frameTypes_Http2DrainWithoutWindowUpdate($windowFrames['write']));
    self::assertNotContains(FrameCodec::TYPE_GOAWAY, $this->frameTypes_Http2DrainWithoutWindowUpdate($windowFrames['write']));
    self::assertFalse($adapter->hasPendingResponseData());
}

private function assert_Http2DrainWithoutWindowUpdate_testWorkerSslArmsPendingHttp2WritesBeforeSelect(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/worker_ssl.php');
    self::assertStringContainsString('function wlsSslArmHttp2PendingResponseWrites', $source);
    self::assertStringContainsString('wlsSslArmHttp2PendingResponseWrites(', $source);
    self::assertStringContainsString('peer with spare flow-control window will not send WINDOW_UPDATE', $source);
    self::assertStringContainsString('Refill whenever the socket-facing buffer is below the watermark', $source);
    self::assertStringContainsString('exclusiveDocumentStreamId', $source);
    self::assertStringContainsString('Incomplete local bodies are connection response state', $source);
    self::assertStringContainsString('But already-parsed streams in http2PendingRequests still need', $source);
    self::assertStringContainsString('if (empty($http2PendingRequests[$connId]))', $source);
}

private function openAdapterWithWindow_Http2DrainWithoutWindowUpdate(int $windowBytes): ConnectionAdapter
{
    $adapter = new ConnectionAdapter();
    $authority = 'example.test';
    $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
    $adapter->receive(FrameCodec::CLIENT_CONNECTION_PREFACE . FrameCodec::settings([FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => $windowBytes]) . FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM, 1, $headerBlock));
    $adapter->receive(FrameCodec::windowUpdate(0, $windowBytes));
    return $adapter;
}

private function openRequestStream_Http2DrainWithoutWindowUpdate(ConnectionAdapter $adapter, int $streamId): void
{
    $authority = 'example.test';
    $headerBlock = "\x82\x84\x87\x01" . \chr(\strlen($authority)) . $authority;
    $adapter->receive(FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS | FrameCodec::FLAG_END_STREAM, $streamId, $headerBlock));
}

/** @return list<int> */
private function dataStreamIds_Http2DrainWithoutWindowUpdate(string $frames): array
{
    $streamIds = [];
    for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
        $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
        $frameLength = (int) ($header['length1'] ?? 0) << 16 | (int) ($header['length2'] ?? 0) << 8 | (int) ($header['length3'] ?? 0);
        if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
            break;
        }
        if ((int) ($header['type'] ?? -1) === FrameCodec::TYPE_DATA) {
            $streamIds[] = (int) ($header['stream'] ?? 0) & 0x7fffffff;
        }
        $offset += 9 + $frameLength;
    }
    return $streamIds;
}

private function dataPayloadBytes_Http2DrainWithoutWindowUpdate(string $frames): int
{
    $bytes = 0;
    for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
        $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
        $frameLength = (int) ($header['length1'] ?? 0) << 16 | (int) ($header['length2'] ?? 0) << 8 | (int) ($header['length3'] ?? 0);
        if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
            break;
        }
        if ((int) ($header['type'] ?? -1) === FrameCodec::TYPE_DATA) {
            $bytes += $frameLength;
        }
        $offset += 9 + $frameLength;
    }
    return $bytes;
}

/** @return list<int> */
private function frameTypes_Http2DrainWithoutWindowUpdate(string $frames): array
{
    $types = [];
    for ($offset = 0, $length = \strlen($frames); $offset + 9 <= $length;) {
        $header = \unpack('C3length/Ctype/Cflags/Nstream', \substr($frames, $offset, 9));
        $frameLength = (int) ($header['length1'] ?? 0) << 16 | (int) ($header['length2'] ?? 0) << 8 | (int) ($header['length3'] ?? 0);
        if ($frameLength < 0 || $offset + 9 + $frameLength > $length) {
            break;
        }
        $types[] = (int) ($header['type'] ?? -1);
        $offset += 9 + $frameLength;
    }
    return $types;
}

private function assert_Http2ReadWhilePending_testWorkerSslAlwaysReadsHttp2SocketIndependentOfPendingQueue(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/worker_ssl.php');
    self::assertStringContainsString('HTTP/2 must ALWAYS read the TLS socket while the connection is selected', $source);
    self::assertStringContainsString('Backpressure must use socket-facing writeBytes only', $source);
    self::assertStringNotContainsString('$http2QueuedResponseBytes < $http2AdmissionWriteHighWatermark', $source);
    self::assertStringNotContainsString('$http2QueuedResponseBytes >= $http2AdmissionWriteHighWatermark', $source);
    self::assertStringNotContainsString('!$hasPendingHttp2Request || $http2NeedsFlowControlRead', $source);
    self::assertStringContainsString('$http2TimeoutAdapter->hasActiveStreams()', $source);
    self::assertStringContainsString('But already-parsed streams in http2PendingRequests still need', $source);
    self::assertStringContainsString('HTTP/2 must stay readable even when a false long-lived mark', $source);
    self::assertStringContainsString('($connectionProtocols[$connId] ?? \'\') !== \'h2\'', $source);
    self::assertStringContainsString('Application gate may pause new Fiber admission, but HTTP/2 still needs', $source);
    self::assertStringContainsString('HTTP/2 `$response` is binary frames', $source);
}
}
