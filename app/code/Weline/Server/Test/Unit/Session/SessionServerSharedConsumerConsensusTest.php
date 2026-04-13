<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateServiceRegistry;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerSharedConsumerConsensusTest extends TestCase
{
    public function testHelloRegistersConsumerAndShutdownOnlyReleasesLease(): void
    {
        $tokenFileName = 'session-server-hello-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_hello_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord('session_server');

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 5,
            'role' => 'session_server',
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-a', 'instance-a', 'session_server'));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayHasKey('instance-a', $registry->getConsumers('session_server'));
            self::assertTrue($server->isRunning());

            \fwrite($socket, SessionProtocol::buildShutdown('instance-a'));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayNotHasKey('instance-a', $registry->getConsumers('session_server'));
            self::assertTrue($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord('session_server');
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testServerStopsAfterIdleWindowWhenNoConsumersRemain(): void
    {
        $tokenFileName = 'session-server-idle-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_idle_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord('session_server');

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 1,
            'role' => 'session_server',
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-b', 'instance-b', 'session_server'));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildShutdown('instance-b'));
            $server->tick(50000);
            $server->tick(50000);

            \sleep(2);
            for ($i = 0; $i < 10 && $server->isRunning(); $i++) {
                $server->tick(50000);
            }

            self::assertFalse($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord('session_server');
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
