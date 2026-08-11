<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBackendIngressProtocol;

final class GatewayBackendIngressProtocolTest extends TestCase
{
    public function testWireHeaderNamesRemainExact(): void
    {
        self::assertSame('X-WLS-Edge-Token', GatewayBackendIngressProtocol::AUTH_HEADER);
        self::assertSame('x-wls-edge-token', GatewayBackendIngressProtocol::AUTH_HEADER_KEY);
        self::assertSame(
            'X-WLS-Client-Protocol',
            GatewayBackendIngressProtocol::CLIENT_PROTOCOL_HEADER,
        );
        self::assertSame(
            'x-wls-client-protocol',
            GatewayBackendIngressProtocol::CLIENT_PROTOCOL_HEADER_KEY,
        );
    }

    public function testLaunchersAndProcessesUseCanonicalBackendCredential(): void
    {
        $serverRoot = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
        foreach ([
            'Service/Provider/DispatcherProvider.php',
            'Service/Provider/GatewayJoinBackendProvider.php',
            'Service/Provider/MaintenanceWorkerProvider.php',
            'Service/Provider/WorkerProvider.php',
        ] as $relative) {
            $source = (string)\file_get_contents($serverRoot . $relative);
            self::assertStringContainsString('--gateway-backend-token-file=', $source);
        }
        foreach (['bin/dispatcher.php', 'bin/worker.php'] as $relative) {
            $source = (string)\file_get_contents($serverRoot . $relative);
            self::assertStringContainsString('--gateway-backend-token-file=', $source);
            self::assertStringContainsString('WLS_GATEWAY_BACKEND_TOKEN_FILE', $source);
        }
    }
}
