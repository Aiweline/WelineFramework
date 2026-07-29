<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeEndpointPublisher;

final class GatewayRuntimeEndpointPublisherTest extends TestCase
{
    public function testHealthyObservationChangesOnlyTheRuntimeProjection(): void
    {
        $endpoint = [
            'port' => 26439,
            'edge_adapter' => 'wls',
            'gateway' => [
                'mode' => 'wls',
                'project_uuid' => 'project-uuid',
                'certificate_generation' => 7,
                'degraded_reason' => 'GATEWAY_UNAVAILABLE',
                'fallback_state' => 'DEGRADED_WLS',
            ],
        ];
        $updated = GatewayRuntimeEndpointPublisher::applyHealthyObservation(
            $endpoint,
            $this->healthyStatus(),
            'DRAINING',
            1800000000,
        );

        self::assertSame(26439, $updated['port']);
        self::assertSame('wls', $updated['edge_adapter']);
        self::assertSame('project-uuid', $updated['gateway']['project_uuid']);
        self::assertSame(7, $updated['gateway']['certificate_generation']);
        self::assertSame('gateway', $updated['gateway']['mode']);
        self::assertSame('wls-edge/2', $updated['gateway']['protocol']);
        self::assertSame(80, $updated['gateway']['public_http']);
        self::assertSame(443, $updated['gateway']['public_https']);
        self::assertSame('', $updated['gateway']['degraded_reason']);
        self::assertSame('NATIVE_EDGE_DRAINING', $updated['gateway']['fallback_state']);
    }

    public function testFallbackObservationPreservesProjectFactsAndPublishesLease(): void
    {
        $updated = GatewayRuntimeEndpointPublisher::applyFallbackObservation(
            [
                'edge_adapter' => 'wls',
                'gateway' => [
                    'project_uuid' => 'project-uuid',
                    'certificate_generation' => 7,
                ],
            ],
            27673,
            'GATEWAY_DATA_PLANE_UNAVAILABLE',
            1800000000,
        );

        self::assertSame('wls', $updated['edge_adapter']);
        self::assertSame('project-uuid', $updated['gateway']['project_uuid']);
        self::assertSame(7, $updated['gateway']['certificate_generation']);
        self::assertSame('wls', $updated['gateway']['mode']);
        self::assertSame(0, $updated['gateway']['public_http']);
        self::assertSame(27673, $updated['gateway']['public_https']);
        self::assertSame('DEGRADED_WLS', $updated['gateway']['fallback_state']);
    }

    public function testUnauthenticatedHealthyObservationIsRejected(): void
    {
        $status = $this->healthyStatus();
        $status['supervisor_ready'] = false;

        $this->expectException(\RuntimeException::class);
        GatewayRuntimeEndpointPublisher::applyHealthyObservation(
            [],
            $status,
            'ACTIVE',
            1800000000,
        );
    }

    /** @return array<string,mixed> */
    private function healthyStatus(): array
    {
        return [
            'ok' => true,
            'ready' => true,
            'supervisor_ready' => true,
            'state' => 'HEALTHY',
            'protocol' => 'wls-edge/2',
            'epoch' => \str_repeat('a', 32),
            'generation' => 88,
            'public_http' => 80,
            'public_https' => 443,
            'data_plane' => ['running' => true],
        ];
    }
}
