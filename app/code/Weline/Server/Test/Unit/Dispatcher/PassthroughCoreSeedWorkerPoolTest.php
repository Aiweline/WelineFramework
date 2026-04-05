<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

class PassthroughCoreSeedWorkerPoolTest extends TestCase
{
    private function createWarmupStubCore(array $warmupResults, bool $sslEnabled = false): PassthroughCore
    {
        return new class('127.0.0.1', 19981, 0, $sslEnabled, $warmupResults) extends PassthroughCore {
            public function __construct(
                string $workerHost,
                int $workerBasePort,
                int $workerCount,
                bool $workerSslEnabled,
                private array $warmupResults
            ) {
                parent::__construct($workerHost, $workerBasePort, $workerCount, $workerSslEnabled);
            }

            protected function warmupWorkerTrustingMasterReady(int $port): array
            {
                return $this->warmupWorker($port);
            }

            protected function warmupWorker(int $port): array
            {
                $result = $this->warmupResults[$port] ?? true;
                if ($result === true) {
                    return ['success' => true, 'error' => ''];
                }
                if (\is_string($result)) {
                    return ['success' => false, 'error' => $result];
                }

                return ['success' => (bool) $result, 'error' => (bool) $result ? '' : 'warmup failed'];
            }
        };
    }

    private function invokePrivateMethod(object $target, string $method, mixed ...$args): mixed
    {
        $caller = function (string $methodName, array $invokeArgs): mixed {
            return $this->{$methodName}(...$invokeArgs);
        };
        $scope = $target instanceof PassthroughCore ? PassthroughCore::class : $target;
        $bound = \Closure::bind($caller, $target, $scope);
        self::assertInstanceOf(\Closure::class, $bound);
        return $bound($method, $args);
    }

    private function getPrivateProperty(object $target, string $property): mixed
    {
        $reader = function (string $propertyName): mixed {
            return $this->{$propertyName};
        };
        $scope = $target instanceof PassthroughCore ? PassthroughCore::class : $target;
        $bound = \Closure::bind($reader, $target, $scope);
        self::assertInstanceOf(\Closure::class, $bound);
        return $bound($property);
    }

