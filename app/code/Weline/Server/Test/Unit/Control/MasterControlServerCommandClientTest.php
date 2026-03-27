<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;

final class MasterControlServerCommandClientTest extends TestCase
{
    public function testCommandClientIsClassifiedAsControlBeforeDisconnect(): void
    {
        $server = new MasterControlServer();
        self::assertTrue($server->start('127.0.0.1', 0));

        $client = @\stream_socket_client('tcp://127.0.0.1:' . $server->getPort(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to MasterControlServer test socket');

        $disconnectInfo = null;
        $server->onDisconnect(static function (int $clientId, array $clientInfo) use (&$disconnectInfo): void {
            $disconnectInfo = $clientInfo;
        });

        try {
            \fwrite($client, ControlMessage::command(ControlMessage::ACTION_STATUS));

            $connectedClients = $this->waitForConnectedClients($server, 2.0);
            self::assertCount(1, $connectedClients);
            $clientInfo = \array_values($connectedClients)[0];
            self::assertSame('control', $clientInfo['role']);

            @\fclose($client);

            $this->waitForCondition(
                static function () use (&$disconnectInfo): bool {
                    return \is_array($disconnectInfo);
                },
                2.0,
                static fn () => $server->poll(0, 10000)
            );

            self::assertIsArray($disconnectInfo);
            self::assertSame('control', $disconnectInfo['role'] ?? null);
        } finally {
            if (\is_resource($client)) {
                @\fclose($client);
            }
            $server->close();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function waitForConnectedClients(MasterControlServer $server, float $timeoutSec): array
    {
        $clients = [];
        $this->waitForCondition(
            static function () use ($server, &$clients): bool {
                $clients = $server->getConnectedClients();

                return $clients !== [];
            },
            $timeoutSec,
            static fn () => $server->poll(0, 10000)
        );

        return $clients;
    }

    private function waitForCondition(callable $condition, float $timeoutSec, ?callable $tick = null): void
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            if ($tick !== null) {
                $tick();
            }

            if ($condition()) {
                return;
            }

            \usleep(10000);
        }

        self::fail('Condition was not satisfied before timeout.');
    }
}
