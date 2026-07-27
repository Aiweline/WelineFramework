<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

/** Plan coverage: WLS01, WLS02, WLS04 protocol and ACK identity. */
final class CacheNamespaceControlMessageTest extends TestCase
{
    public function testWls01NamespaceInvalidationFrameRoundTripsWithoutUsingLegacyClear(): void
    {
        $frame = [
            'schema_version' => 1,
            'operation_id' => 'op-0123456789abcdef',
            'authority_clock' => 9,
            'changes' => [
                ['namespace' => 'website/default', 'generation' => 3],
                ['namespace' => 'website/default/catalog', 'generation' => 7],
            ],
        ];

        $decoded = ControlMessage::decode(ControlMessage::cacheNamespaceInvalidationV1($frame));

        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_CACHE_NAMESPACE_INVALIDATE_V1, $decoded['type']);
        self::assertNotSame(ControlMessage::TYPE_CACHE_CLEAR, $decoded['type']);
        self::assertSame(1, $decoded['schema_version']);
        self::assertSame('op-0123456789abcdef', $decoded['operation_id']);
        self::assertSame(9, $decoded['authority_clock']);
        self::assertSame($frame['changes'], $decoded['changes']);
    }

    public function testWls01AckNormalizesGenerationVectorAndPreservesWorkerIdentity(): void
    {
        $decoded = ControlMessage::decode(ControlMessage::cacheNamespaceInvalidationAckV1(
            [
                'operation_id' => 'op-1',
                'success' => true,
                'applied' => false,
                'authority_clock' => 12,
                'generations' => [
                    'website/default/catalog' => 8,
                    'invalid-negative' => -1,
                    'website/default' => 4,
                    7 => 99,
                ],
                'error_code' => '',
                'error' => '',
            ],
            [
                'client_id' => 31,
                'role' => 'worker',
                'worker_id' => 2,
                'slot_id' => 'worker:2',
                'lease_id' => 'lease-2',
                'slot_generation' => 5,
                'pid' => 43294,
            ],
        ));

        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_CACHE_NAMESPACE_INVALIDATE_ACK_V1, $decoded['type']);
        self::assertTrue($decoded['success']);
        self::assertFalse($decoded['applied']);
        self::assertSame(12, $decoded['authority_clock']);
        self::assertSame(
            [
                'website/default' => 4,
                'website/default/catalog' => 8,
            ],
            $decoded['generations'],
        );
        self::assertSame(2, $decoded['source']['worker_id']);
        self::assertSame('lease-2', $decoded['source']['lease_id']);
        self::assertSame(5, $decoded['source']['slot_generation']);
        self::assertSame(43294, $decoded['source']['pid']);
    }

    public function testWls04LegacyCacheClearAckKeepsStrictEpochAndWorkerIdentity(): void
    {
        $decoded = ControlMessage::decode(ControlMessage::cacheClearAck(
            cacheEpoch: 1_784_776_003_165_952,
            success: true,
            workerId: 1,
            applied: true,
            currentEpoch: 1_784_776_003_165_952,
        ));

        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_CACHE_CLEAR_ACK, $decoded['type']);
        self::assertSame(1_784_776_003_165_952, $decoded['cache_epoch']);
        self::assertSame(1_784_776_003_165_952, $decoded['current_epoch']);
        self::assertSame(1, $decoded['worker_id']);
        self::assertTrue($decoded['success']);
        self::assertTrue($decoded['applied']);
    }
}
