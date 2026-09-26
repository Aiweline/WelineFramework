<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\Gateway\EdgeRuntimeDecision;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayStartupBootstrapperInterface;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Gateway\GatewayStartupHostInterface;
use Weline\Server\Service\Edge\Gateway\ManagedEdgeAvailabilityInterface;

/**
 * auto 模式的第三出口：宿主无 Nginx 且本项目托管 Nginx 已安装时自建项目级 Nginx 边缘。
 */
final class GatewayStartupManagedEdgeAutoTest extends TestCase
{
    public function testAutoOwnsManagedNginxEdgeWhenHostNginxIsAbsent(): void
    {
        $edge = new FakeManagedEdgeAvailability(hostOccupied: false, edgeReady: true);
        $decision = $this->decide(GatewayStartupDecision::MODE_AUTO, $edge);

        self::assertSame(GatewayStartupDecision::MODE_LEGACY, $decision->mode);
        self::assertSame(EdgeAdapterInterface::NAME_NGINX, $decision->adapter);
        self::assertSame(EdgeRuntimeDecision::SCOPE_LEGACY, $decision->scope);
        self::assertSame(GatewayStartupDecision::MODE_AUTO, $decision->requestedMode);
        self::assertSame([], $decision->portLease);
        self::assertSame(0, $decision->fallbackPort);
        self::assertSame('', $decision->fallbackReason);
        self::assertFalse($decision->isGateway());
        self::assertFalse($decision->isAutoFallback());
        self::assertSame(1, $edge->hostProbes);
        self::assertSame(1, $edge->readyProbes);
    }

    public function testAutoFallsBackToPureWlsWhenHostNginxOccupiesTheEdge(): void
    {
        $edge = new FakeManagedEdgeAvailability(
            hostOccupied: true,
            edgeReady: true,
            unavailable: 'host Nginx is present; WLS will not contend for the public edge',
        );
        $decision = $this->decide(GatewayStartupDecision::MODE_AUTO, $edge);

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertTrue($decision->isAutoFallback());
        self::assertStringContainsString('PACKAGE_UNAVAILABLE', $decision->fallbackReason);
        self::assertStringContainsString('host Nginx is present', $decision->fallbackReason);
        self::assertSame(0, $edge->readyProbes, '宿主已占用边缘时不必再问托管 Nginx 是否就绪');
    }

    public function testAutoFallsBackToPureWlsWhenManagedNginxIsNotReady(): void
    {
        $edge = new FakeManagedEdgeAvailability(
            hostOccupied: false,
            edgeReady: false,
            unavailable: 'project-managed Nginx is not installed; run server:nginx:install explicitly',
        );
        $decision = $this->decide(GatewayStartupDecision::MODE_AUTO, $edge);

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertStringContainsString('not installed', $decision->fallbackReason);
        self::assertStringContainsString('PACKAGE_UNAVAILABLE', $decision->fallbackReason);
    }

    public function testAutoStillJoinsTrustedHostGatewayBeforeOwningManagedNginx(): void
    {
        $edge = new FakeManagedEdgeAvailability(hostOccupied: false, edgeReady: true);
        $host = new FakeManagedEdgeHost();
        $host->status = self::trustedStatus();
        $decision = $this->decide(GatewayStartupDecision::MODE_AUTO, $edge, $host);

        self::assertSame(GatewayStartupDecision::MODE_GATEWAY, $decision->mode);
        self::assertTrue($decision->isGateway());
        self::assertSame(0, $edge->hostProbes, '命中可信宿主网关时不得再走托管 Nginx');
        self::assertSame(0, $edge->readyProbes);
    }

    public function testExplicitGatewayNeverFallsBackToManagedNginx(): void
    {
        $edge = new FakeManagedEdgeAvailability(hostOccupied: false, edgeReady: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PACKAGE_UNAVAILABLE');
        try {
            $this->decide(GatewayStartupDecision::MODE_GATEWAY, $edge);
        } finally {
            self::assertSame(0, $edge->hostProbes, '显式 gateway 模式不得降级到托管 Nginx');
        }
    }

    public function testPureWlsNeverConsultsManagedEdgeAvailability(): void
    {
        $edge = new FakeManagedEdgeAvailability(hostOccupied: false, edgeReady: true);
        $decision = $this->decide(GatewayStartupDecision::MODE_WLS, $edge);

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertSame(0, $edge->hostProbes);
        self::assertSame(0, $edge->readyProbes);
    }

    private function decide(
        string $mode,
        ManagedEdgeAvailabilityInterface $edge,
        ?FakeManagedEdgeHost $host = null,
    ): EdgeRuntimeDecision {
        return (new GatewayStartupDecision(
            $host ?? new FakeManagedEdgeHost(),
            null,
            new FakeManagedEdgeBootstrapper([
                'ok' => false,
                'ready' => false,
                'state' => 'PACKAGE_UNAVAILABLE',
                'reason' => 'No signed project gateway release package.',
            ]),
            $edge,
        ))->decide(
            $mode,
            'managed-edge-project',
            false,
            reserveListener: false,
            deadlineMonotonic: self::deadline(),
        );
    }

    /** @return array<string,mixed> */
    private static function trustedStatus(): array
    {
        return [
            'ok' => true,
            'ready' => true,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => GatewayPaths::PROTOCOL,
            'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL,
            'security_profile' => GatewayPaths::SECURITY_PROFILE,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => \str_repeat('a', 32),
            'host_boot_id' => \str_repeat('b', 64),
            'public_http' => 18080,
            'public_https' => 18443,
            'state' => 'READY',
            'reason' => 'trusted',
            'data_plane' => ['running' => true],
        ];
    }

    private static function deadline(): float
    {
        return (\hrtime(true) / 1_000_000_000) + 30.0;
    }
}

final class FakeManagedEdgeAvailability implements ManagedEdgeAvailabilityInterface
{
    public int $hostProbes = 0;

    public int $readyProbes = 0;

    public function __construct(
        private readonly bool $hostOccupied,
        private readonly bool $edgeReady,
        private readonly string $unavailable = 'managed Nginx edge unavailable in test',
    ) {
    }

    public function hostNginxOccupied(): bool
    {
        ++$this->hostProbes;
        return $this->hostOccupied;
    }

    public function managedNginxReady(): bool
    {
        ++$this->readyProbes;
        return $this->edgeReady;
    }

    public function unavailableReason(): string
    {
        return $this->unavailable;
    }
}

final class FakeManagedEdgeHost implements GatewayStartupHostInterface
{
    /** @var array<string,mixed> */
    public array $status = ['ok' => false, 'ready' => false, 'reason' => 'missing'];

    /** @var array<string,mixed> */
    public array $prepared = [
        'ok' => false,
        'ready' => false,
        'state' => 'PACKAGE_UNAVAILABLE',
        'reason' => 'No signed project gateway release package.',
        'data_plane' => ['running' => false],
    ];

    public function status(
        float $transientRetrySeconds = 0.0,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->status;
    }

    public function prepare(
        ?array $observedStatus = null,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->prepared;
    }
}

final class FakeManagedEdgeBootstrapper implements GatewayStartupBootstrapperInterface
{
    /** @param array<string,mixed> $result */
    public function __construct(private readonly array $result)
    {
    }

    public function bootstrap(array $observedStatus, float $deadlineMonotonic): array
    {
        return $this->result;
    }
}
