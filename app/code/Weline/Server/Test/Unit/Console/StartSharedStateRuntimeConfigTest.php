<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartSharedStateRuntimeConfigTest extends TestCase
{
    public function testImplicitRuntimeGeneratedTokenIsRebasedToCurrentInstance(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            'codex-bg-tail5',
            'session_server.codex-bg-tail4.token',
            'session_server.token',
            false
        );

        self::assertSame('session_server.codex-bg-tail5.token', $token);
    }

    public function testImplicitRuntimeGeneratedTokenFallsBackToDefaultInstanceName(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            'default',
            'memory_server.codex-bg-tail4.token',
            'memory_server.token',
            false
        );

        self::assertSame('memory_server.token', $token);
    }

    public function testExplicitTokenNameIsPreserved(): void
    {
        $start = new Start();

        $token = $this->invokeProtected(
            $start,
            'resolveSharedStateTokenFileName',
            'codex-bg-tail5',
            'session_server.cluster-a.token',
            'session_server.token',
            true
        );

        self::assertSame('session_server.cluster-a.token', $token);
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
                'session_server_token_file_name' => 'session_server.codex-bg-tail4.token',
                'memory_server_port' => 19976,
                'memory_server_token_file_name' => 'memory_server.codex-bg-tail4.token',
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
