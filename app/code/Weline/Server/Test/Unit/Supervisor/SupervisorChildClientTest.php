<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Supervisor;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Telemetry\WorkerTelemetryReporter;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Supervisor;
use Weline\Server\Supervisor\SupervisorRuntime;
use Weline\Server\Supervisor\SupervisorServer;

final class SupervisorChildClientTest extends TestCase
{
    public function testReadyIntentDoesNotCountAsConfirmedUntilAckArrives(): void
    {
        $runtime = $this->createRuntime('ut-instance');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            endpoint: $endpoint,
            progressCallback: static function () use ($server): void {
                $server->poll(0, 10000);
            },
        );

        try {
            self::assertFalse($client->isReadyStateConfirmed());

            $client->markReadyState(true);
            self::assertFalse($client->isReadyStateConfirmed());

            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12001,
                port: 18081,
                workerId: 1,
                launchId: 'launch-1',
                instanceCode: 'ut-instance',
                msgId: 'hello-1',
            ));
            self::assertFalse($client->isReadyStateConfirmed());

            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 1,
                port: 18081,
                launchId: 'launch-1',
                msgId: 'ready-1',
            ));
            self::assertTrue($client->isReadyStateConfirmed());
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testReconnectRequiresFreshReadyAckWhilePreservingReadyIntent(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($probe, (string) $errstr);
        $probeName = \stream_socket_get_name($probe, false);
        self::assertIsString($probeName);
        $parts = \explode(':', $probeName);
        $port = (int) \end($parts);
        @\fclose($probe);

        $runtime = $this->createRuntime('ut-instance');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', $port));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            endpoint: $endpoint,
            progressCallback: static function () use (&$server): void {
                $server?->poll(0, 10000);
            },
        );

        try {
            $client->markReadyState(true);
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12002,
                port: 18082,
                workerId: 2,
                launchId: 'launch-2',
                instanceCode: 'ut-instance',
                msgId: 'hello-2',
            ));
            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 2,
                port: 18082,
                launchId: 'launch-2',
                msgId: 'ready-2',
            ));
            self::assertTrue($client->isReadyStateConfirmed());

            $server->close();
            for ($i = 0; $i < 10; $i++) {
                $client->handleReadable();
                if (!$client->isConnected()) {
                    break;
                }
                \usleep(10000);
            }

            self::assertFalse($client->isConnected());
            self::assertFalse($client->isReadyStateConfirmed());

            $server = new SupervisorServer($runtime);
            $server->start(ControlEndpoint::tcp('127.0.0.1', $port));

            self::assertTrue(
                $client->tryReconnect(),
                'reconnect snapshot=' . \json_encode($runtime->slotSnapshot())
                . ', sessions=' . \json_encode($server->sessionsSnapshot()),
            );
            self::assertTrue($client->isConnected());
            self::assertTrue($client->isReadyStateConfirmed());
        } finally {
            $client->close();
            $server?->close();
        }
    }

    public function testCloseReleasesOwnedLeaseAndAllowsReplacement(): void
    {
        $runtime = $this->createRuntime('ut-instance');
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            endpoint: $endpoint,
            progressCallback: static function () use ($server): void {
                $server->poll(0, 10000);
            },
        );

        try {
            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12003,
                port: 18083,
                workerId: 3,
                launchId: 'launch-3',
                instanceCode: 'ut-instance',
                msgId: 'hello-3',
            ));
            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 3,
                port: 18083,
                launchId: 'launch-3',
                msgId: 'ready-3',
            ));

            $lease = $runtime->supervisor()->leases()->get('worker#3');
            self::assertNotNull($lease);

            $client->close();
            $server->poll(0, 10000);
            self::assertNull($runtime->supervisor()->leases()->get('worker#3'));

            self::assertTrue($client->connect('127.0.0.1', 0));
            self::assertTrue($client->register(
                role: 'worker',
                pid: 12003,
                port: 18083,
                workerId: 3,
                launchId: 'launch-3b',
                instanceCode: 'ut-instance',
                msgId: 'hello-3b',
            ));
            $replacementBeforeReady = $server->sessionsSnapshot();
            $clientLeaseProperty = new \ReflectionProperty($client, 'leaseId');
            $clientLeaseProperty->setAccessible(true);
            $clientGenerationProperty = new \ReflectionProperty($client, 'generation');
            $clientGenerationProperty->setAccessible(true);
            self::assertTrue($client->sendReady(
                role: 'worker',
                workerId: 3,
                port: 18083,
                launchId: 'launch-3b',
                msgId: 'ready-3b',
            ), 'replacement_before=' . \json_encode($replacementBeforeReady)
                . ', client_lease=' . (string)$clientLeaseProperty->getValue($client)
                . '/' . (int)$clientGenerationProperty->getValue($client)
                . ', replacement snapshot=' . \json_encode($runtime->slotSnapshot())
                . ', sessions=' . \json_encode($server->sessionsSnapshot()));

            $sessionIds = \array_keys($server->sessions());
            self::assertNotSame([], $sessionIds);
            self::assertTrue($server->sendToSession((int)\end($sessionIds), ControlMessage::shutdown()));
            for ($i = 0; $i < 10; $i++) {
                $client->handleReadable();
                if ($client->hasReceivedShutdown()) {
                    break;
                }
                \usleep(10000);
            }
            self::assertTrue($client->hasReceivedShutdown());

            $client->close();
            for ($i = 0; $i < 10; $i++) {
                $server->poll(0, 10000);
                if ($runtime->supervisor()->leases()->get('worker#3') === null) {
                    break;
                }
                \usleep(10000);
            }

            self::assertNull($runtime->supervisor()->leases()->get('worker#3'));
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testLifecycleStatusAndLogMethodsEncodeAndSendControlMessages(): void
    {
        [$client, $peer, $listener] = $this->createRawClient();

        try {
            $client->rememberRegistration(
                role: ControlMessage::ROLE_WORKER,
                pid: 12004,
                port: 18084,
                workerId: 4,
                launchId: 'launch-4',
                instanceCode: 'ut-instance',
                msgId: 'hello-4',
            );

            self::assertTrue($client->sendWorkerLoopStarted(4, 18084, 12004));
            self::assertTrue($client->sendDrainingComplete(reason: 'unit-drain'));
            self::assertTrue($client->sendStatusReport(7, 8192, 99));
            self::assertTrue($client->sendLogLine('unit-log', 'INFO', 'Worker#4'));
            $client->flushPendingWrites(0.25);
            $messages = $this->readControlMessages($peer, 4);

            self::assertSame([
                ControlMessage::TYPE_WORKER_LOOP_STARTED,
                ControlMessage::TYPE_DRAINING_COMPLETE,
                ControlMessage::TYPE_STATUS_REPORT,
                ControlMessage::TYPE_LOG,
            ], \array_column($messages, 'type'));
            self::assertSame(4, $messages[1]['worker_id'] ?? null);
            self::assertSame(18084, $messages[1]['port'] ?? null);
            self::assertSame('hello-4', $messages[1]['msg_id'] ?? null);
            self::assertSame('unit-drain', $messages[1]['reason'] ?? null);
            self::assertSame(7, $messages[2]['connections'] ?? null);
            self::assertSame('unit-log', $messages[3]['line'] ?? null);
        } finally {
            $client->close();
            @\fclose($peer);
            @\fclose($listener);
        }
    }

    public function testLogAndTelemetryOverflowDoNotDisconnectControlSession(): void
    {
        [$client, $peer, $listener] = $this->createRawClient();

        try {
            $maxWriteBuffer = new \ReflectionProperty($client, 'maxWriteBufferSize');
            $maxWriteBuffer->setAccessible(true);
            $maxWriteBuffer->setValue($client, 1);

            self::assertFalse($client->sendLogLine('overflow', 'WARNING', 'Worker#5'));
            self::assertTrue($client->isConnected());

            $reporter = WorkerTelemetryReporter::boot('ut-instance');
            $reporter->record($client, 'example.test', 500, 10, 128);
            self::assertTrue($client->isConnected());

            $sendBatch = new \ReflectionMethod($reporter, 'sendBatch');
            $sendBatch->setAccessible(true);
            $sendBatch->invoke($reporter, $client, [[
                'host' => 'example.test',
                'bucket_ts' => 1_711_111_080,
                'request_count' => 1,
                'error_count' => 0,
                'bytes_out' => 128,
                'latency_total_ms' => 10,
                'latency_max_ms' => 10,
            ]]);
            self::assertTrue($client->isConnected());
        } finally {
            WorkerTelemetryReporter::reset();
            $client->close();
            @\fclose($peer);
            @\fclose($listener);
        }
    }

    public function testImmediateTelemetryBurstIsCoalescedUntilForcedFlush(): void
    {
        [$client, $peer, $listener] = $this->createRawClient();

        try {
            $reporter = WorkerTelemetryReporter::boot('ut-instance');
            $reporter->record($client, 'example.test', 500, 10, 128);

            $lastImmediateSentAt = new \ReflectionProperty($reporter, 'lastImmediateSentAt');
            $lastImmediateSentAt->setAccessible(true);
            $lastImmediateSentAt->setValue($reporter, \microtime(true));
            $reporter->record($client, 'example.test', 503, 20, 256);

            $client->flushPendingWrites(0.25);
            $messages = $this->readControlMessages($peer, 1);
            self::assertCount(1, $messages);
            self::assertSame(500, $messages[0]['status'] ?? null);

            $reporter->flush($client);
            $client->flushPendingWrites(0.25);
            $messages = $this->readControlMessages($peer, 1);
            self::assertCount(1, $messages);
            self::assertSame(503, $messages[0]['status'] ?? null);
            self::assertSame(256, $messages[0]['bytes_out'] ?? null);
        } finally {
            WorkerTelemetryReporter::reset();
            $client->close();
            @\fclose($peer);
            @\fclose($listener);
        }
    }

    /** @return array{0:SupervisorChildClient,1:resource,2:resource} */
    private function createRawClient(): array
    {
        $listener = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($listener, (string)$errstr);
        $address = \stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $socket = \stream_socket_client('tcp://' . $address, $clientErrno, $clientErrstr, 1);
        self::assertIsResource($socket, (string)$clientErrstr);
        $peer = \stream_socket_accept($listener, 1);
        self::assertIsResource($peer);
        \stream_set_blocking($socket, false);
        \stream_set_blocking($peer, false);

        $client = new SupervisorChildClient(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
        );
        $socketProperty = new \ReflectionProperty($client, 'socket');
        $socketProperty->setAccessible(true);
        $socketProperty->setValue($client, $socket);

        return [$client, $peer, $listener];
    }

    /** @param resource $peer @return list<array<string, mixed>> */
    private function readControlMessages($peer, int $expectedCount): array
    {
        $buffer = '';
        $messages = [];
        $deadline = \microtime(true) + 1.0;
        while (\count($messages) < $expectedCount && \microtime(true) < $deadline) {
            $chunk = @\fread($peer, 65536);
            if (\is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
            }
            while (($newline = \strpos($buffer, "\n")) !== false) {
                $line = \substr($buffer, 0, $newline);
                $buffer = (string)\substr($buffer, $newline + 1);
                $decoded = ControlMessage::decode($line);
                if ($decoded !== []) {
                    $messages[] = $decoded;
                }
            }
            if (\count($messages) < $expectedCount) {
                \usleep(1000);
            }
        }

        return $messages;
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
