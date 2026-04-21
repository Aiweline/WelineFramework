<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

class PassthroughCoreSeedWorkerPoolTest extends TestCase
{
    private function createTrustingMasterReadyWarmupCore(
        array $healthResults,
        array $homepageResults = [],
        array $config = []
    ): object {
        return new class('127.0.0.1', 19981, 0, false, $healthResults, $homepageResults, $config) extends PassthroughCore {
            public array $healthCalls = [];
            public array $homepageCalls = [];

            public function __construct(
                string $workerHost,
                int $workerBasePort,
                int $workerCount,
                bool $workerSslEnabled,
                private array $healthResults,
                private array $homepageResults,
                array $config
            ) {
                parent::__construct($workerHost, $workerBasePort, $workerCount, $workerSslEnabled);
                if ($config !== []) {
                    $this->configure($config);
                }
            }

            public function runTrustingMasterReadyWarmup(int $port): array
            {
                return parent::warmupWorkerTrustingMasterReady($port);
            }

            protected function requestWorkerHealth(int $port, float $connectTimeout, float $responseTimeout): array
            {
                $this->healthCalls[] = [
                    'port' => $port,
                    'connect_timeout' => $connectTimeout,
                    'response_timeout' => $responseTimeout,
                ];

                $result = $this->healthResults[$port] ?? true;
                if (\is_array($result) && \array_is_list($result)) {
                    $result = \array_shift($this->healthResults[$port]);
                }
                if ($result === true) {
                    return ['success' => true, 'error' => '', 'status_line' => 'HTTP/1.1 200 OK', 'elapsed' => 0.01];
                }
                if (\is_string($result)) {
                    return ['success' => false, 'error' => $result, 'elapsed' => 0.01];
                }

                return $result;
            }

            protected function warmupWorkerViaHomepage(
                int $port,
                int $maxRetries = 3,
                float $connectTimeoutSeconds = 5.0,
                float $tlsTimeoutSeconds = 8.0,
                float $writeTimeoutSeconds = 5.0,
                float $readTimeoutSeconds = 60.0
            ): array {
                $this->homepageCalls[] = [
                    'port' => $port,
                    'retries' => $maxRetries,
                    'connect_timeout' => $connectTimeoutSeconds,
                    'tls_timeout' => $tlsTimeoutSeconds,
                    'write_timeout' => $writeTimeoutSeconds,
                    'read_timeout' => $readTimeoutSeconds,
                ];

                $result = $this->homepageResults[$port] ?? true;
                if (\is_array($result) && \array_is_list($result)) {
                    $result = \array_shift($this->homepageResults[$port]);
                }
                if ($result === true) {
                    return ['success' => true, 'error' => ''];
                }
                if (\is_string($result)) {
                    return ['success' => false, 'error' => $result];
                }

                return $result;
            }
        };
    }

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
        self::assertTrue(\socket_connect($client, '127.0.0.1', (int) $port));

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

