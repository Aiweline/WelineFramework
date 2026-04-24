<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Protocol\SupervisorMessage;
use Weline\Server\Supervisor\Supervisor;
use Weline\Server\Supervisor\SupervisorRuntime;
use Weline\Server\Supervisor\SupervisorServer;
use Weline\Server\Supervisor\SupervisorSession;

final class SupervisorServerTest extends TestCase
{
    public function testServerProcessesHelloAndReadyOverTcpEndpoint(): void
    {
        $runtime = $this->createRuntime('default');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $client = @\stream_socket_client($endpoint->uri(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to SupervisorServer test endpoint');
        \stream_set_blocking($client, false);

        try {
            \fwrite($client, SupervisorMessage::hello(
                instance: 'default',
                channel: 'channel-default',
                role: 'worker',
                slotId: 'worker#1',
                pid: 12001,
                launchNonce: 'launch-1',
                msgId: 'hello-1',
            ));

            $leaseAssign = $this->waitForMessage($server, $client);
            self::assertSame(SupervisorMessage::TYPE_LEASE_ASSIGN, $leaseAssign['type'] ?? null);
            self::assertSame('channel-default', $leaseAssign['channel'] ?? null);

            \fwrite($client, SupervisorMessage::ready(
                slotId: 'worker#1',
                leaseId: (string) $leaseAssign['lease_id'],
                generation: (int) $leaseAssign['generation'],
                port: 18081,
                msgId: 'ready-1',
                channel: 'channel-default',
            ));

            $readyAck = $this->waitForMessage($server, $client);
            self::assertSame(SupervisorMessage::TYPE_READY_ACK, $readyAck['type'] ?? null);
            self::assertTrue((bool) ($readyAck['accepted'] ?? false));
            self::assertSame(1, (int) ($readyAck['pool_snapshot_version'] ?? 0));

            $slotSnapshot = $runtime->slotSnapshot();
            self::assertSame(2, $slotSnapshot['version']);
            self::assertCount(1, $slotSnapshot['slots']);
            self::assertSame('ready', $slotSnapshot['slots'][0]['state']);
        } finally {
            if (\is_resource($client)) {
                @\fclose($client);
            }
            $server->close();
        }
    }

    public function testServerRejectsWrongChannelAndKeepsRuntimeStateEmpty(): void
    {
        $runtime = $this->createRuntime('default');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $client = @\stream_socket_client($endpoint->uri(), $errno, $errstr, 3);
        self::assertNotFalse($client, $errstr ?: 'Failed to connect to SupervisorServer test endpoint');
        \stream_set_blocking($client, false);

        try {
            \fwrite($client, SupervisorMessage::hello(
                instance: 'default',
                channel: 'channel-other',
                role: 'worker',
                slotId: 'worker#1',
                pid: 12001,
                launchNonce: 'launch-1',
                msgId: 'hello-wrong',
            ));

            $response = $this->waitForMessage($server, $client);
            self::assertSame(SupervisorMessage::TYPE_CHANNEL_REJECT, $response['type'] ?? null);
            self::assertSame('channel-default', $response['expected_channel'] ?? null);
            self::assertSame('channel-other', $response['received_channel'] ?? null);
            self::assertSame(0, $runtime->slotSnapshot()['version']);
            self::assertSame([], $runtime->slotSnapshot()['slots']);
        } finally {
            if (\is_resource($client)) {
                @\fclose($client);
            }
            $server->close();
        }
    }

    public function testClosingSessionDoesNotReleaseCurrentLease(): void
    {
        $runtime = $this->createRuntime('default');
        $leaseAssign = SupervisorMessage::decode((string)$runtime->handle([
            'type' => SupervisorMessage::TYPE_HELLO,
            'msg_id' => 'hello-1',
            'instance' => 'default',
            'channel' => 'channel-default',
            'role' => 'worker',
            'slot_id' => 'worker#1',
            'pid' => 12001,
            'port' => 18081,
            'launch_nonce' => 'launch-1',
        ]));
        self::assertTrue($runtime->supervisor()->leases()->isCurrent(
            'worker#1',
            (string)$leaseAssign['lease_id'],
            (int)$leaseAssign['generation']
        ));

        $server = new SupervisorServer($runtime);
        $socket = \fopen('php://temp', 'r+');
        self::assertIsResource($socket);

        $sessionsProp = new \ReflectionProperty($server, 'sessions');
        $sessionsProp->setAccessible(true);
        $sessionsProp->setValue($server, [
            11 => new SupervisorSession(
                id: 11,
                peer: 'test',
                socket: $socket,
                instance: 'default',
                channel: 'channel-default',
                role: 'worker',
                slotId: 'worker#1',
                workerId: 1,
                pid: 12001,
                port: 18081,
                launchNonce: 'launch-1',
                leaseId: (string)$leaseAssign['lease_id'],
                generation: (int)$leaseAssign['generation'],
            ),
        ]);

        $server->closeSessionById(11);

        self::assertTrue($runtime->supervisor()->leases()->isCurrent(
            'worker#1',
            (string)$leaseAssign['lease_id'],
            (int)$leaseAssign['generation']
        ));
        self::assertFalse($server->hasSession(11));
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForMessage(SupervisorServer $server, $client): array
    {
        $deadline = \microtime(true) + 2.0;
        $buffer = '';
        while (\microtime(true) < $deadline) {
            $server->poll(0, 10000);
            $chunk = @\fread($client, 65536);
            if (\is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
                if (\str_contains($buffer, "\n")) {
                    [$line] = \explode("\n", $buffer, 2);
                    $decoded = SupervisorMessage::decode($line);
                    if ($decoded !== []) {
                        return $decoded;
                    }
                }
            }
            \usleep(10000);
        }

        self::fail('Expected supervisor response was not received in time.');
    }

    private function createRuntime(string $instanceName): SupervisorRuntime
    {
        return new SupervisorRuntime(
            instanceName: $instanceName,
            channelId: 'channel-' . $instanceName,
            endpointResolver: new ControlEndpointResolver('/srv/weline', 27000, 1000),
            supervisor: new Supervisor(new LeaseRegistry(
                static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}"
            )),
        );
    }
}
