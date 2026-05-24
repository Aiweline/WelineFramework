<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\Runtime\WlsRuntimeProfile;

final class RuntimeStrategyResolverTest extends TestCase
{
    public function testAutoSelectsDirectOnUnixWhenReusePortAndEventAreAvailable(): void
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
        self::assertSame('direct', $result['topology']);
        self::assertTrue($result['direct_reuse_port']);
        self::assertSame('event', $result['event_loop_driver']);
        self::assertTrue($result['supervisor_enabled']);
        self::assertSame(16, $result['worker_count']);
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
        self::assertTrue($result['supervisor_enabled']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testExplicitDirectFailsWhenReusePortIsUnavailable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SO_REUSEPORT');

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
            ['worker_count' => 4, 'event_loop' => 'auto'],
            [],
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
