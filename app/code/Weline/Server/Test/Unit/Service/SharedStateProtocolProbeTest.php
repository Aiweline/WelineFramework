<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateProtocolProbe;
use Weline\Server\Session\Server\SharedStateTokenStore;

final class SharedStateProtocolProbeTest extends TestCase
{
    public function testTokenReadIsBoundToRequestedEndpointTuple(): void
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-shared-probe-authority-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($directory, 0700, true));
        $path = $directory . DIRECTORY_SEPARATOR . 'session_server.39991.token';
        $secret = \str_repeat('b', 64);
        $authority = [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => 39991,
            'instance' => SharedStateTokenStore::defaultInstance(
                'session_server',
                '127.0.0.1',
                39991,
            ),
        ];
        (new SharedStateTokenStore($path, 0.25, $authority))->publish($secret, 1);
        $reader = new \ReflectionMethod(SharedStateProtocolProbe::class, 'readSecretFromTokenFile');
        $reader->setAccessible(true);

        try {
            self::assertSame(
                $secret,
                $reader->invoke(null, $path, '127.0.0.1', 39991, 'session_server', null),
            );
            self::assertSame(
                '',
                $reader->invoke(null, $path, '127.0.0.1', 39992, 'session_server', null),
                'A probe must not consume a capability issued for another endpoint tuple.',
            );
        } finally {
            foreach ((array)\scandir($directory) as $leaf) {
                if ($leaf !== '.' && $leaf !== '..') {
                    @\unlink($directory . DIRECTORY_SEPARATOR . $leaf);
                }
            }
            @\rmdir($directory);
        }
    }

    public function testPingFailsFastWhenPeerDoesNotRespond(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP is required for shared-state token lookup.');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);

        $address = (string) \stream_socket_get_name($server, false);
        $separator = \strrpos($address, ':');
        self::assertNotFalse($separator, $address);
        $port = (int) \substr($address, $separator + 1);
        self::assertGreaterThan(0, $port);

        $tokenBasename = 'shared_probe_timeout_' . \bin2hex(\random_bytes(4)) . '.token';
        $tokenDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR;
        $tokenPath = $tokenDir . $tokenBasename;
        if (!\is_dir($tokenDir)) {
            self::assertTrue(@\mkdir($tokenDir, 0777, true) || \is_dir($tokenDir));
        }

        self::assertNotFalse(@\file_put_contents(
            $tokenPath,
            \str_repeat('a', 64) . ':1',
        ));

        try {
            $startedAt = \microtime(true);
            $healthy = SharedStateProtocolProbe::pingWithTokenBasename('127.0.0.1', $port, $tokenBasename);
            $elapsed = \microtime(true) - $startedAt;

            self::assertFalse($healthy);
            self::assertLessThan(1.0, $elapsed, 'Unresponsive shared-state probes must stay below command-visible seconds.');
        } finally {
            @\unlink($tokenPath);
            @\fclose($server);
        }
    }
}
