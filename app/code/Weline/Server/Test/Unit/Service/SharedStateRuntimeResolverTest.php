<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateRuntimeResolver;

final class SharedStateRuntimeResolverTest extends TestCase
{
    public function testResolveDoesNotReuseMainServerPortForSharedStateEndpoints(): void
    {
        $resolver = new SharedStateRuntimeResolver();

        $runtime = $resolver->resolve(
            [
                'host' => 'p11005ce4.weline.test',
                'port' => 9524,
            ],
            [
                'session' => [
                    'server_host' => '127.0.0.1',
                    'server_port' => 29970,
                ],
                'wls' => [
                    'session' => [
                        'host' => 'p11005ce4.weline.test',
                        'port' => 9524,
                        'token_file_name' => 'session.main-port.token',
                        'wls_server' => [
                            'host' => 'p11005ce4.weline.test',
                            'port' => 9524,
                        ],
                    ],
                    'memory_service' => [
                        'host' => '127.0.0.1',
                        'port' => 29971,
                        'token_file_name' => 'memory.runtime.token',
                    ],
                ],
            ],
            null
        );

        self::assertSame(29970, $runtime['session']['port']);
        self::assertSame(29971, $runtime['memory']['port']);
        self::assertNotSame(9524, $runtime['session']['port']);
        self::assertNotSame(9524, $runtime['memory']['port']);
    }

    public function testResolveDerivesImplicitTokenNamesWhenTokenNotExplicit(): void
    {
        $resolver = new SharedStateRuntimeResolver();

        $runtime = $resolver->resolve(
            [
                'session_server_port' => 26425,
                'memory_server_port' => 26424,
            ],
            [
                'wls' => [
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 26425,
                    ],
                    'memory_service' => [
                        'host' => '127.0.0.1',
                        'port' => 26424,
                    ],
                ],
            ],
            null
        );

        self::assertSame('session_server.26425.token', $runtime['session']['token_file_name']);
        self::assertSame('memory_server.26424.token', $runtime['memory']['token_file_name']);
    }
}
