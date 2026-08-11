<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxLiveProbe;

final class ManagedNginxLifecycleDeadlineTest extends TestCase
{
    public function testExpiredLifecycleDeadlineStopsEveryLiveProbeBeforeIo(): void
    {
        $service = new ManagedNginxService();
        $deadline = new \ReflectionProperty(
            ManagedNginxService::class,
            'activeLifecycleDeadlineMonotonic',
        );
        $deadline->setValue(
            $service,
            (\hrtime(true) / 1_000_000_000) - 1.0,
        );
        $backendIdentity = [
            'topology' => 'direct',
            'listener_mode' => 'direct',
            'upstream_port' => 22080,
            'upstream_ports' => [22080],
            'worker_port' => 22080,
            'worker_count' => 1,
        ];
        $started = \hrtime(true);

        self::assertFalse((new \ReflectionMethod(
            ManagedNginxService::class,
            'probeWlsBackendPool',
        ))->invoke(
            $service,
            '127.0.0.1',
            [22080],
            'deadline-instance',
            $backendIdentity,
        ));
        self::assertFalse((new \ReflectionMethod(
            ManagedNginxService::class,
            'probeConfigGeneration',
        ))->invoke($service, 22080, \str_repeat('a', 32)));
        self::assertFalse((new \ReflectionMethod(
            ManagedNginxService::class,
            'probeTls13',
        ))->invoke(
            $service,
            22443,
            ['deadline.example.test'],
            \str_repeat('b', 32),
            \str_repeat('c', 64),
        ));
        self::assertFalse((new \ReflectionMethod(
            ManagedNginxService::class,
            'verifyHttpRuntime',
        ))->invoke(
            $service,
            '1.1',
            22443,
            ['deadline.example.test'],
            \str_repeat('d', 32),
            'deadline-instance',
            22080,
        ));

        self::assertLessThan(
            250_000_000,
            \hrtime(true) - $started,
            'An expired lifecycle deadline must not perform a connect, read, or retry sleep.',
        );
    }

    public function testLiveProbeExpiredDeadlineReportsZeroAttempts(): void
    {
        $started = \hrtime(true);
        $result = (new NginxLiveProbe())->probeHttp(
            address: '127.0.0.1',
            port: 22080,
            host: 'localhost',
            path: '/_wls/health',
            maxAttempts: 60,
            requiredConsecutive: 8,
            deadlineMonotonic: (\hrtime(true) / 1_000_000_000) - 1.0,
        );

        self::assertFalse($result['ok']);
        self::assertSame(0, $result['attempts']);
        self::assertStringContainsString(
            'deadline was exhausted',
            \strtolower($result['reason']),
        );
        self::assertLessThan(250_000_000, \hrtime(true) - $started);
    }

    public function testEveryManagedProbeConsumesTheActiveAbsoluteDeadline(): void
    {
        $reflection = new \ReflectionClass(ManagedNginxService::class);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $methodSource = static function (string $method) use ($lines): string {
            $reflection = new \ReflectionMethod(ManagedNginxService::class, $method);
            return \implode('', \array_slice(
                $lines,
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            ));
        };

        self::assertStringContainsString(
            'deadlineMonotonic: $this->activeLifecycleDeadlineMonotonic',
            $methodSource('probeConfigGeneration'),
        );
        foreach ([
            'probeWlsBackendPool',
            'probeWlsBackend',
            'probeTls13',
            'verifyHttpRuntime',
            'verifyHttp3Runtime',
        ] as $method) {
            $source = $methodSource($method);
            self::assertTrue(
                \str_contains($source, 'lifecycleDeadlineAvailable()')
                    || \str_contains($source, 'remainingLifecycleDeadline(')
                    || \str_contains($source, 'remainingLifecycleMilliseconds('),
                $method . ' must consume the active lifecycle deadline.',
            );
        }
        $source = (string)\file_get_contents($file);
        self::assertStringNotContainsString(
            'SchedulerSystem::usleep(100_000)',
            $source,
        );
        self::assertStringContainsString(
            'CURLOPT_TIMEOUT_MS => $remainingMilliseconds',
            $source,
        );
        self::assertStringContainsString(
            '$waitTimeoutSeconds,' . "\n" . '                $deadlineMonotonic,',
            $methodSource('withLifecycleLock'),
        );
    }
}
