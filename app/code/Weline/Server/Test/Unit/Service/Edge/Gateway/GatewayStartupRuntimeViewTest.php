<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView;

final class GatewayStartupRuntimeViewTest extends TestCase
{
    public function testAutoWlsIntentIsNotMisclassifiedAsStandalonePureWls(): void
    {
        $view = GatewayStartupRuntimeView::resolveObserved(
            $this->endpoint('auto', 'wls', 'wls'),
            false,
            false,
        );

        self::assertSame(GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS, $view['source']);
        self::assertSame(GatewayStartupRuntimeView::READY_ACTION_NONE, $view['ready_action']);
    }

    public function testFreshGatewayProjectionWinsOverAutoWlsStartupIntent(): void
    {
        $view = GatewayStartupRuntimeView::resolveObserved(
            $this->endpoint('auto', 'wls', 'wls'),
            true,
            false,
        );

        self::assertSame(GatewayStartupRuntimeView::SOURCE_GATEWAY, $view['source']);
        self::assertTrue($view['public_proven']);
        self::assertSame(GatewayStartupRuntimeView::READY_ACTION_NONE, $view['ready_action']);
    }

    public function testGatewayStartupIntentRegistersOnlyAfterWlsReady(): void
    {
        $view = GatewayStartupRuntimeView::resolveObserved(
            $this->endpoint('auto', 'gateway', 'nginx'),
            false,
            false,
        );

        self::assertSame(GatewayStartupRuntimeView::SOURCE_GATEWAY_PENDING, $view['source']);
        self::assertSame(
            GatewayStartupRuntimeView::READY_ACTION_REGISTER_GATEWAY,
            $view['ready_action'],
        );
    }

    public function testDrainedAutoNativeEdgeWithoutFreshGatewayProofFailsClosed(): void
    {
        $endpoint = $this->endpoint('auto', 'wls', 'wls');
        $endpoint['gateway']['native_edge'] = ['state' => 'DRAINED'];
        $view = GatewayStartupRuntimeView::resolveObserved(
            $endpoint,
            false,
            false,
        );

        self::assertSame(GatewayStartupRuntimeView::SOURCE_UNKNOWN, $view['source']);
        self::assertSame(GatewayStartupRuntimeView::READY_ACTION_REJECT, $view['ready_action']);
    }

    public function testLegacyNginxKeepsItsManagedPublicationAction(): void
    {
        $view = GatewayStartupRuntimeView::resolveObserved(
            $this->endpoint('legacy', 'legacy', 'nginx'),
            false,
            false,
        );

        self::assertSame(GatewayStartupRuntimeView::SOURCE_MANAGED_NGINX, $view['source']);
        self::assertSame(
            GatewayStartupRuntimeView::READY_ACTION_START_MANAGED_NGINX,
            $view['ready_action'],
        );
    }

    public function testContradictoryRequestedAndEffectiveModesFailClosed(): void
    {
        $gatewayEndpoint = $this->endpoint('wls', 'gateway', 'nginx');
        $gatewayEndpoint['gateway']['protocol'] = GatewayPaths::PROTOCOL;
        $gatewayMismatch = GatewayStartupRuntimeView::resolveObserved(
            $gatewayEndpoint,
            false,
            false,
        );
        self::assertSame(GatewayStartupRuntimeView::SOURCE_UNKNOWN, $gatewayMismatch['source']);
        self::assertSame(GatewayStartupRuntimeView::READY_ACTION_REJECT, $gatewayMismatch['ready_action']);

        $legacyMismatch = GatewayStartupRuntimeView::resolveObserved(
            $this->endpoint('auto', 'legacy', 'nginx'),
            false,
            false,
        );
        self::assertSame(GatewayStartupRuntimeView::SOURCE_UNKNOWN, $legacyMismatch['source']);
        self::assertSame(GatewayStartupRuntimeView::READY_ACTION_REJECT, $legacyMismatch['ready_action']);
    }

    /** @return array<string,mixed> */
    private function endpoint(string $requested, string $mode, string $adapter): array
    {
        return [
            'edge_adapter' => $adapter,
            'gateway' => [
                'requested_mode' => $requested,
                'mode' => $mode,
                'protocol' => \in_array($requested, ['auto', 'gateway'], true)
                    ? GatewayPaths::PROTOCOL
                    : '',
            ],
        ];
    }
}
