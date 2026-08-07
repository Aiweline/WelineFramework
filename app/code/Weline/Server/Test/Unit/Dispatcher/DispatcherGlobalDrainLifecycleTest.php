<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlMessage;

final class DispatcherGlobalDrainLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        $this->sent = [];
    }

    public function testGlobalDrainClosesAcceptGateAndWaitsForExistingTunnel(): void
    {
        $dispatcher = $this->newDispatcher();
        $listener = $this->newSocket();
        $this->setProperty($dispatcher, 'serverSocket', $listener);
        $this->setProperty($dispatcher, 'clientConnections', [41 => new \stdClass()]);

        $this->invoke($dispatcher, 'handleIpcMessage', [[
            'type' => ControlMessage::TYPE_DRAIN,
            'ports' => [],
            'drain_timeout_sec' => 300,
        ]]);

        self::assertTrue($this->getProperty($dispatcher, 'globalDrainActive'));
        self::assertNull($this->getProperty($dispatcher, 'serverSocket'));
        self::assertFalse($this->invoke($dispatcher, 'canAcceptNewSelectTunnel'));
        self::assertSame([], $this->sent, 'A live tunnel must prevent a false draining_complete ACK.');

        $this->setProperty($dispatcher, 'clientConnections', []);
        $this->invoke($dispatcher, 'advanceGlobalDrain');

        self::assertCount(1, $this->sent);
        $message = $this->decode($this->sent[0]);
        self::assertSame(ControlMessage::TYPE_DRAINING_COMPLETE, $message['type'] ?? null);
        self::assertSame(ControlMessage::DRAIN_OUTCOME_NATURAL, $message['drain']['outcome'] ?? null);
        self::assertSame(1, $message['drain']['observed']['connections'] ?? null);
        self::assertSame(0, $message['drain']['terminated']['connections'] ?? null);
    }

    public function testDuplicateGlobalDrainCannotExtendTheAbsoluteDeadline(): void
    {
        $dispatcher = $this->newDispatcher();
        $this->setProperty($dispatcher, 'serverSocket', $this->newSocket());
        $this->setProperty($dispatcher, 'clientConnections', [42 => new \stdClass()]);

        $this->invoke($dispatcher, 'handleIpcMessage', [[
            'type' => ControlMessage::TYPE_DRAIN,
            'ports' => [],
            'drain_timeout_sec' => 10,
        ]]);
        $startedAt = $this->getProperty($dispatcher, 'globalDrainStartedAt');
        $hardDeadline = $this->getProperty($dispatcher, 'globalDrainHardDeadlineAt');

        $this->invoke($dispatcher, 'handleIpcMessage', [[
            'type' => ControlMessage::TYPE_DRAIN,
            'ports' => [],
            'drain_timeout_sec' => 7200,
        ]]);

        self::assertSame($startedAt, $this->getProperty($dispatcher, 'globalDrainStartedAt'));
        self::assertSame($hardDeadline, $this->getProperty($dispatcher, 'globalDrainHardDeadlineAt'));
        self::assertSame([], $this->sent);
    }

    public function testHardDeadlineForceClosesPendingTunnelAndReportsTermination(): void
    {
        $dispatcher = $this->newDispatcher();
        $this->setProperty($dispatcher, 'serverSocket', $this->newSocket());
        $pendingSocket = $this->newSocket();
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueue', [
            77 => [
                'socket' => $pendingSocket,
                'clientIp' => '127.0.0.1',
                'acceptedAt' => \hrtime(true) / 1_000_000_000,
                'allWorkersUnavailable' => false,
            ],
        ]);

        $this->invoke($dispatcher, 'handleIpcMessage', [[
            'type' => ControlMessage::TYPE_DRAIN,
            'ports' => [],
            'drain_timeout_sec' => 1,
        ]]);
        $this->setProperty($dispatcher, 'globalDrainStartedAt', $this->monotonicSeconds() - 2.0);
        $this->setProperty($dispatcher, 'globalDrainSoftDeadlineAt', $this->monotonicSeconds() - 1.0);
        $this->setProperty($dispatcher, 'globalDrainHardDeadlineAt', $this->monotonicSeconds() - 0.5);

        $this->invoke($dispatcher, 'advanceGlobalDrain');

        self::assertSame([], $this->getProperty($dispatcher, 'pendingMaintenancePageQueue'));
        self::assertCount(1, $this->sent);
        $message = $this->decode($this->sent[0]);
        self::assertSame(ControlMessage::DRAIN_OUTCOME_FORCED, $message['drain']['outcome'] ?? null);
        self::assertTrue($message['drain']['forced'] ?? false);
        self::assertSame(1, $message['drain']['terminated']['connections'] ?? null);
    }

    public function testAcceptedButNotPromotedSocketIsInsideTheDrainFence(): void
    {
        $dispatcher = $this->newDispatcher();
        $this->setProperty($dispatcher, 'serverSocket', $this->newSocket());
        $acceptedSocket = $this->newSocket();
        $this->setProperty($dispatcher, 'acceptInFlightConnections', [88 => $acceptedSocket]);

        $this->invoke($dispatcher, 'handleIpcMessage', [[
            'type' => ControlMessage::TYPE_DRAIN,
            'ports' => [],
            'drain_timeout_sec' => 1,
        ]]);

        self::assertSame([], $this->sent, 'An accepted socket must not disappear between accept and registration.');
        $now = $this->monotonicSeconds();
        $this->setProperty($dispatcher, 'globalDrainStartedAt', $now - 2.0);
        $this->setProperty($dispatcher, 'globalDrainSoftDeadlineAt', $now - 1.0);
        $this->setProperty($dispatcher, 'globalDrainHardDeadlineAt', $now - 0.5);
        $this->invoke($dispatcher, 'advanceGlobalDrain');

        self::assertSame([], $this->getProperty($dispatcher, 'acceptInFlightConnections'));
        self::assertCount(1, $this->sent);
        $message = $this->decode($this->sent[0]);
        self::assertSame(ControlMessage::DRAIN_OUTCOME_FORCED, $message['drain']['outcome'] ?? null);
        self::assertSame(1, $message['drain']['terminated']['connections'] ?? null);
    }

    public function testForcedDrainCannotPromoteABackendSelectedByAnOlderControlTick(): void
    {
        $dispatcher = $this->newDispatcher();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['closeConnection'])
            ->getMock();
        $core->expects(self::once())->method('closeConnection');
        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'globalDrainCompletionReport', [
            'outcome' => ControlMessage::DRAIN_OUTCOME_FORCED,
        ]);
        $socket = $this->newSocket();

        $registered = $this->invoke(
            $dispatcher,
            'registerAcceptedClientConnection',
            [$socket, '127.0.0.1', 99],
        );

        self::assertFalse($registered);
        self::assertSame([], $this->getProperty($dispatcher, 'clientConnections'));
    }

    private function newDispatcher(): Dispatcher
    {
        $dispatcher = (new \ReflectionClass(Dispatcher::class))->newInstanceWithoutConstructor();
        $client = $this->createMock(ChildControlClientInterface::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('send')->willReturnCallback(function (string $message): bool {
            $this->sent[] = $message;
            return true;
        });
        $client->method('flushPendingWrites')->willReturn(true);

        $this->setProperty($dispatcher, 'ipcClient', $client);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'instanceName', 'drain-test');
        $this->setProperty($dispatcher, 'port', 29502);
        $this->setProperty($dispatcher, 'clientConnections', []);
        $this->setProperty($dispatcher, 'pendingMaintenancePageQueue', []);

        return $dispatcher;
    }

    private function newSocket(): \Socket
    {
        $socket = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        return $socket;
    }

    /** @return array<string,mixed> */
    private function decode(string $message): array
    {
        $decoded = \json_decode(\trim($message), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setValue($object, $value);
    }

    private function getProperty(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        return $property->getValue($object);
    }

    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $arguments);
    }
}
