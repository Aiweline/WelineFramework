<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Runtime\RuntimeSelection;

final class ServiceContextGatewayEdgeTest extends TestCase
{
    private const PROJECT_UUID = '1fe48cb3-0a52-4056-8ac8-1e1ffd3bc143';
    private const GATEWAY_EPOCH = '03e6067353ca80282e2b42912b77b030';

    public function testAuthenticatedHostGatewayUsesLoopbackH1WithoutProjectNginxOwnership(): void
    {
        $context = $this->context(
            edge: [
                'adapter' => 'nginx',
                'mode' => 'gateway',
                'scope' => 'host_gateway',
                'nginx' => [
                    'managed' => false,
                    'auto_start' => false,
                ],
            ],
            gateway: [
                'protocol' => 'wls-edge/2',
                'project_uuid' => self::PROJECT_UUID,
                'epoch' => self::GATEWAY_EPOCH,
            ],
        );

        self::assertSame('gateway', $context->getConfig('wls.edge.mode'));
        self::assertSame('host_gateway', $context->getConfig('wls.edge.scope'));
    }

    public function testHostGatewayCannotBypassOwnershipWithoutAuthenticatedIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('authenticated wls-edge/2 project and epoch metadata');

        $this->context(
            edge: [
                'adapter' => 'nginx',
                'mode' => 'gateway',
                'scope' => 'host_gateway',
            ],
            gateway: [
                'protocol' => 'wls-edge/2',
                'project_uuid' => self::PROJECT_UUID,
                'epoch' => '',
            ],
        );
    }

    public function testLegacyNginxStillRequiresManagedAutoStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('managed=true and auto_start=true');

        $this->context(
            edge: [
                'adapter' => 'nginx',
                'mode' => 'legacy',
                'scope' => 'legacy',
                'nginx' => [
                    'managed' => false,
                    'auto_start' => false,
                ],
            ],
            gateway: [
                'protocol' => 'wls-edge/2',
                'project_uuid' => self::PROJECT_UUID,
                'epoch' => self::GATEWAY_EPOCH,
            ],
        );
    }

    private function context(array $edge, array $gateway): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'gateway-context-test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 29620,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'dispatcher',
                'effective_topology' => 'dispatcher',
                'topology_source' => 'unit-test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'none',
                'listener_mode' => 'single',
                'policy_compatible' => true,
                'reason_codes' => ['unit_test'],
                'reason' => 'unit test gateway backend',
            ]),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => $edge,
                    'gateway' => $gateway,
                    'http' => [
                        'protocols' => ['h1'],
                        'preferred' => 'h1',
                        'protocol_edge' => 'disabled',
                        'tls_session_resumption' => false,
                        'alt_svc' => false,
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 14694,
            workerPort: 44314,
            publicHost: 'gateway.example.test',
        );
    }
}
