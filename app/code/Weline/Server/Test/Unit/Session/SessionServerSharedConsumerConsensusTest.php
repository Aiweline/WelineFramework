<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateServiceRegistry;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerSharedConsumerConsensusTest extends TestCase
{
    private const ROLE = 'session_server_unit_consensus';

    public function testHelloRegistersConsumerAndShutdownOnlyReleasesLease(): void
    {
        $tokenFileName = 'session-server-hello-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_hello_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord(self::ROLE);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 5,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-a', 'instance-a', self::ROLE));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayHasKey('instance-a', $registry->getConsumers(self::ROLE));
            self::assertTrue($server->isRunning());

            \fwrite($socket, SessionProtocol::buildShutdown('instance-a'));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayNotHasKey('instance-a', $registry->getConsumers(self::ROLE));
            self::assertTrue($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord(self::ROLE);
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testStartupGraceKeepsServerAliveUntilFirstConsumerConsensus(): void
    {
        $tokenFileName = 'session-server-startup-grace-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_startup_grace_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord(self::ROLE);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 5,
            'empty_token_exit_grace_sec' => 1,
            'startup_consumer_grace_sec' => 3,
            'empty_token_check_interval_sec' => 0.1,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            self::assertTrue($server->isSharedConsumerIdleWindowOpen());

            \usleep(1200000);
            $server->tick(50000);
            self::assertTrue($server->isRunning());

            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-startup', 'instance-startup', self::ROLE));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayHasKey('instance-startup', $registry->getConsumers(self::ROLE));
            self::assertArrayNotHasKey('shutdown_due_at', $registry->getRecord(self::ROLE));
            self::assertFalse($server->isSharedConsumerIdleWindowOpen());

            \usleep(2200000);
            $server->tick(50000);
            self::assertTrue($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord(self::ROLE);
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testStartPreservesPreRegisteredConsumerConsensus(): void
    {
        $tokenFileName = 'session-server-preserve-consumer-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_preserve_consumer_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord(self::ROLE);
        $registry->touchConsumer(self::ROLE, 'instance-pre');

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 5,
            'empty_token_exit_grace_sec' => 1,
            'startup_consumer_grace_sec' => 1,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));

            self::assertArrayHasKey('instance-pre', $registry->getConsumers(self::ROLE));
            self::assertArrayNotHasKey('shutdown_due_at', $registry->getRecord(self::ROLE));
            self::assertTrue($server->hasActiveConsumers());
            self::assertFalse($server->isSharedConsumerIdleWindowOpen());
            self::assertTrue($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord(self::ROLE);
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
        $registry->removeRecord(self::ROLE);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 1,
            'empty_token_exit_grace_sec' => 1,
            'empty_token_check_interval_sec' => 0.1,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-b', 'instance-b', self::ROLE));
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
            $registry->removeRecord(self::ROLE);
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testScheduledIdleShutdownDoesNotWaitForLongSelfCheckInterval(): void
    {
        $tokenFileName = 'session-server-scheduled-idle-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_scheduled_idle_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord(self::ROLE);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 10,
            'empty_token_exit_grace_sec' => 1,
            'empty_token_check_interval_sec' => 120.0,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-c', 'instance-c', self::ROLE));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildShutdown('instance-c'));
            $server->tick(50000);
            $server->tick(50000);

            \sleep(2);
            $server->tick(50000);

            self::assertFalse($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord(self::ROLE);
            $tokenPath = BP . 'var/session/' . $tokenFileName;
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testNewConsumerCancelsScheduledIdleShutdown(): void
    {
        $tokenFileName = 'session-server-cancel-idle-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_cancel_idle_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $registry = new SharedStateServiceRegistry();
        $registry->removeRecord(self::ROLE);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'shared_consumer_lease_ttl_sec' => 10,
            'empty_token_exit_grace_sec' => 1,
            'empty_token_check_interval_sec' => 120.0,
            'role' => self::ROLE,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $socket = \stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
            self::assertNotFalse($socket, $errstr);
            \stream_set_blocking($socket, false);

            \fwrite($socket, SessionProtocol::buildAuth((string) $server->getAuthToken()));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildHello('instance-d', 'instance-d', self::ROLE));
            $server->tick(50000);
            $server->tick(50000);

            \fwrite($socket, SessionProtocol::buildShutdown('instance-d'));
            $server->tick(50000);
            $server->tick(50000);
            self::assertArrayNotHasKey('instance-d', $registry->getConsumers(self::ROLE));

            \fwrite($socket, SessionProtocol::buildHello('instance-e', 'instance-e', self::ROLE));
            $server->tick(50000);
            $server->tick(50000);

            self::assertArrayHasKey('instance-e', $registry->getConsumers(self::ROLE));
            self::assertArrayNotHasKey('shutdown_due_at', $registry->getRecord(self::ROLE));

            \sleep(2);
            $server->tick(50000);

            self::assertTrue($server->isRunning());
        } finally {
            $server->stop();
            $registry->removeRecord(self::ROLE);
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
