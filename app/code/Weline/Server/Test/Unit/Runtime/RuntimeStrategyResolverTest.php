<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;

final class RuntimeStrategyResolverTest extends TestCase
{
    public function testAutoUsesSharedListenerDirectOnLinux(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 'auto', 'mode' => 'io'],
            [],
            $this->sharedListenerProfile(['cpu_cores' => 8, 'memory_mb' => 8192]),
        );

        $selection = $this->selection($result);
        self::assertSame('optimal', $result['status']);
        self::assertSame(RequestedTopology::Auto, $selection->requestedTopology);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('shared_fd', $selection->listenerMode);
        self::assertSame('event', $selection->eventLoopDriver);
        self::assertTrue($result['supervisor_enabled']);
        self::assertSame(16, $result['worker_count']);
    }

    public function testExplicitDirectUsesReusePortOnlyWhenConfigured(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            [
                'worker_count' => 'auto',
                'mode' => 'io',
                'runtime' => ['listener_mode' => 'reuseport'],
            ],
            ['direct' => true],
            $this->profile([
                'supports_reuse_port' => true,
                'reuse_port_probe' => ['supported' => true],
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ]),
        );

        $selection = $this->selection($result);
        self::assertSame(RequestedTopology::Direct, $selection->requestedTopology);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('reuseport', $selection->listenerMode);
        self::assertSame('cli.direct', $selection->source);
    }

    public function testAutoUsesDirectEvenWithSingleWorker(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 1, 'mode' => 'io'],
            [],
            $this->sharedListenerProfile(['cpu_cores' => 2, 'memory_mb' => 1024]),
        );

        self::assertSame(EffectiveTopology::Direct, $this->selection($result)->effectiveTopology);
        self::assertSame(1, $result['worker_count']);
    }

    public function testRemovedNoDispatcherOptionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Removed WLS topology option --no-dispatcher');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 1],
            ['no-dispatcher' => true],
            $this->sharedListenerProfile(),
        );
    }

    public function testWindowsAutoUsesNginxBalancedWorkerPortsAndStableWorkerCount(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 'auto', 'mode' => 'io'],
            [],
            $this->profile([
                'os_family' => 'Windows',
                'cpu_cores' => 16,
                'memory_mb' => 32600,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ]),
        );

        $selection = $this->selection($result);
        self::assertSame('degraded', $result['status']);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('worker_ports', $selection->listenerMode);
        self::assertSame(8, $result['worker_count']);
        self::assertFalse($result['supervisor_enabled']);
        self::assertSame(
            'auto disabled on Windows; native Master control plane avoids Supervisor reconnect churn',
            $result['supervisor_reason'],
        );
        self::assertContains(
            'Supervisor is disabled automatically on Windows; use --supervisor=on only when validating Supervisor HA.',
            $result['warnings'],
        );
    }

    public function testExplicitDirectUsesNginxBalancedWorkerPortsOnWindows(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['direct' => true],
            $this->profile(['os_family' => 'Windows']),
        );

        $selection = $this->selection($result);
        self::assertSame(RequestedTopology::Direct, $selection->requestedTopology);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('worker_ports', $selection->listenerMode);
        self::assertSame('cli.direct', $selection->source);
    }

    public function testExplicitDispatcherFallsBackToSelectWithWarning(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4, 'event_loop' => 'auto'],
            ['dispatcher' => true],
            $this->profile(['functions' => ['proc_open' => true]]),
        );

        $selection = $this->selection($result);
        self::assertSame(EffectiveTopology::Dispatcher, $selection->effectiveTopology);
        self::assertSame('select', $selection->eventLoopDriver);
        self::assertContains('PHP event extension is missing; stream_select is slower.', $result['warnings']);
    }

    public function testAutoUsesSharedListenerDirectOnDarwin(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            [],
            $this->sharedListenerProfile(['os_family' => 'Darwin']),
        );

        $selection = $this->selection($result);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('shared_fd', $selection->listenerMode);
        self::assertSame(['posix_auto_direct'], $selection->reasonCodes);
    }

    public function testDarwinDirectFailsWhenSharedListenerProbeFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shared listener consumers were not balanced');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            [],
            $this->profile([
                'os_family' => 'Darwin',
                'supports_direct_listener' => false,
                'direct_listener_mode' => '',
                'direct_listener_probe' => [
                    'reason' => 'Darwin shared listener consumers were not balanced.',
                ],
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ]),
        );
    }

    public function testExplicitDispatcherOverridesPosixAuto(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['dispatcher' => true],
            $this->profile([
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ]),
        );

        $selection = $this->selection($result);
        self::assertSame('stable', $result['status']);
        self::assertSame(RequestedTopology::Dispatcher, $selection->requestedTopology);
        self::assertSame(EffectiveTopology::Dispatcher, $selection->effectiveTopology);
        self::assertSame('cli.dispatcher', $selection->source);
    }

    public function testWindowsRejectsDirectListenerModeConfiguration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows Direct requires wls.runtime.listener_mode=auto or worker_ports');

        (new RuntimeStrategyResolver())->resolve(
            ['runtime' => ['listener_mode' => 'reuseport']],
            [],
            $this->profile(['os_family' => 'Windows']),
        );
    }

    public function testConflictingTopologyFlagsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conflicting WLS topology CLI options');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['direct' => true, 'dispatcher' => true],
            $this->sharedListenerProfile(),
        );
    }

    public function testDirectRejectsEventBufferSslEngineDuringPreflight(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('none/stream');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4, 'ssl' => ['engine' => 'event_buffer']],
            [],
            $this->sharedListenerProfile(),
        );
    }

    public function testPureWlsSelectsStreamTlsEngineBeforeDependencyBootstrap(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            [
                'worker_count' => 4,
                'https' => true,
                'edge' => ['adapter' => 'wls'],
            ],
            [],
            $this->sharedListenerProfile(['extensions' => ['event' => true, 'openssl' => false]]),
        );

        self::assertSame('stream', $this->selection($result)->sslEngine);
    }

    public function testLegacyTopologyIsRejectedEvenWhenRuntimeTopologyExists(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Removed WLS topology configuration "wls.topology"');

        (new RuntimeStrategyResolver())->resolve(
            [
                'topology' => 'direct',
                'runtime' => ['topology' => 'dispatcher'],
            ],
            [],
            $this->sharedListenerProfile(),
        );
    }

    public function testInstanceExplicitAutoUsesCanonicalRuntimeSource(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            [
                'worker_count' => 4,
                'runtime' => ['topology' => 'auto'],
                '_instance_topology_explicit' => true,
            ],
            [],
            $this->sharedListenerProfile(),
        );

        $selection = $this->selection($result);
        self::assertSame(RequestedTopology::Auto, $selection->requestedTopology);
        self::assertSame(EffectiveTopology::Direct, $selection->effectiveTopology);
        self::assertSame('instance.runtime.topology', $selection->source);
    }

    /** @param array<string,mixed> $result */
    private function selection(array $result): RuntimeSelection
    {
        self::assertArrayHasKey('runtime_selection', $result);
        self::assertInstanceOf(RuntimeSelection::class, $result['runtime_selection']);

        return $result['runtime_selection'];
    }

    /** @param array<string,mixed> $overrides */
    private function sharedListenerProfile(array $overrides = []): WlsRuntimeProfile
    {
        return $this->profile($overrides + [
            'supports_direct_listener' => true,
            'direct_listener_mode' => 'shared_fd',
            'direct_listener_probe' => ['supported' => true],
            'event_classes_available' => true,
            'extensions' => ['event' => true],
            'functions' => ['proc_open' => true],
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function profile(array $overrides): WlsRuntimeProfile
    {
        return new WlsRuntimeProfile($overrides + [
            'os_family' => 'Linux',
            'cpu_cores' => 4,
            'memory_mb' => 2048,
            'supports_reuse_port' => false,
            'supports_direct_listener' => false,
            'direct_listener_mode' => '',
            'event_classes_available' => false,
            'extensions' => [],
            'functions' => [],
            'windows_tools' => [],
        ]);
    }
}
