<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

final class ControlMessageMonotonicPingPongTest extends TestCase
{
    private const BOOT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testPingAndPongCarryComparableMonotonicEvidenceWhileRetainingWallAuditFields(): void
    {
        $ping = ControlMessage::decode(ControlMessage::ping(
            timestamp: 1_725_000_000.25,
            monotonicTimestamp: 100.5,
            hostBootId: self::BOOT_ID,
        ));

        self::assertIsArray($ping);
        self::assertSame(1_725_000_000.25, $ping['timestamp'] ?? null);
        self::assertSame(1_725_000_000.25, $ping['wall_timestamp'] ?? null);
        self::assertSame(100.5, $ping['monotonic_timestamp'] ?? null);
        self::assertSame(self::BOOT_ID, $ping['host_boot_id'] ?? null);

        $pong = ControlMessage::decode(ControlMessage::pongForPing(
            $ping,
            ['active_fibers' => 2],
            pongMonotonicTimestamp: 100.75,
            pongHostBootId: self::BOOT_ID,
            pongWallTimestamp: 1_725_000_000.5,
        ));

        self::assertIsArray($pong);
        self::assertSame(1_725_000_000.25, $pong['ping_timestamp'] ?? null);
        self::assertSame(1_725_000_000.5, $pong['pong_timestamp'] ?? null);
        self::assertSame(100.5, $pong['ping_monotonic'] ?? null);
        self::assertSame(100.75, $pong['pong_monotonic'] ?? null);
        self::assertSame(self::BOOT_ID, $pong['ping_host_boot_id'] ?? null);
        self::assertSame(self::BOOT_ID, $pong['pong_host_boot_id'] ?? null);

        self::assertSame([
            'ping_monotonic' => 100.5,
            'pong_monotonic' => 100.75,
            'received_monotonic' => 101.0,
            'rtt_seconds' => 0.25,
            'host_boot_id' => self::BOOT_ID,
        ], ControlMessage::monotonicPongObservation($pong, 101.0, self::BOOT_ID));
    }

    public function testLegacyPongRemainsDecodableButCannotRefreshHealth(): void
    {
        $legacy = ControlMessage::decode(ControlMessage::pong(1_725_000_000.25));

        self::assertIsArray($legacy);
        self::assertArrayHasKey('ping_timestamp', $legacy);
        self::assertArrayHasKey('pong_timestamp', $legacy);
        self::assertNull(ControlMessage::monotonicPongObservation($legacy, 101.0, self::BOOT_ID));
    }

    #[DataProvider('invalidPongProvider')]
    public function testInvalidOrIncomparablePongEvidenceFailsClosed(array $pong, float $now, string $bootId): void
    {
        self::assertNull(ControlMessage::monotonicPongObservation($pong, $now, $bootId));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:float,2:string}> */
    public static function invalidPongProvider(): iterable
    {
        $valid = [
            'type' => ControlMessage::TYPE_PONG,
            'ping_monotonic' => 100.0,
            'pong_monotonic' => 100.25,
            'ping_host_boot_id' => self::BOOT_ID,
            'pong_host_boot_id' => self::BOOT_ID,
        ];

        yield 'missing monotonic fields' => [['type' => ControlMessage::TYPE_PONG], 101.0, self::BOOT_ID];
        yield 'non finite ping string' => [[...$valid, 'ping_monotonic' => 'NAN'], 101.0, self::BOOT_ID];
        yield 'non finite pong string' => [[...$valid, 'pong_monotonic' => 'INF'], 101.0, self::BOOT_ID];
        yield 'pong precedes ping' => [[...$valid, 'pong_monotonic' => 99.0], 101.0, self::BOOT_ID];
        yield 'future producer timestamp' => [[...$valid, 'pong_monotonic' => 102.0], 101.0, self::BOOT_ID];
        yield 'future ping timestamp' => [[...$valid, 'ping_monotonic' => 102.0, 'pong_monotonic' => 102.0], 101.0, self::BOOT_ID];
        yield 'ping boot mismatch' => [[...$valid, 'ping_host_boot_id' => \str_repeat('b', 64)], 101.0, self::BOOT_ID];
        yield 'pong boot mismatch' => [[...$valid, 'pong_host_boot_id' => \str_repeat('b', 64)], 101.0, self::BOOT_ID];
        yield 'current boot invalid' => [$valid, 101.0, 'legacy-boot'];
        yield 'now non positive' => [$valid, 0.0, self::BOOT_ID];
    }
}
