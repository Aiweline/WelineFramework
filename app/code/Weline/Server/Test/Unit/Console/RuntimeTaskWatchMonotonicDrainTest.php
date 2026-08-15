<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class RuntimeTaskWatchMonotonicDrainTest extends TestCase
{
    public function testShutdownDrainNeverUsesWallClockDuration(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Runtime/Task/Watch.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('\\hrtime(true)', $source);
        self::assertStringNotContainsString('microtime(true)', $source);
    }

    public function testWlsWatchdogPublishesItsChildOwnedPidBeforeCredentialResolution(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Runtime/Task/Watch.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);

        $registration = \strpos($source, 'WorkerProcessLease::register(');
        $credential = \strpos($source, 'resolveProtectedCredentialFromArguments(');

        self::assertIsInt($registration);
        self::assertIsInt($credential);
        self::assertLessThan($credential, $registration);
    }

    public function testManagedWlsChildrenPublishTheirPidBeforeCredentialResolution(): void
    {
        $serverRoot = \dirname(__DIR__, 3);
        foreach ([
            'dispatcher.php' => 'Processer::setPid($managedIdentity',
            'worker.php' => 'WorkerProcessLease::register(',
            'worker_ssl.php' => 'WorkerProcessLease::register(',
        ] as $script => $registrationNeedle) {
            $source = \file_get_contents($serverRoot . '/bin/' . $script);
            self::assertIsString($source, $script);

            $autoload = \strpos($source, "require_once BP . 'app'");
            $registration = \strpos($source, $registrationNeedle);
            $credential = \strpos($source, 'resolveProtectedCredentialFromArguments(');

            self::assertIsInt($autoload, $script);
            self::assertIsInt($registration, $script);
            self::assertIsInt($credential, $script);
            self::assertGreaterThan($autoload, $registration, $script);
            self::assertLessThan($credential, $registration, $script);
        }
    }
}
