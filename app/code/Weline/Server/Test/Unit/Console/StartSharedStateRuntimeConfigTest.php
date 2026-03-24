<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

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

    public function testResolveSharedStateRuntimeConfigReusesExistingSharedSidecar(): void
    {
        $start = new class extends Start {
            protected function inspectReusableSharedStateService(int $port, string $expectedRole, string $defaultTokenFileName): array
            {
                if ($expectedRole !== 'session_server') {
                    return ['reusable' => false];
                }

                return [
                    'reusable' => true,
                    'pid' => 4321,
                    'port' => $port,
                    'role' => 'session_server',
                    'token_file_name' => 'session_server.owner.token',
                    'process_name' => 'weline-wls-session-owner',
                ];
            }
        };

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
        self::assertSame('session_server.owner.token', $runtime['session']['token_file_name']);
        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertSame(4321, $runtime['session']['pid']);
        self::assertSame('weline-wls-session-owner', $runtime['session']['process_name']);
        self::assertFalse((bool) ($runtime['memory']['reuse_existing'] ?? false));
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
