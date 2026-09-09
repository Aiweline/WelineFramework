<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class Http2StripResetStreamFramesTest extends TestCase
{
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

    public function testWorkerScrubsWriteBufferOnResetStreams(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/bin/worker_ssl.php'
        );
        self::assertStringContainsString('FrameCodec::stripStreamFrames', $source);
        self::assertStringContainsString('FrameCodec::retainStreamFrames', $source);
        self::assertStringContainsString('completeFramesPrefixLength', $source);
        self::assertStringContainsString('creditConnectionSendWindow', $source);
    }
}
