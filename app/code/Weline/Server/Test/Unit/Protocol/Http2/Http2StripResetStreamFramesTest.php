<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class Http2StripResetStreamFramesTest extends TestCase
{
    public function testResponseCleanupPreservesTheServerResetThatRejectsAStream(): void
    {
        $reset = FrameCodec::rstStream(3, FrameCodec::ERROR_ENHANCE_YOUR_CALM);
        $connectionControl = FrameCodec::windowUpdate(0, 100);
        $liveData = FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_END_STREAM, 5, 'ok');
        $buffer = FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 3, \str_repeat('x', 100))
            . $reset . $connectionControl . $liveData;

        foreach ([FrameCodec::stripStreamFrames($buffer, [3]), FrameCodec::retainStreamFrames($buffer, [5])] as [$kept, $removed]) {
            self::assertSame(100, $removed);
            self::assertSame($reset . $connectionControl . $liveData, $kept);
        }
    }

    public function testStripStreamFramesRemovesDataAndCreditsWindow(): void
    {
        $buffer = FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 3, \str_repeat('a', 100))
            . FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS, 5, 'hdr')
            . FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 5, \str_repeat('b', 50))
            . FrameCodec::settingsAck();

        [$kept, $removed] = FrameCodec::stripStreamFrames($buffer, [3, 5]);
        self::assertSame(150, $removed);
        self::assertSame(FrameCodec::settingsAck(), $kept);

        $adapter = new ConnectionAdapter();
        $before = (int)$adapter->diagnostics()['connection_send_window'];
        $adapter->creditConnectionSendWindow(150);
        self::assertSame($before + 150, (int)$adapter->diagnostics()['connection_send_window']);
    }

    public function testRetainStreamFramesKeepsOnlyAllowlistedStreams(): void
    {
        $buffer = FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 3, \str_repeat('a', 40))
            . FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 7, \str_repeat('c', 20))
            . FrameCodec::settingsAck();
        [$kept, $removed] = FrameCodec::retainStreamFrames($buffer, [7]);
        self::assertSame(40, $removed);
        self::assertSame(
            FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 7, \str_repeat('c', 20)) . FrameCodec::settingsAck(),
            $kept
        );
        self::assertSame(
            9 + 20,
            FrameCodec::completeFramesPrefixLength(
                FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 7, \str_repeat('c', 20)) . 'x'
            )
        );
    }

    public function testNewDocumentDoesNotDiscardAnEarlierResponseWaitingInTheWriteQueue(): void
    {
        $earlierDocument = FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_END_STREAM, 3, 'remaining body');
        $cancelledAsset = FrameCodec::encode(FrameCodec::TYPE_DATA, 0, 7, 'cancelled body');
        $newDocument = FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS, 5, 'headers');
        $buffer = $earlierDocument . $cancelledAsset . $newDocument;

        self::assertSame(
            [$earlierDocument . $newDocument, \strlen('cancelled body')],
            FrameCodec::stripStreamFrames($buffer, [7]),
        );
    }

    public function testWorkerScrubsWriteBufferOnResetStreams(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/bin/worker_ssl.php'
        );
        self::assertStringContainsString('FrameCodec::stripStreamFrames', $source);
        self::assertStringNotContainsString('FrameCodec::retainStreamFrames', $source);
        self::assertStringContainsString('completeFramesPrefixLength', $source);
        self::assertStringContainsString('creditConnectionSendWindow', $source);
    }
}
