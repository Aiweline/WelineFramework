<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Api\Stream;

final class StreamTest extends TestCase
{
    public function testPersistentProviderIdIsPreserved(): void
    {
        $normalized = $this->normalize([
            'id' => '123',
            'event' => 'progress',
            'data' => ['step' => 3],
        ]);

        self::assertSame('progress', $normalized['event']);
        self::assertSame(['step' => 3], $normalized['data']);
        self::assertSame(123, $normalized['id']);
        self::assertTrue($normalized['has_id']);
        self::assertFalse($normalized['control']);
    }

    public function testSequenceAliasIsPreservedForDurableProviderEvents(): void
    {
        $normalized = $this->normalize([
            'sequence' => 9,
            'event' => 'log',
            'data' => ['line' => 'ready'],
        ]);

        self::assertSame(9, $normalized['id']);
        self::assertTrue($normalized['has_id']);
    }

    public function testControlEventsAreFlaggedForIdlessTransportFrames(): void
    {
        $normalized = $this->normalize([
            'id' => 18,
            'event' => 'runtime_reset',
            'control' => true,
            'data' => ['reason' => 'compacted'],
        ]);

        self::assertTrue($normalized['control']);
        self::assertSame(18, $normalized['id']);
    }

    public function testInvalidPersistentIdFallsBackToLegacyAutoId(): void
    {
        $normalized = $this->normalize([
            'id' => 'not-a-sequence',
            'event' => 'progress',
            'data' => ['step' => 3],
        ]);

        self::assertNull($normalized['id']);
        self::assertFalse($normalized['has_id']);
    }

    public function testTransportHeartbeatMarkerIsRecognizedWithoutBecomingABusinessEvent(): void
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isTransportHeartbeat');

        self::assertTrue($method->invoke($stream, ['transport' => 'heartbeat']));
        self::assertFalse($method->invoke($stream, ['event' => 'heartbeat']));
        self::assertFalse($method->invoke($stream, 'heartbeat'));
    }

    public function testRuntimeTaskChannelDoesNotUseGenericTransportTerminalMarkers(): void
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isRuntimeTaskChannel');

        self::assertTrue($method->invoke($stream, 'runtime_task.events'));
        self::assertFalse($method->invoke($stream, 'legacy.events'));
    }

    /** @return array{event:string, data:mixed, id:int|null, has_id:bool, control:bool} */
    private function normalize(array $event): array
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeStreamEvent');

        /** @var array{event:string, data:mixed, id:int|null, has_id:bool, control:bool} $normalized */
        $normalized = $method->invoke($stream, $event);

        return $normalized;
    }
}
