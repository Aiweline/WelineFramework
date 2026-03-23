<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorControlQueueTest extends TestCase
{
    protected function setUp(): void
    {
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testMutatingCommandQueuesBehindActiveOperation(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $reloadCalls = [];

            public function reloadAll(string $type = 'code', ?int $imperialEpochSnap = null): void
            {
                $this->reloadCalls[] = [$type, $imperialEpochSnap];
            }
        };
        $server = new class extends MasterControlServer {
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = [
                    'clientId' => $clientId,
                    'message' => ControlMessage::decode(\rtrim($message, "\n")),
                ];

                return true;
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'activeControlOperation', [
            'id' => 'ctrl_op_active',
            'action' => ControlMessage::ACTION_ROLLING_RESTART,
            'clientId' => 9,
            'payload' => [],
            'state' => 'running',
            'queuedAt' => \microtime(true),
            'startedAt' => \microtime(true),
        ]);

        $this->invokePrivate($orchestrator, 'handleCommand', [[
            'action' => ControlMessage::ACTION_RELOAD,
            'reload_type' => ControlMessage::RELOAD_TYPE_CODE,
        ], 12]);

        self::assertSame([], $orchestrator->reloadCalls);

        $pending = $this->readPrivate($orchestrator, 'pendingControlOperations');
        self::assertCount(1, $pending);
        self::assertSame(ControlMessage::ACTION_RELOAD, $pending[0]['action']);

        self::assertCount(1, $server->sent);
        self::assertSame(12, $server->sent[0]['clientId']);
        self::assertTrue((bool)$server->sent[0]['message']['success']);
        self::assertSame('queued', $server->sent[0]['message']['data']['state'] ?? null);
        self::assertSame(2, $server->sent[0]['message']['data']['queue_position'] ?? null);
    }

    public function testProcessNextQueuedControlOperationExecutesReloadLater(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $reloadCalls = [];

            public function reloadAll(string $type = 'code', ?int $imperialEpochSnap = null): void
            {
                $this->reloadCalls[] = [$type, $imperialEpochSnap];
            }
        };
        $server = new class extends MasterControlServer {
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = [
                    'clientId' => $clientId,
                    'message' => ControlMessage::decode(\rtrim($message, "\n")),
                ];

                return true;
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);

        $this->invokePrivate($orchestrator, 'handleCommand', [[
            'action' => ControlMessage::ACTION_RELOAD,
            'reload_type' => ControlMessage::RELOAD_TYPE_CODE,
        ], 7]);

        self::assertSame([], $orchestrator->reloadCalls);

        $processed = $this->invokePrivate($orchestrator, 'processNextQueuedControlOperation');

        self::assertTrue($processed);
        self::assertCount(1, $orchestrator->reloadCalls);
        self::assertSame(ControlMessage::RELOAD_TYPE_CODE, $orchestrator->reloadCalls[0][0]);
        self::assertIsInt($orchestrator->reloadCalls[0][1]);
        self::assertSame([], $this->readPrivate($orchestrator, 'pendingControlOperations'));
        self::assertNull($this->readPrivate($orchestrator, 'activeControlOperation'));
    }

    public function testStopClearsQueuedOperationsAndMarksActiveOperationAborting(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $server = new class extends MasterControlServer {
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = [
                    'clientId' => $clientId,
                    'message' => ControlMessage::decode(\rtrim($message, "\n")),
                ];

                return true;
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'activeControlOperation', [
            'id' => 'ctrl_op_1',
            'action' => ControlMessage::ACTION_RELOAD,
            'clientId' => 5,
            'payload' => ['reload_type' => ControlMessage::RELOAD_TYPE_CODE],
            'state' => 'running',
            'queuedAt' => \microtime(true),
            'startedAt' => \microtime(true),
        ]);
        $this->writePrivate($orchestrator, 'pendingControlOperations', [[
            'id' => 'ctrl_op_2',
            'action' => ControlMessage::ACTION_CACHE_CLEAR,
            'clientId' => 6,
            'payload' => ['action' => ControlMessage::ACTION_CACHE_CLEAR],
            'state' => 'queued',
            'queuedAt' => \microtime(true),
            'startedAt' => null,
        ]]);

        $this->invokePrivate($orchestrator, 'handleCommand', [[
            'action' => ControlMessage::ACTION_STOP,
        ], 11]);

        self::assertSame([], $this->readPrivate($orchestrator, 'pendingControlOperations'));
        self::assertSame('aborting', $this->readPrivate($orchestrator, 'activeControlOperation')['state']);
        self::assertSame('command', $this->readPrivate($orchestrator, 'pendingStopReason'));
        self::assertSame(ControlMessage::ACTION_STOP, $this->readPrivate($orchestrator, 'ipcExclusiveCommand'));
        self::assertCount(2, $server->sent);
        self::assertFalse((bool)$server->sent[0]['message']['success']);
        self::assertSame('cancelled', $server->sent[0]['message']['data']['state'] ?? null);
        self::assertTrue((bool)$server->sent[1]['message']['success']);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = $this->findPropertyReflection($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = $this->findPropertyReflection($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function findPropertyReflection(object $object, string $property): \ReflectionProperty
    {
        $reflection = new \ReflectionClass($object);
        do {
            if ($reflection->hasProperty($property)) {
                return $reflection->getProperty($property);
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        throw new \ReflectionException("Property {$property} not found");
    }
}