    private function setPrivateProperty(object $target, string $property, mixed $value): void
    {
        $writer = function (string $propertyName, mixed $propertyValue): void {
            $this->{$propertyName} = $propertyValue;
        };
        $scope = $target instanceof PassthroughCore ? PassthroughCore::class : $target;
        $bound = \Closure::bind($writer, $target, $scope);
        self::assertInstanceOf(\Closure::class, $bound);
        $bound($property, $value);
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function createConnectedSocketPair(): array
    {
        $server = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($server);
        self::assertTrue(\socket_bind($server, '127.0.0.1', 0));
        self::assertTrue(\socket_listen($server, 1));
        self::assertTrue(\socket_getsockname($server, $host, $port));

        $client = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($client);
        self::assertTrue(\socket_connect($client, '127.0.0.1', (int)$port));

        $accepted = \socket_accept($server);
        self::assertNotFalse($accepted);
        \socket_set_nonblock($accepted);

        return [$accepted, $client, $server];
    }

    public function testWorkerPoolStartsEmptyUntilMasterSync(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        self::assertSame([], $core->getWorkerPorts());
        self::assertSame(0, $core->getWorkerCount());
    }

    public function testIsWorkerPortInPoolReflectsCurrentPool(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $this->setPrivateProperty($core, 'workerPorts', [100, 200]);

        self::assertTrue($this->invokePrivateMethod($core, 'isWorkerPortInPool', 100));
        self::assertFalse($this->invokePrivateMethod($core, 'isWorkerPortInPool', 999));

        $this->setPrivateProperty($core, 'workerPorts', []);
        self::assertFalse($this->invokePrivateMethod($core, 'isWorkerPortInPool', 100));
    }

    public function testNormalizeExcludePortClearsWhenPoolHasSingleWorker(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $this->setPrivateProperty($core, 'workerPorts', [19982]);

        self::assertNull($this->invokePrivateMethod($core, 'normalizeExcludePortForWorkerPool', 19982));
        self::assertNull($this->invokePrivateMethod($core, 'normalizeExcludePortForWorkerPool', null));
        self::assertSame(19983, $this->invokePrivateMethod($core, 'normalizeExcludePortForWorkerPool', 19983));

        $this->setPrivateProperty($core, 'workerPorts', [19982, 19983]);
        self::assertSame(19982, $this->invokePrivateMethod($core, 'normalizeExcludePortForWorkerPool', 19982));
    }

    public function testPostFailureSpinBudgetEmptyPoolUsesShortCap(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $budget = (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds');
        self::assertSame(0.5, $budget);

        $readyCore = $this->createWarmupStubCore([
            19982 => true,
        ]);
        $readyCore->setWorkerPorts([19982]);
        $readyBudget = (float) $this->invokePrivateMethod($readyCore, 'resolvePostFailureSpinBudgetSeconds');
        self::assertSame(3.0, $readyBudget);
    }

    public function testPostFailureSpinBudgetNonEmptyPoolUsesFullSpinMax(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
            19983 => true,
        ]);
        $core->setWorkerPorts([19982, 19983]);
        self::assertSame(3.0, (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds'));

        $core->blacklistWorker(19982);
        $core->blacklistWorker(19983);
        self::assertSame(3.0, (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds'));

        $core->unblacklistWorker(19982);
        self::assertSame(3.0, (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds'));
    }

    public function testSslModeKeepsStartupSpinWaitGraceEvenWhenConfiguredZero(): void
    {
        $sslCore = new PassthroughCore('127.0.0.1', 19981, 2, true);
        $sslCore->setWorkerPorts([]);
        $sslCore->configure(['spin_wait_max_seconds' => 0.0]);

        self::assertSame(0.5, (float) $this->invokePrivateMethod($sslCore, 'resolvePostFailureSpinBudgetSeconds'));
        self::assertSame(15.0, $this->getPrivateProperty($sslCore, 'spinWaitMaxSeconds'));

        $plainCore = new PassthroughCore('127.0.0.1', 19981, 2, false);
        $plainCore->setWorkerPorts([]);
        $plainCore->configure(['spin_wait_max_seconds' => 0.0]);

        self::assertSame(0.0, (float) $this->invokePrivateMethod($plainCore, 'resolvePostFailureSpinBudgetSeconds'));
        self::assertSame(0.0, $this->getPrivateProperty($plainCore, 'spinWaitMaxSeconds'));
    }

    public function testAddWorkerPortRejectsPortWhenWarmupFails(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => 'connect failed: connection refused',
        ]);

        $result = $core->addWorkerPort(19982);

        self::assertFalse($result['accepted']);
        self::assertSame('connect failed: connection refused', $result['error']);
        self::assertSame([], $core->getWorkerPorts());
        self::assertSame(0, $core->getWorkerCount());
    }

    public function testSetWorkerPortsOnlyKeepsWarmupApprovedPorts(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
            19983 => 'unexpected health response: HTTP/1.1 503',
        ]);

        $result = $core->setWorkerPorts([19982, 19983]);

        self::assertSame([19982], $result['accepted']);
        self::assertSame([
            19983 => 'unexpected health response: HTTP/1.1 503',
        ], $result['rejected']);
        self::assertSame([19982], $core->getWorkerPorts());
        self::assertSame(1, $core->getWorkerCount());
        self::assertSame(1, $core->getWorkerHealthSummary()['total']);
    }

    public function testSetWorkerPortsKeepsPreviousPoolWhenAllNewPortsRejected(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
            19996 => 'connect failed: timeout',
        ]);

        $core->setWorkerPorts([19982]);
        self::assertSame([19982], $core->getWorkerPorts());

        $result = $core->setWorkerPorts([19996]);

        self::assertSame([], $result['accepted']);
        self::assertSame([19996 => 'connect failed: timeout'], $result['rejected']);
        self::assertSame([19982], $core->getWorkerPorts(), '新池全失败时应保留旧池，避免 0/0');
        self::assertSame(1, $core->getWorkerCount());
    }

