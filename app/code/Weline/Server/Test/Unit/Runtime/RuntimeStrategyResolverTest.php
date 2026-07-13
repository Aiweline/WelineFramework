<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;

final class RuntimeStrategyResolverTest extends TestCase
{
    public function testAutoUsesDirectOnLinuxWhenReusePortAndEventAreAvailable(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 'auto', 'mode' => 'io'],
            [],
            $this->profile([
                'os_family' => 'Linux',
                'cpu_cores' => 8,
                'memory_mb' => 8192,
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('optimal', $result['status']);
        self::assertSame('auto', $result['requested_topology']);
        self::assertSame('direct', $result['topology']);
        self::assertFalse($result['dispatcher_enabled']);
        self::assertTrue($result['direct_reuse_port']);
        self::assertSame('event', $result['event_loop_driver']);
        self::assertTrue($result['supervisor_enabled']);
        self::assertSame(16, $result['worker_count']);
    }

    public function testExplicitDirectUsesReusePortWhenAvailable(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 'auto', 'mode' => 'io'],
            ['direct' => true],
            $this->profile([
                'os_family' => 'Linux',
                'cpu_cores' => 8,
                'memory_mb' => 8192,
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('optimal', $result['status']);
        self::assertSame('direct', $result['topology']);
        self::assertFalse($result['dispatcher_enabled']);
        self::assertTrue($result['direct_reuse_port']);
    }

    public function testAutoUsesDirectEvenWithSingleWorker(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 1, 'mode' => 'io'],
            [],
            $this->profile([
                'os_family' => 'Linux',
                'cpu_cores' => 2,
                'memory_mb' => 1024,
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('optimal', $result['status']);
        self::assertSame('direct', $result['topology']);
        self::assertFalse($result['dispatcher_enabled']);
        self::assertTrue($result['direct_reuse_port']);
        self::assertSame(1, $result['worker_count']);
    }

    public function testExplicitNoDispatcherUsesDeprecatedIndependentTopologyWithSingleWorker(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 1, 'mode' => 'io'],
            ['no-dispatcher' => true],
            $this->profile([
                'os_family' => 'Linux',
                'cpu_cores' => 2,
                'memory_mb' => 1024,
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('compatibility', $result['status']);
        self::assertSame('independent', $result['topology']);
        self::assertFalse($result['dispatcher_enabled']);
        self::assertFalse($result['direct_reuse_port']);
        self::assertContains('Independent topology is deprecated; use direct or Dispatcher topology.', $result['warnings']);
    }

    public function testWindowsAutoUsesDispatcherAndStableWorkerCount(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 'auto', 'mode' => 'io'],
            [],
            $this->profile([
                'os_family' => 'Windows',
                'cpu_cores' => 16,
                'memory_mb' => 32600,
                'supports_reuse_port' => false,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('compatibility', $result['status']);
        self::assertSame('dispatcher', $result['topology']);
        self::assertSame(8, $result['worker_count']);
        self::assertFalse($result['supervisor_enabled']);
        self::assertSame(
            'auto disabled on Windows; legacy control plane avoids Supervisor reconnect churn',
            $result['supervisor_reason']
        );
        self::assertContains(
            'Supervisor is disabled automatically on Windows; use --supervisor=on only when validating Supervisor HA.',
            $result['warnings']
        );
    }

    public function testExplicitDirectFailsWhenReusePortIsUnavailable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows supports only WLS Dispatcher topology');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['direct' => true],
            $this->profile([
                'os_family' => 'Windows',
                'cpu_cores' => 4,
                'supports_reuse_port' => false,
            ])
        );
    }

    public function testAutoFallsBackToSelectWithPerformanceWarningWhenEventIsMissing(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4, 'event_loop' => 'auto', 'runtime' => ['topology' => 'dispatcher']],
            ['dispatcher' => true],
            $this->profile([
                'os_family' => 'Linux',
                'cpu_cores' => 4,
                'memory_mb' => 4096,
                'supports_reuse_port' => true,
                'event_classes_available' => false,
                'extensions' => ['event' => false],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('select', $result['event_loop_driver']);
        self::assertContains('PHP event extension is missing; stream_select compatibility mode is slower.', $result['warnings']);
    }

    public function testAutoUsesDirectOnDarwin(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            [],
            $this->profile([
                'os_family' => 'Darwin',
                'supports_reuse_port' => true,
                'supports_direct_listener' => true,
                'direct_listener_mode' => 'shared_fd',
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('direct', $result['effective_topology']);
        self::assertSame('shared_fd', $result['direct_listener_mode']);
        self::assertFalse($result['direct_reuse_port']);
        self::assertSame('posix_auto_direct', $result['topology_reason_codes'][0]);
    }

    public function testDarwinDirectFailsWhenSharedListenerDistributionProbeFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shared listener consumers were not balanced');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            [],
            $this->profile([
                'os_family' => 'Darwin',
                'supports_reuse_port' => true,
                'supports_direct_listener' => false,
                'direct_listener_mode' => '',
                'direct_listener_probe' => [
                    'reason' => 'Darwin shared listener consumers were not balanced.',
                ],
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );
    }

    public function testExplicitDispatcherOverridesPosixAuto(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['dispatcher' => true],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('dispatcher', $result['topology']);
        self::assertTrue($result['dispatcher_enabled']);
    }

    public function testWindowsRejectsIndependentTopology(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows supports only WLS Dispatcher topology');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 1],
            ['no-dispatcher' => true],
            $this->profile(['os_family' => 'Windows'])
        );
    }

    public function testConflictingTopologyFlagsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conflicting WLS topology CLI options');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4],
            ['direct' => true, 'dispatcher' => true],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
            ])
        );
    }

    public function testDirectRejectsEventBufferSslEngineDuringPreflight(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support wls.ssl.engine=event_buffer');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4, 'ssl' => ['engine' => 'event_buffer']],
            [],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );
    }

    public function testHttpsDirectRejectsMissingOpenSslExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires the PHP OpenSSL extension');

        (new RuntimeStrategyResolver())->resolve(
            ['worker_count' => 4, 'https' => true],
            [],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true, 'openssl' => false],
                'functions' => ['proc_open' => true],
            ])
        );
    }

    public function testRuntimeTopologyTakesPrecedenceOverLegacyTopology(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            [
                'worker_count' => 4,
                'topology' => 'direct',
                'runtime' => ['topology' => 'dispatcher'],
            ],
            [],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('dispatcher', $result['topology']);
        self::assertSame('wls.runtime.topology', $result['topology_source']);
    }

    public function testInstanceExplicitAutoOverridesLegacyGlobalDispatcher(): void
    {
        $result = (new RuntimeStrategyResolver())->resolve(
            [
                'worker_count' => 4,
                'topology' => 'dispatcher',
                'runtime' => ['topology' => 'auto'],
                '_instance_topology_explicit' => true,
            ],
            [],
            $this->profile([
                'supports_reuse_port' => true,
                'event_classes_available' => true,
                'extensions' => ['event' => true],
                'functions' => ['proc_open' => true],
            ])
        );

        self::assertSame('direct', $result['topology']);
        self::assertSame('instance.runtime.topology', $result['topology_source']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function profile(array $overrides): WlsRuntimeProfile
    {
        return new WlsRuntimeProfile($overrides + [
            'os_family' => 'Linux',
            'cpu_cores' => 4,
            'memory_mb' => 2048,
            'supports_reuse_port' => false,
            'event_classes_available' => false,
            'extensions' => [],
            'functions' => [],
            'windows_tools' => [],
        ]);
    }
}
