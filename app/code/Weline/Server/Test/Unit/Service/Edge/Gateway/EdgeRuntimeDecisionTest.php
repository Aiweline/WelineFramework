<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;

final class EdgeRuntimeDecisionTest extends TestCase
{
    public function testRoundTripPreservesAutomaticFallbackFacts(): void
    {
        $decision = new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Gateway unavailable.',
            fallbackReason: 'PORT_TAKEN: unknown owner',
            fallbackPort: 24567,
            gateway: ['state' => 'PORT_TAKEN'],
        );

        $restored = EdgeRuntimeDecision::fromArray($decision->toArray());

        self::assertSame($decision->toArray(), $restored->toArray());
        self::assertTrue($restored->isAutoFallback());
        self::assertFalse($restored->isGateway());
    }

    public function testGatewayModeRejectsProjectScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_NGINX,
            requestedMode: GatewayStartupDecision::MODE_GATEWAY,
            mode: GatewayStartupDecision::MODE_GATEWAY,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Invalid scope.',
        );
    }

    public function testAutomaticFallbackRequiresReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Unavailable.',
        );
    }

    public function testLegacySchemaFourLeaseMustBeReallocated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public port lease is invalid');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Gateway unavailable.',
            fallbackReason: 'PORT_TAKEN',
            fallbackPort: 24567,
            portLease: [
                'schema_version' => 4,
                'port' => 24567,
                'state' => 'RESERVED',
                'lease_id' => \str_repeat('a', 32),
                'instance' => 'default',
                'bind_host' => '127.0.0.1',
            ],
        );
    }

    public function testStartupRequiresTrustedControlButCanReplayWhileDataPlaneRecovers(): void
    {
        $method = new \ReflectionMethod(
            GatewayStartupDecision::class,
            'shouldJoinTrustedGateway',
        );

        self::assertTrue($method->invoke(
            null,
            GatewayStartupDecision::MODE_AUTO,
            true,
        ));
        self::assertTrue($method->invoke(
            null,
            GatewayStartupDecision::MODE_GATEWAY,
            true,
        ));
        self::assertFalse($method->invoke(
            null,
            GatewayStartupDecision::MODE_AUTO,
            false,
        ));
    }
}
