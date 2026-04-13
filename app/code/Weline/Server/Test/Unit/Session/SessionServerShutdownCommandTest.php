<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerShutdownCommandTest extends TestCase
{
    public function testShutdownCommandRequiresValidTokenAndStopsServer(): void
    {
        $tokenFileName = 'session-server-shutdown-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_shutdown_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $port = $server->getPort();
            self::assertGreaterThan(0, $port);

            $socket = \stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth('wrong-token'));
            $server->tick(50000);
            $server->tick(50000);
            self::assertTrue($server->isRunning());

            @\fclose($socket);

            $socket = \stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildShutdown(null, ['server' => true]));
            for ($i = 0; $i < 10 && $server->isRunning(); $i++) {
                $server->tick(50000);
            }

            self::assertFalse($server->isRunning());
        } finally {
            $server->stop();
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }
}
