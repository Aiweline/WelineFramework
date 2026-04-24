<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateProtocolProbe;

final class SharedStateProtocolProbeTest extends TestCase
{
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

        self::assertNotFalse(@\file_put_contents($tokenPath, 'secret:unit-test'));

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
