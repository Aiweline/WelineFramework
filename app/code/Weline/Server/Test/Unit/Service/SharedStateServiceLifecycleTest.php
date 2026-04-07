<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;

/**
 * 共享状态侧车：acquire/release/sweep 已简化为 ensure 兼容壳，本组用例对齐当前契约。
 */
final class SharedStateServiceLifecycleTest extends TestCase
{
    public function testAcquireDelegatesToEnsureWhenRuntimeMissing(): void
    {
        $manager = new class extends SharedStateServiceManager {
            /** @var list<array{0:string,1:array,2:array,3:string,4:bool}> */
            public array $ensureInvocations = [];

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureInvocations[] = [$role, $config, $envConfig, $requesterInstanceName, $frontend];

                return ['shared_service' => true, 'port' => 7];
            }
        };

        $runtime = $manager->acquire(ControlMessage::ROLE_SESSION_SERVER, 'my-consumer', [
            'env_config' => ['wls' => []],
        ]);

        self::assertSame(7, $runtime['port'] ?? null);
        self::assertCount(1, $manager->ensureInvocations);
        self::assertSame(ControlMessage::ROLE_SESSION_SERVER, $manager->ensureInvocations[0][0]);
        self::assertSame('my-consumer', $manager->ensureInvocations[0][3]);
    }

    public function testAcquireReturnsRuntimeFromOptionsWithoutCallingEnsure(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $ensureCalls = 0;

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls++;

                return [];
            }
        };

        $cached = ['port' => 9, 'reuse_existing' => true];
        $out = $manager->acquire(ControlMessage::ROLE_SESSION_SERVER, 'c', ['runtime' => $cached]);

        self::assertSame($cached, $out);
        self::assertSame(0, $manager->ensureCalls);
    }

    public function testSweepStaleConsumersReturnsEmptyRemovedWithPeekRecord(): void
    {
        $manager = new SharedStateServiceManager();
        $result = $manager->sweepStaleConsumers(ControlMessage::ROLE_SESSION_SERVER);

        self::assertSame([], $result['removed']);
        self::assertArrayHasKey('port', $result['record']);
        self::assertSame(ControlMessage::ROLE_SESSION_SERVER, $result['role']);
    }

    public function testSweepStaleConsumersIfAvailableDoesNotSkipLock(): void
    {
        $manager = new SharedStateServiceManager();
        $result = $manager->sweepStaleConsumersIfAvailable(ControlMessage::ROLE_SESSION_SERVER);

        self::assertFalse($result['skipped_locked'] ?? true);
        self::assertSame([], $result['removed']);
        self::assertArrayHasKey('port', $result['record']);
    }
}
