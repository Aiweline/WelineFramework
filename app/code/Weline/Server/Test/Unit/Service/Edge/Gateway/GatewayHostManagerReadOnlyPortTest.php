<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

final class GatewayHostManagerReadOnlyPortTest extends TestCase
{
    public function testKernelListenerTableObservesAnEphemeralListener(): void
    {
        $listener = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($listener, $error !== '' ? $error : (string)$errno);
        try {
            $address = \stream_socket_get_name($listener, false);
            self::assertIsString($address);
            $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
            $method = new \ReflectionMethod(
                GatewayHostManager::class,
                'readOnlyPublicListenerTable',
            );
            $observation = $method->invoke(new GatewayHostManager(), [$port]);
            if (($observation['known'] ?? false) !== true) {
                self::markTestSkipped('No bounded kernel listener-table tool is available.');
            }
            self::assertTrue((bool)($observation['occupied'][$port] ?? false));
        } finally {
            @\fclose($listener);
        }
    }

    public function testListenerTableFindsNonLoopbackListenersWithoutPidVisibility(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'parseReadOnlyListenerTable',
        );
        $occupied = $method->invoke(null, <<<'TABLE'
LISTEN 0 4096 192.0.2.44:18080 0.0.0.0:*
tcp4 0 0 198.51.100.20.18443 *.* LISTEN
TCP 203.0.113.10:19090 0.0.0.0:0 LISTENING 0
TABLE, [18080, 18443, 19090, 19999]);

        self::assertSame([
            18080 => true,
            18443 => true,
            19090 => true,
        ], $occupied);
    }

    public function testUnknownInspectionNeverBecomesAvailable(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'classifyReadOnlyListenerObservation',
        );

        self::assertTrue($method->invoke(null, 0, false, true));
        self::assertNull($method->invoke(null, 0, false, null));
        self::assertFalse($method->invoke(null, 0, false, false));
        self::assertTrue($method->invoke(null, 4321, false, false));
    }

    public function testReadOnlyDiscoveryRejectsAnExpiredAbsoluteDeadlineBeforeIo(): void
    {
        $manager = new GatewayHostManager();
        $deadline = (\hrtime(true) / 1_000_000_000) - 1.0;
        foreach ([
            ['readOnlyPublicListenerTable', [[65534], $deadline]],
            ['readOnlyLoopbackListenerProbe', ['ipv4', 65534, $deadline]],
        ] as [$methodName, $arguments]) {
            try {
                (new \ReflectionMethod(GatewayHostManager::class, $methodName))
                    ->invokeArgs($manager, $arguments);
                self::fail($methodName . ' must reject an expired deadline.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'deadline was exhausted',
                    $exception->getMessage(),
                );
            }
        }

        $source = (string)\file_get_contents(
            (new \ReflectionClass(GatewayHostManager::class))->getFileName(),
        );
        self::assertStringNotContainsString(
            'Processer::getProcessIdByPort',
            $source,
        );
    }
}
