<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayControllerPortTakenRecoveryTest extends TestCase
{
    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
    }

    protected function setUp(): void
    {
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin'
            ? '/tmp'
            : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR
            . 'wls-port-taken-' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testOccupiedTcpPortIsProvenWithoutTouchingItsOwner(): void
    {
        $listener = $this->tcpListener();
        try {
            $port = $this->listenerPort($listener);
            $otherPort = $port === 65535 ? $port - 1 : $port + 1;
            $availability = $this->invokeStatic(
                'publicTcpBindAvailability',
                [[$port, $otherPort], 'ipv4-only'],
            );

            self::assertSame('PORT_TAKEN', $availability['state'] ?? null);
            self::assertSame(
                'tcp_bind_failure',
                $availability['diagnostics'][0]['evidence'] ?? null,
            );
            self::assertIsResource($listener);
            self::assertSame(
                '0.0.0.0:' . $port,
                \stream_socket_get_name($listener, false),
            );
        } finally {
            @\fclose($listener);
        }
    }

    public function testUdp443EquivalentNeverBecomesTcpPortTaken(): void
    {
        $udp = \stream_socket_server(
            'udp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND,
        );
        self::assertIsResource($udp, $error !== '' ? $error : (string)$errno);
        try {
            $udpPort = $this->listenerPort($udp);
            $otherPort = $this->freeTcpPortExcluding($udpPort);
            $availability = $this->invokeStatic(
                'publicTcpBindAvailability',
                [[$udpPort, $otherPort], 'ipv4-only'],
            );

            self::assertSame('AVAILABLE', $availability['state'] ?? null);
            self::assertNotContains(
                'PORT_TAKEN',
                \array_column(
                    (array)($availability['diagnostics'] ?? []),
                    'state',
                ),
            );
        } finally {
            @\fclose($udp);
        }
    }

    public function testAvailabilityProbeReleasesEveryHeldTcpSocket(): void
    {
        $availability = null;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $first = $this->freeTcpPortExcluding(0);
            $second = $this->freeTcpPortExcluding($first);
            $availability = $this->invokeStatic(
                'publicTcpBindAvailability',
                [[$first, $second], 'ipv4-only'],
            );
            if (($availability['state'] ?? '') === 'AVAILABLE') {
                break;
            }
        }
        self::assertSame('AVAILABLE', $availability['state'] ?? null);

        $firstListener = $this->tcpListener($first);
        try {
            $secondListener = $this->tcpListener($second);
            try {
                self::assertSame($first, $this->listenerPort($firstListener));
                self::assertSame($second, $this->listenerPort($secondListener));
            } finally {
                @\fclose($secondListener);
            }
        } finally {
            @\fclose($firstListener);
        }
    }

    public function testDefaultProfileDoesNotSelfCollideAcrossV4AndV6(): void
    {
        $first = $this->freeTcpPortExcluding(0);
        $second = $this->freeTcpPortExcluding($first);
        $availability = $this->invokeStatic(
            'publicTcpBindAvailability',
            [[$first, $second], 'default'],
        );
        if (($availability['state'] ?? '') === 'PORT_PROFILE_UNAVAILABLE') {
            self::markTestSkipped('The host has no usable IPv6 wildcard listener.');
        }

        self::assertSame('AVAILABLE', $availability['state'] ?? null);
        self::assertCount(4, (array)($availability['diagnostics'] ?? []));
        self::assertSame(
            ['ipv4', 'ipv6', 'ipv4', 'ipv6'],
            \array_column(
                (array)($availability['diagnostics'] ?? []),
                'family',
            ),
        );
    }

    public function testPermissionFailureAloneNeverClaimsPortTaken(): void
    {
        self::assertSame(
            'PORT_PERMISSION',
            $this->invokeStatic(
                'classifyPublicTcpBindFailure',
                [13, 'Permission denied'],
            ),
        );
        self::assertSame(
            'PORT_TAKEN',
            $this->invokeStatic(
                'classifyPublicTcpBindFailure',
                [98, 'Address already in use'],
            ),
        );

        $listener = $this->tcpListener('127.0.0.1');
        $port = $this->listenerPort($listener);
        try {
            self::assertTrue($this->invokeStatic(
                'loopbackTcpListenerPresent',
                ['tcp://127.0.0.1:' . $port],
            ));
        } finally {
            @\fclose($listener);
        }
        self::assertFalse($this->invokeStatic(
            'loopbackTcpListenerPresent',
            ['tcp://127.0.0.1:' . $port],
        ));
    }

    public function testPortTakenBackoffDoesNotConsumeRecoveryOrRollbackBudget(): void
    {
        $reflection = new \ReflectionClass(\WlsEdgeGatewayController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $bootId = \str_repeat('a', 64);
        $this->property($controller, 'hostBootId')->setValue(
            $controller,
            $bootId,
        );
        $stateProperty = $this->property($controller, 'state');
        $stateProperty->setValue($controller, [
            'ready' => true,
            'health_state' => 'HEALTHY',
            'failure_events' => [['reason' => 'sentinel']],
            'binary_transaction' => ['phase' => 'OBSERVING'],
            'recovery' => [
                'stage' => 'NONE',
                'consecutive_failures' => 2,
                'backoff_attempt' => 3,
                'port_taken_attempt' => 0,
                'port_taken_retry_at' => 0,
                'port_taken_retry_monotonic' => 0.0,
                'port_taken_boot_id' => '',
            ],
        ]);
        $defer = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'deferForTakenPublicTcpPorts',
        );
        $availability = [
            'state' => 'PORT_TAKEN',
            'reason' => 'occupied',
            'diagnostics' => [],
        ];

        $defer->invoke($controller, $availability);
        $first = $stateProperty->getValue($controller);
        self::assertSame('PORT_TAKEN', $first['recovery']['stage']);
        self::assertSame(1, $first['recovery']['port_taken_attempt']);
        self::assertGreaterThanOrEqual(
            4,
            (int)$first['recovery']['port_taken_retry_at'] - \time(),
        );
        self::assertLessThanOrEqual(
            5,
            (int)$first['recovery']['port_taken_retry_at'] - \time(),
        );
        self::assertSame(2, $first['recovery']['consecutive_failures']);
        self::assertSame(3, $first['recovery']['backoff_attempt']);
        self::assertSame([['reason' => 'sentinel']], $first['failure_events']);
        self::assertSame(
            ['phase' => 'OBSERVING'],
            $first['binary_transaction'],
        );

        $defer->invoke($controller, $availability);
        $duplicate = $stateProperty->getValue($controller);
        self::assertSame(1, $duplicate['recovery']['port_taken_attempt']);
        self::assertSame(
            $first['recovery']['port_taken_retry_at'],
            $duplicate['recovery']['port_taken_retry_at'],
        );
        self::assertSame(
            $first['recovery']['port_taken_retry_monotonic'],
            $duplicate['recovery']['port_taken_retry_monotonic'],
        );

        for ($expectedAttempt = 2; $expectedAttempt <= 8; ++$expectedAttempt) {
            $state = $stateProperty->getValue($controller);
            $state['recovery']['port_taken_retry_monotonic'] = 0.0;
            $stateProperty->setValue($controller, $state);
            $defer->invoke($controller, $availability);
            $state = $stateProperty->getValue($controller);
            self::assertSame(
                $expectedAttempt,
                $state['recovery']['port_taken_attempt'],
            );
        }
        self::assertGreaterThanOrEqual(
            299,
            (int)$state['recovery']['port_taken_retry_at'] - \time(),
        );
        self::assertLessThanOrEqual(
            300,
            (int)$state['recovery']['port_taken_retry_at'] - \time(),
        );

        (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'clearPublicPortTakenDeferral',
        ))->invoke($controller);
        $cleared = $stateProperty->getValue($controller);
        self::assertSame(0, $cleared['recovery']['port_taken_attempt']);
        self::assertSame(0, $cleared['recovery']['port_taken_retry_at']);
        self::assertSame(0.0, $cleared['recovery']['port_taken_retry_monotonic']);
        self::assertSame('', $cleared['recovery']['port_taken_boot_id']);

        (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'deferForUnprovenPublicTcpPorts',
        ))->invoke($controller, [
            'state' => 'PORT_PERMISSION',
            'reason' => 'Low-port bind permission cannot prove availability.',
            'diagnostics' => [],
        ]);
        $permission = $stateProperty->getValue($controller);
        self::assertSame('PORT_PERMISSION', $permission['recovery']['stage']);
        self::assertSame(2, $permission['recovery']['consecutive_failures']);
        self::assertSame(3, $permission['recovery']['backoff_attempt']);
        self::assertSame(
            [['reason' => 'sentinel']],
            $permission['failure_events'],
        );
    }

    public function testRecoveryWaitsForTcpReleaseThenResumesInitialPublication(): void
    {
        $controller = $this->createController();
        $listener = $this->tcpListener();
        $occupiedPort = $this->listenerPort($listener);
        $otherPort = $this->freeTcpPortExcluding($occupiedPort);
        $stateProperty = $this->property($controller, 'state');
        $state = $stateProperty->getValue($controller);
        $state['public_http'] = $occupiedPort;
        $state['public_https'] = $otherPort;
        $state['failure_events'] = [['reason' => 'sentinel']];
        $state['recovery']['consecutive_failures'] = 2;
        $state['recovery']['backoff_attempt'] = 3;
        $stateProperty->setValue($controller, $state);
        $recover = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'recoverDataPlane',
        );

        try {
            $recover->invoke($controller);
            $deferred = $stateProperty->getValue($controller);
            self::assertSame('PORT_TAKEN', $deferred['health_state']);
            self::assertSame('PORT_TAKEN', $deferred['recovery']['stage']);
            self::assertSame(1, $deferred['recovery']['port_taken_attempt']);
            self::assertSame(2, $deferred['recovery']['consecutive_failures']);
            self::assertSame(3, $deferred['recovery']['backoff_attempt']);
            self::assertSame(
                [['reason' => 'sentinel']],
                $deferred['failure_events'],
            );
        } finally {
            @\fclose($listener);
        }

        $state = $stateProperty->getValue($controller);
        $state['recovery']['port_taken_retry_monotonic'] = 0.0;
        $stateProperty->setValue($controller, $state);
        $recover->invoke($controller);
        $released = $stateProperty->getValue($controller);
        self::assertSame(
            'PORT_RELEASED_CONFIG_PENDING',
            $released['recovery']['stage'],
        );
        self::assertSame(0, $released['recovery']['port_taken_attempt']);
        self::assertSame(2, $released['recovery']['consecutive_failures']);
        self::assertSame(3, $released['recovery']['backoff_attempt']);
        self::assertTrue($this->property($controller, 'configDirty')->getValue(
            $controller,
        ));
    }

    public function testOnlyStableEmptyConfigTestPidArtifactIsNonRunning(): void
    {
        $controller = $this->createController();
        $pidFile = $this->root . DIRECTORY_SEPARATOR
            . 'home/runtime/run/nginx.pid';
        self::assertSame(0, \file_put_contents($pidFile, ''));
        self::assertTrue(\chmod($pidFile, 0644));
        $status = (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'nginxStatus',
        ))->invoke($controller);
        self::assertTrue($status['ok'] ?? false);
        self::assertFalse($status['running'] ?? true);
        self::assertTrue($status['empty_pid_artifact'] ?? false);

        self::assertSame(3, \file_put_contents($pidFile, 'bad'));
        $malformed = (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'nginxStatus',
        ))->invoke($controller);
        self::assertFalse($malformed['ok'] ?? true);
        self::assertFalse($malformed['running'] ?? true);
        self::assertStringContainsString(
            'unsafe or malformed',
            (string)($malformed['message'] ?? ''),
        );
    }

    public function testPortTakenBranchesPrecedeH3AndRollbackRecovery(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );
        $publish = $this->methodSource($source, 'publishIfDirty', 'activateCurrentConfigAndProbe');
        $portBranch = \strpos(
            $publish,
            'if (!$publicVerified && self::resultDefersPublicTcpStart((array)$result))',
        );
        $h3Branch = \strpos(
            $publish,
            'if (!$publicVerified && (bool)($this->state[\'h3_enabled\'] ?? false))',
        );
        $rollback = \strpos($publish, '$this->restorePublicationDataPlane(');
        self::assertIsInt($portBranch);
        self::assertIsInt($h3Branch);
        self::assertIsInt($rollback);
        self::assertLessThan($h3Branch, $portBranch);
        self::assertLessThan($rollback, $portBranch);

        $classifier = $this->methodSource(
            $source,
            'publicTcpBindAvailability',
            'publicPortsReachable',
        );
        self::assertStringContainsString('tcp://0.0.0.0:', $classifier);
        self::assertStringContainsString('tcp://[::]:', $classifier);
        self::assertStringNotContainsString('udp://', $classifier);

        $boundary = $this->methodSource(
            $source,
            'verifyDataPlaneStartBoundary',
            'startDataPlane',
        );
        $ownershipBranch = \substr(
            $boundary,
            (int)\strpos($boundary, '// A safe missing/stale PID'),
        );
        self::assertStringNotContainsString('stopDataPlane(', $ownershipBranch);
        self::assertStringNotContainsString('runNginx(', $ownershipBranch);
        self::assertStringNotContainsString(
            '$this->restartDataPlane(',
            $ownershipBranch,
        );
        self::assertStringNotContainsString(
            '$this->rollbackBinarySlot(',
            $ownershipBranch,
        );
        self::assertStringNotContainsString(
            '$this->rollbackToLkg(',
            $ownershipBranch,
        );

        $startup = $this->methodSource(
            $source,
            'adoptOrRecoverDataPlane',
            'backendProbeCacheKey',
        );
        self::assertStringContainsString(
            'if (self::resultDefersPublicTcpStart($boundary))',
            $startup,
        );
        self::assertStringContainsString('$this->configDirty = true;', $startup);
    }

    /** @return resource */
    private function tcpListener(int|string $port = 0)
    {
        $target = \is_int($port)
            ? '0.0.0.0:' . $port
            : $port . ':0';
        $listener = \stream_socket_server(
            'tcp://' . $target,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource(
            $listener,
            $error !== '' ? $error : (string)$errno,
        );
        return $listener;
    }

    /** @param resource $listener */
    private function listenerPort($listener): int
    {
        $address = \stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $separator = \strrpos($address, ':');
        self::assertIsInt($separator);
        $port = (int)\substr($address, $separator + 1);
        self::assertGreaterThan(0, $port);
        return $port;
    }

    private function freeTcpPortExcluding(int $excluded): int
    {
        do {
            $listener = $this->tcpListener();
            $port = $this->listenerPort($listener);
            @\fclose($listener);
        } while ($port === $excluded);
        return $port;
    }

    private function invokeStatic(string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            $method,
        ))->invokeArgs(null, $arguments);
    }

    private function property(object $object, string $name): \ReflectionProperty
    {
        return new \ReflectionProperty($object, $name);
    }

    private function createController(): \WlsEdgeGatewayController
    {
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $run = $home . DIRECTORY_SEPARATOR . 'runtime/run';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0750, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($run, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            \bin2hex(\random_bytes(16)),
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex(\random_bytes(32)),
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token',
            \bin2hex(\random_bytes(32)),
        ));
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'slot' => 'A',
                'test_mode' => true,
                'release_ready' => false,
                'implementation_level' => 'wls-2.0',
                'security_profile' => 'native-broker-v1',
                'runtime_generation' => 'port-taken-test-runtime',
                'listen_profile' => 'ipv4-only',
                'components' => [],
            ], JSON_THROW_ON_ERROR),
        ));
        $nginx = $slot . DIRECTORY_SEPARATOR . 'bin/nginx';
        self::assertNotFalse(\file_put_contents($nginx, "#!/bin/sh\nexit 0\n"));
        self::assertTrue(\chmod($nginx, 0700));

        return new \WlsEdgeGatewayController(
            $home,
            'unix://' . $run . DIRECTORY_SEPARATOR . 'controller.sock',
        );
    }

    private function methodSource(
        string $source,
        string $method,
        string $nextMethod,
    ): string {
        $start = \strpos($source, 'private function ' . $method . '(');
        if ($start === false) {
            $start = \strpos($source, 'private static function ' . $method . '(');
        }
        $end = \strpos($source, 'private function ' . $nextMethod . '(', (int)$start + 1);
        self::assertIsInt($start, 'Missing method ' . $method);
        self::assertIsInt($end, 'Missing method ' . $nextMethod);
        return \substr($source, $start, $end - $start);
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink()
                ? @\rmdir($path)
                : @\unlink($path);
        }
        @\rmdir($root);
    }
}
