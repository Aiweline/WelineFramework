<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\SharedStateServiceManager;

final class StartSharedStateRuntimeConfigTest extends TestCase
{
    public function testImplicitRuntimeGeneratedTokenIsRebasedToSharedPortIdentity(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            19975,
            'session_server.codex-bg-tail4.token',
            'session_server.token',
            false,
            19970
        );

        self::assertSame('session_server.19975.token', $token);
    }

    public function testImplicitRuntimeGeneratedTokenFallsBackToDefaultPortTokenName(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            19971,
            'memory_server.codex-bg-tail4.token',
            'memory_server.token',
            false,
            19971
        );

        self::assertSame('memory_server.token', $token);
    }

    public function testExplicitTokenNameIsPreserved(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            19975,
            'session_server.cluster-a.token',
            'session_server.token',
            true,
            19970
        );

        self::assertSame('session_server.cluster-a.token', $token);
    }

    public function testResolveSharedStateRuntimeConfigDelegatesToIndependentSharedServiceManager(): void
    {
        $manager = new class extends SharedStateServiceManager {
            /**
             * @param array<string, mixed> $config
             * @param array<string, mixed> $envConfig
             * @return array{
             *   session: array<string, mixed>,
             *   memory: array<string, mixed>
             * }
             */
            public function ensureRuntime(string $requesterInstanceName, array $config, array $envConfig = []): array
            {
                return [
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 19970,
                        'token_file_name' => 'session_server.shared.token',
                        'reuse_existing' => true,
                        'pid' => 4321,
                        'process_name' => 'weline-wls-session-shared-19970',
                        'instance_name' => 'shared-session-19970',
                        'independent' => true,
                    ],
                    'memory' => [
                        'host' => '127.0.0.1',
                        'port' => 19971,
                        'token_file_name' => 'memory_server.shared.token',
                        'reuse_existing' => false,
                        'created_now' => true,
                        'pid' => 9876,
                        'process_name' => 'weline-wls-memory-shared-19971',
                        'instance_name' => 'shared-memory-19971',
                        'independent' => true,
                    ],
                ];
            }
        };

        $start = new class extends Start {
            public SharedStateServiceManager $manager;

            protected function createSharedStateServiceManager(): SharedStateServiceManager
            {
                return $this->manager;
            }
        };
        $start->manager = $manager;

        $runtime = $this->invokeProtected(
            $start,
            'resolveSharedStateRuntimeConfig',
            'consumer',
            [
                'session_server_port' => 19970,
                'memory_server_port' => 19971,
            ],
            false
        );

        self::assertSame(19970, $runtime['session']['port']);
        self::assertSame('session_server.shared.token', $runtime['session']['token_file_name']);
        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertSame(4321, $runtime['session']['pid']);
        self::assertSame('weline-wls-session-shared-19970', $runtime['session']['process_name']);
        self::assertSame('shared-session-19970', $runtime['session']['instance_name']);
        self::assertTrue((bool) ($runtime['memory']['created_now'] ?? false));
        self::assertSame('shared-memory-19971', $runtime['memory']['instance_name']);
    }

    public function testSavedConfigStripsRuntimeOnlySharedStateKeys(): void
    {
        $start = new Start();

        $filtered = $this->invokeProtected(
            $start,
            'stripRuntimeOnlySavedInstanceConfig',
            [
                'host' => '127.0.0.1',
                'port' => 9982,
                'session_server_port' => 19975,
                'session_server_token_file_name' => 'session_server.19975.token',
                'memory_server_port' => 19976,
                'memory_server_token_file_name' => 'memory_server.19976.token',
            ]
        );

        self::assertSame(
            [
                'host' => '127.0.0.1',
                'port' => 9982,
            ],
            $filtered
        );
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