    public function testShouldReuseCachedWorkerRouteRejectsSaturatedWorker(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        self::assertTrue($this->invokePrivateMethod($core, 'shouldReuseCachedWorkerRoute', 19982));

        $core->setWorkerSaturation(19982, 1, 1);
        self::assertFalse($this->invokePrivateMethod($core, 'shouldReuseCachedWorkerRoute', 19982));

        $core->clearWorkerSaturation(19982);
        self::assertTrue($this->invokePrivateMethod($core, 'shouldReuseCachedWorkerRoute', 19982));
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

    public function testPostFailureSpinBudgetEmptyPoolReturnsZero(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $budget = (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds');
        self::assertSame(0.0, $budget);

        $readyCore = $this->createWarmupStubCore([
            19982 => true,
        ]);
        $readyCore->setWorkerPorts([19982]);
        $readyBudget = (float) $this->invokePrivateMethod($readyCore, 'resolvePostFailureSpinBudgetSeconds');
        self::assertSame(3.0, $readyBudget);
    }

    public function testPostFailureSpinBudgetUsesFullSpinMaxWhenOnlyMaintenanceCandidatesExist(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $this->setPrivateProperty($core, 'maintenanceWorkerPorts', [19992]);

        self::assertSame(3.0, (float) $this->invokePrivateMethod($core, 'resolvePostFailureSpinBudgetSeconds'));
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

        self::assertSame(0.0, (float) $this->invokePrivateMethod($sslCore, 'resolvePostFailureSpinBudgetSeconds'));
        self::assertSame(15.0, $this->getPrivateProperty($sslCore, 'spinWaitMaxSeconds'));

        $plainCore = new PassthroughCore('127.0.0.1', 19981, 2, false);
        $plainCore->setWorkerPorts([]);
        $plainCore->configure(['spin_wait_max_seconds' => 0.0]);

        self::assertSame(0.0, (float) $this->invokePrivateMethod($plainCore, 'resolvePostFailureSpinBudgetSeconds'));
        self::assertSame(0.0, $this->getPrivateProperty($plainCore, 'spinWaitMaxSeconds'));
    }

    public function testSslModeStillSpinsWhenPoolAlreadyHasPorts(): void
    {
        $sslCore = new PassthroughCore('127.0.0.1', 19981, 2, true);
        $sslCore->configure(['spin_wait_max_seconds' => 0.0]);
        $this->setPrivateProperty($sslCore, 'workerPorts', [19982]);

        self::assertSame(15.0, (float) $this->invokePrivateMethod($sslCore, 'resolvePostFailureSpinBudgetSeconds'));
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
        self::assertSame([19982], $core->getWorkerPorts(), 'previous pool should stay when every new port is rejected');
        self::assertSame(1, $core->getWorkerCount());
    }

    public function testSetWorkerPortsKeepsPreviouslyAcceptedPortWhenRefreshWarmupFailsTransiently(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
        ]);

        $core->setWorkerPorts([19982]);
        self::assertSame([19982], $core->getWorkerPorts());

        $failingRefreshCore = $this->createWarmupStubCore([
            19982 => 'health connect timeout after 2.5s',
            19983 => true,
        ]);

        $this->setPrivateProperty($failingRefreshCore, 'workerPorts', [19982]);
        $this->setPrivateProperty($failingRefreshCore, 'workerCount', 1);
        $this->setPrivateProperty($failingRefreshCore, 'workerHealth', [
            19982 => [
                'failures' => 0,
                'blacklisted_at' => 0.0,
                'last_success' => \microtime(true) - 30.0,
                'total_failures' => 0,
            ],
        ]);

        $result = $failingRefreshCore->setWorkerPorts([19982, 19983]);

        self::assertSame([19982, 19983], $result['accepted']);
        self::assertSame([], $result['rejected']);
        self::assertSame([19982, 19983], $failingRefreshCore->getWorkerPorts());
        self::assertSame(2, $failingRefreshCore->getWorkerCount());
    }

    public function testSetWorkerPortsPublishesAcceptedPortsProgressively(): void
    {
        $core = $this->createWarmupStubCore([
            19982 => true,
            19983 => true,
        ]);

        $core->setWarmupCooperativeYield(static fn (): mixed => \Fiber::suspend());

        $result = null;
        $fiber = new \Fiber(function () use ($core, &$result): void {
            $result = $core->setWorkerPorts([19982, 19983]);
        });
        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertSame([], $core->getWorkerPorts());

        $fiber->resume();

        self::assertTrue($fiber->isSuspended());
        self::assertSame([19982], $core->getWorkerPorts(), 'first accepted port should be published before later warmup continues');

        while ($fiber->isSuspended()) {
            $fiber->resume();
        }

        self::assertSame([19982, 19983], $result['accepted']);
        self::assertSame([], $result['rejected']);
        self::assertSame([19982, 19983], $core->getWorkerPorts());
    }

    public function testBuildWorkerHealthRequestMarksInternalHealthProbe(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);

        $request = (string) $this->invokePrivateMethod($core, 'buildWorkerHealthRequest');

