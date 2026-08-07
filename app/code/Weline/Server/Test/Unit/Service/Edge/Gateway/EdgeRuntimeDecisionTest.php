<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
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
            portLease: self::stableLease(),
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

    public function testExplicitPureWlsCannotResolveToGateway(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requested/resolved mode transition');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_NGINX,
            requestedMode: GatewayStartupDecision::MODE_WLS,
            mode: GatewayStartupDecision::MODE_GATEWAY,
            scope: EdgeRuntimeDecision::SCOPE_HOST_GATEWAY,
            source: 'test',
            reason: 'Contradictory persisted decision.',
        );
    }

    public function testLegacyIntentCannotResolveToPureWls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requested/resolved mode transition');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_LEGACY,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Contradictory persisted decision.',
        );
    }

    public function testLegacyModeRequiresLegacyNginxScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy mode requires the legacy Nginx scope');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_LEGACY,
            mode: GatewayStartupDecision::MODE_LEGACY,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Contradictory persisted decision.',
        );
    }

    public function testGatewayModeCannotCarryAProjectPublicLease(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only pure WLS mode may carry a public port lease');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_NGINX,
            requestedMode: GatewayStartupDecision::MODE_GATEWAY,
            mode: GatewayStartupDecision::MODE_GATEWAY,
            scope: EdgeRuntimeDecision::SCOPE_HOST_GATEWAY,
            source: 'test',
            reason: 'Contradictory persisted decision.',
            fallbackPort: 24567,
            portLease: self::stableLease(),
        );
    }

    public function testAdvertisedFallbackPortRequiresABoundLease(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a retained public port lease');

        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Gateway unavailable.',
            fallbackReason: 'PORT_TAKEN',
            fallbackPort: 24567,
        );
    }

    public function testCurrentLeaseRequiresItsProjectIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public port lease is invalid');

        $lease = self::stableLease();
        unset($lease['project_uuid']);
        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Gateway unavailable.',
            fallbackReason: 'PORT_TAKEN',
            fallbackPort: 24567,
            portLease: $lease,
        );
    }

    public function testCurrentLeaseRejectsCoercedNumericFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public port lease is invalid');

        $lease = self::stableLease();
        $lease['schema_version'] = (string)GatewayPortLeaseAllocator::SCHEMA_VERSION;
        new EdgeRuntimeDecision(
            adapter: EdgeAdapterInterface::NAME_WLS,
            requestedMode: GatewayStartupDecision::MODE_AUTO,
            mode: GatewayStartupDecision::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: 'test',
            reason: 'Gateway unavailable.',
            fallbackReason: 'PORT_TAKEN',
            fallbackPort: 24567,
            portLease: $lease,
        );
    }

    public function testLegacySchemaFiveLeaseMustBeReallocated(): void
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
                'schema_version' => 5,
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

    /** @return array<string,mixed> */
    private static function stableLease(): array
    {
        return [
            'schema_version' => GatewayPortLeaseAllocator::SCHEMA_VERSION,
            'allocation_scope' => 'stable_range',
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174000',
            'port' => 24567,
            'state' => 'RESERVED',
            'lease_id' => \str_repeat('a', 32),
            'instance' => 'default',
            'bind_host' => '127.0.0.1',
        ];
    }
}
