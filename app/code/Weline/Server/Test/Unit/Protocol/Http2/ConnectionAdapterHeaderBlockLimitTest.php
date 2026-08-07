<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Protocol\Http2;

use PHPUnit\Framework\TestCase;
use Weline\Server\Protocol\Http2\ConnectionAdapter;
use Weline\Server\Protocol\Http2\FrameCodec;

final class ConnectionAdapterHeaderBlockLimitTest extends TestCase
{
    public function testContinuationFramesCannotGrowCompressedHeaderBlockWithoutBound(): void
    {
        $adapter = new ConnectionAdapter();
        $chunk = \str_repeat("\0", FrameCodec::DEFAULT_MAX_FRAME_SIZE);
        $initial = $adapter->receive(
            FrameCodec::CLIENT_CONNECTION_PREFACE
            . FrameCodec::settings()
            . FrameCodec::encode(
                FrameCodec::TYPE_HEADERS,
                FrameCodec::FLAG_END_STREAM,
                1,
                $chunk,
            ),
        );
        self::assertSame('incomplete', $initial['status']);

        for ($index = 0; $index < 3; ++$index) {
            $accepted = $adapter->receive(FrameCodec::encode(
                FrameCodec::TYPE_CONTINUATION,
                0,
                1,
                $chunk,
            ));
            self::assertSame('incomplete', $accepted['status']);
        }

        $rejected = $adapter->receive(FrameCodec::encode(
            FrameCodec::TYPE_CONTINUATION,
            0,
            1,
            $chunk,
        ));

        self::assertSame('error', $rejected['status']);
        self::assertSame('header_block_too_large', $rejected['error']);
        $goaway = FrameCodec::decodeOne((string)$rejected['write']);
        self::assertSame('frame', $goaway['status']);
        self::assertSame(FrameCodec::TYPE_GOAWAY, $goaway['type']);
    }
}
