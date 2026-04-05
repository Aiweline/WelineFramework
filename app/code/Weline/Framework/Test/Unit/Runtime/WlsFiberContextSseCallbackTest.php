<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsFiberContext;

final class WlsFiberContextSseCallbackTest extends TestCase
{
    /** @var array<int, resource> */
    private array $streams = [];

    protected function setUp(): void
    {
        SseContext::reset();
        RequestContext::setId(null);
    }

    protected function tearDown(): void
    {
        SseContext::reset();
        RequestContext::setId(null);

        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    public function testRestoreReinstallsCapturedSseWriteCallback(): void
    {
        $stream = $this->createStream();
        $writes = [];

        SseContext::setConnection($stream);
        SseContext::setWriteCallback(static function (string $data) use (&$writes): void {
            $writes[] = ['captured', $data];
        });

        $context = WlsFiberContext::capture();

        SseContext::setWriteCallback(static function (string $data) use (&$writes): void {
            $writes[] = ['stale', $data];
        });

        $context->restore();

        self::assertTrue(SseContext::write('payload'));
        \rewind($stream);

        self::assertSame([['captured', 'payload']], $writes);
        self::assertSame('', (string)\stream_get_contents($stream));
    }

    public function testRestoreClearsStaleWriteCallbackWhenSnapshotHadNone(): void
    {
        $stream = $this->createStream();
        $writes = [];

        SseContext::setConnection($stream);
        $context = WlsFiberContext::capture();

        SseContext::setWriteCallback(static function (string $data) use (&$writes): void {
            $writes[] = $data;
        });

        $context->restore();

        self::assertTrue(SseContext::write('stream-body'));
        \rewind($stream);

        self::assertSame([], $writes);
        self::assertSame('stream-body', (string)\stream_get_contents($stream));
    }

    public function testSetConnectionNullClearsSseFlagsAndWriteCallback(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);
        SseContext::enableSse();
        SseContext::markHeadersSent();
        SseContext::setWriteCallback(static function (string $data): void {
        });

        SseContext::setConnection(null);

        self::assertNull(SseContext::getConnection());
        self::assertFalse(SseContext::isSseEnabled());
        self::assertFalse(SseContext::isHeadersSent());
        self::assertNull(SseContext::getWriteCallback());
    }

    /**
     * @return resource
     */
    private function createStream()
    {
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $this->streams[] = $stream;

        return $stream;
    }
}