        self::assertStringContainsString("GET /_wls/health HTTP/1.1\r\n", $request);
        self::assertStringContainsString("Host: 127.0.0.1\r\n", $request);
        self::assertStringContainsString("X-WLS-Internal-Request: health-probe\r\n", $request);
    }

    public function testBuildWorkerHomepageWarmupRequestMarksInternalWarmup(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);

        $request = (string) $this->invokePrivateMethod($core, 'buildWorkerHomepageWarmupRequest');

        self::assertStringContainsString("GET / HTTP/1.1\r\n", $request);
        self::assertStringContainsString("Host: localhost\r\n", $request);
        self::assertStringContainsString("X-WLS-Internal-Request: homepage-warmup\r\n", $request);
    }

    public function testTrustingMasterReadyWarmupSkipsHomepageWarmupByDefault(): void
    {
        $core = $this->createTrustingMasterReadyWarmupCore([
            19982 => ['success' => true, 'error' => '', 'status_line' => 'HTTP/1.1 200 OK', 'elapsed' => 0.01],
        ]);

        $result = $core->runTrustingMasterReadyWarmup(19982);

        self::assertTrue($result['success']);
        self::assertSame('', $result['error']);
        self::assertCount(1, $core->healthCalls);
        self::assertSame([], $core->homepageCalls);
    }

    public function testTrustingMasterReadyWarmupUsesShortHealthProbeBudget(): void
    {
        $core = $this->createTrustingMasterReadyWarmupCore([
            19982 => ['success' => true, 'error' => '', 'status_line' => 'HTTP/1.1 200 OK', 'elapsed' => 0.01],
        ]);

        $result = $core->runTrustingMasterReadyWarmup(19982);

        self::assertTrue($result['success']);
        self::assertCount(1, $core->healthCalls);
        self::assertSame(0.6, $core->healthCalls[0]['connect_timeout']);
        self::assertSame(1.0, $core->healthCalls[0]['response_timeout']);
    }

    public function testTrustingMasterReadyWarmupCanOptIntoHomepageWarmup(): void
    {
        $core = $this->createTrustingMasterReadyWarmupCore(
            [
                19982 => ['success' => true, 'error' => '', 'status_line' => 'HTTP/1.1 200 OK', 'elapsed' => 0.01],
            ],
            [
                19982 => 'homepage warmup timed out',
            ],
            [
                'homepage_warmup_enabled' => true,
            ]
        );

        $result = $core->runTrustingMasterReadyWarmup(19982);

        self::assertTrue($result['success'], 'health probe success should still admit the worker');
        self::assertSame('', $result['error']);
        self::assertCount(1, $core->healthCalls);
        self::assertCount(1, $core->homepageCalls);
    }

    public function testTrustingMasterReadyWarmupExtendsRetriesForTransientEarlyCloses(): void
    {
        $core = $this->createTrustingMasterReadyWarmupCore([
            19982 => [
                'health connection closed before response after 0.05s',
                'health connection closed before response after 0.05s',
                'health connection closed before response after 0.05s',
                ['success' => true, 'error' => '', 'status_line' => 'HTTP/1.1 200 OK', 'elapsed' => 0.08],
            ],
        ]);

        $result = $core->runTrustingMasterReadyWarmup(19982);

        self::assertTrue($result['success']);
        self::assertSame('', $result['error']);
        self::assertCount(4, $core->healthCalls);
        self::assertSame([], $core->homepageCalls);
    }

    public function testWarmupWaitSliceFallsBackOutsideFiberEvenWhenCallbackIsRegistered(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 1);
        self::assertSame(0.05, (float) $this->invokePrivateMethod($core, 'resolveWarmupWaitSliceSeconds'));

        $core->setWarmupCooperativeYield(static fn (): mixed => \Fiber::suspend());
        self::assertSame(0.05, (float) $this->invokePrivateMethod($core, 'resolveWarmupWaitSliceSeconds'));

        $slice = null;
        $fiber = new \Fiber(function () use ($core, &$slice): void {
            $slice = (float) $this->invokePrivateMethod($core, 'resolveWarmupWaitSliceSeconds');
        });
        $fiber->start();

        self::assertSame(0.01, $slice);
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

        self::assertFalse($released, 'SSL passthrough mode must not reuse backend sockets');
        $this->invokePrivateMethod($core, 'discardWorkerSocket', $socket);
        @\socket_close($peer);
        @\socket_close($server);
    }
}