    public function testSetWorkerPortsPublishesAcceptedPortsProgressively(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,  // 维护 Worker
            19983 => true,  // 业务 Worker
        ]);

        $snapshots = [];
        $core->setWarmupCooperativeYield(function () use ($core, &$snapshots): void {
            $snapshots[] = $core->getWorkerPorts();
        });

        $result = $core->setWorkerPorts([19982, 19983]);

        self::assertSame([19982, 19983], $result['accepted']);
        self::assertSame([], $result['rejected']);
        self::assertSame([19982, 19983], $core->getWorkerPorts());
        self::assertContains([19982], $snapshots, '应在后续端口预热前已发布首个可用端口（维护接流可提前生效）');
    }

    public function testWarmupWaitSliceIsShorterInCooperativeMode(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);
        self::assertSame(0.05, (float)$this->invokePrivateMethod($core, 'resolveWarmupWaitSliceSeconds'));

        $core->setWarmupCooperativeYield(static function (): void {
        });
        self::assertSame(0.01, (float)$this->invokePrivateMethod($core, 'resolveWarmupWaitSliceSeconds'));
    }

    public function testBackendPoolReleaseAndAcquireRoundTrip(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
        ]);
        $core->configure([
            'backend_pool_enabled' => true,
            'backend_pool_max_idle_per_worker' => 2,
            'backend_pool_idle_ttl' => 30,
        ]);
        $core->setWorkerPorts([19982]);

        [$socket, $peer, $server] = $this->createConnectedSocketPair();

        $released = $this->invokePrivateMethod($core, 'releaseWorkerSocketToPool', 19982, $socket);
        self::assertTrue($released);

        $acquired = $this->invokePrivateMethod($core, 'acquireIdleWorkerSocket', 19982);
        self::assertSame($socket, $acquired);

        $this->invokePrivateMethod($core, 'discardWorkerSocket', $acquired);
        @\socket_close($peer);
        @\socket_close($server);
    }

    public function testDisablingBackendPoolClosesIdleSockets(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
        ]);
        $core->configure([
            'backend_pool_enabled' => true,
            'backend_pool_max_idle_per_worker' => 2,
            'backend_pool_idle_ttl' => 30,
        ]);
        $core->setWorkerPorts([19982]);

        [$socket, $peer, $server] = $this->createConnectedSocketPair();

        self::assertTrue($this->invokePrivateMethod($core, 'releaseWorkerSocketToPool', 19982, $socket));

        $core->configure(['backend_pool_enabled' => false]);
        self::assertSame([], $this->getPrivateProperty($core, 'idleWorkerPool'));
        @\socket_close($peer);
        @\socket_close($server);
    }

    public function testSslPassthroughNeverReusesBackendPoolSockets(): void
    {
        $core = $this->createWarmupStubCore([19982 => true], true);
        $core->configure([
            'backend_pool_enabled' => true,
            'backend_pool_max_idle_per_worker' => 2,
            'backend_pool_idle_ttl' => 30,
        ]);
        $core->setWorkerPorts([19982]);

        [$socket, $peer, $server] = $this->createConnectedSocketPair();
        $released = $this->invokePrivateMethod($core, 'releaseWorkerSocketToPool', 19982, $socket);

        self::assertFalse($released, 'SSL 透传模式下不应复用后端 socket');
        $this->invokePrivateMethod($core, 'discardWorkerSocket', $socket);
        @\socket_close($peer);
        @\socket_close($server);
    }
}
