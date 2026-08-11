<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayStartupBootstrapperInterface;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Gateway\GatewayStartupHostInterface;

final class GatewayStartupAutoBootstrapTest extends TestCase
{
    public function testAutoEstablishesFirstTrustedGatewayAndJoinsIt(): void
    {
        $host = new FakeGatewayStartupHost();
        $bootstrapper = new FakeGatewayStartupBootstrapper(self::trustedStatus());
        $deadline = self::deadline();
        $decision = (new GatewayStartupDecision(
            $host,
            null,
            $bootstrapper,
        ))->decide(
            GatewayStartupDecision::MODE_AUTO,
            'first-project',
            false,
            reserveListener: false,
            deadlineMonotonic: $deadline,
        );

        self::assertSame(GatewayStartupDecision::MODE_GATEWAY, $decision->mode);
        self::assertSame(1, $bootstrapper->calls);
        self::assertSame('INSTALL_REQUIRED', $bootstrapper->observed['state']);
        self::assertSame(18080, $decision->gateway['public_http']);
        self::assertSame(18443, $decision->gateway['public_https']);
        self::assertLessThan($deadline, $host->statusDeadlines[0]);
        self::assertSame($deadline, $host->prepareDeadlines[0]);
        self::assertSame($deadline, $bootstrapper->deadlines[0]);
    }

    public function testAutoFallsBackWhenSignedProjectPackageIsUnavailable(): void
    {
        $host = new FakeGatewayStartupHost();
        $bootstrapper = new FakeGatewayStartupBootstrapper([
            'ok' => false,
            'ready' => false,
            'state' => 'PACKAGE_UNAVAILABLE',
            'reason' => 'No signed project gateway release package.',
        ]);
        $decision = (new GatewayStartupDecision(
            $host,
            null,
            $bootstrapper,
        ))->decide(
            GatewayStartupDecision::MODE_AUTO,
            'first-project',
            false,
            reserveListener: false,
            deadlineMonotonic: self::deadline(),
        );

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertStringContainsString('PACKAGE_UNAVAILABLE', $decision->fallbackReason);
        self::assertSame(1, $bootstrapper->calls);
    }

    public function testExplicitGatewayEstablishesOrFailsInsteadOfFallingBack(): void
    {
        $host = new FakeGatewayStartupHost();
        $bootstrapper = new FakeGatewayStartupBootstrapper([
            'ok' => false,
            'ready' => false,
            'state' => 'PORT_PERMISSION',
            'reason' => 'Administrator rights are unavailable.',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PORT_PERMISSION');
        try {
            (new GatewayStartupDecision(
                $host,
                null,
                $bootstrapper,
            ))->decide(
                GatewayStartupDecision::MODE_GATEWAY,
                'first-project',
                false,
                reserveListener: false,
                deadlineMonotonic: self::deadline(),
            );
        } finally {
            self::assertSame(1, $bootstrapper->calls);
        }
    }

    public function testPureWlsNeverDiscoversOrBootstrapsGateway(): void
    {
        $host = new FakeGatewayStartupHost();
        $bootstrapper = new FakeGatewayStartupBootstrapper(self::trustedStatus());
        $decision = (new GatewayStartupDecision(
            $host,
            null,
            $bootstrapper,
        ))->decide(
            GatewayStartupDecision::MODE_WLS,
            'standalone-project',
            false,
            reserveListener: false,
            deadlineMonotonic: self::deadline(),
        );

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertSame(0, $host->statusCalls);
        self::assertSame(0, $host->prepareCalls);
        self::assertSame(0, $bootstrapper->calls);
    }

    public function testUnknownPortOwnerNeverInvokesBootstrapper(): void
    {
        $host = new FakeGatewayStartupHost();
        $host->prepared = [
            'ok' => false,
            'ready' => false,
            'state' => 'PORT_TAKEN',
            'reason' => 'Unknown listener owns 80/443; WLS will not modify it.',
            'owner' => 'unknown',
        ];
        $bootstrapper = new FakeGatewayStartupBootstrapper(self::trustedStatus());
        $decision = (new GatewayStartupDecision(
            $host,
            null,
            $bootstrapper,
        ))->decide(
            GatewayStartupDecision::MODE_AUTO,
            'safe-project',
            false,
            reserveListener: false,
            deadlineMonotonic: self::deadline(),
        );

        self::assertSame(GatewayStartupDecision::MODE_WLS, $decision->mode);
        self::assertSame(0, $bootstrapper->calls);
        self::assertStringContainsString('PORT_TAKEN', $decision->fallbackReason);
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

final class FakeGatewayStartupHost implements GatewayStartupHostInterface
{
    /** @var array<string,mixed> */
    public array $status = ['ok' => false, 'ready' => false, 'reason' => 'missing'];

    /** @var array<string,mixed> */
    public array $prepared = [
        'ok' => false,
        'ready' => false,
        'state' => 'INSTALL_REQUIRED',
        'reason' => 'safe public ports and no installed gateway',
        'listen_profile' => 'default',
    ];

    public int $statusCalls = 0;
    public int $prepareCalls = 0;

    /** @var list<float> */
    public array $statusDeadlines = [];

    /** @var list<float> */
    public array $prepareDeadlines = [];

    public function status(
        float $transientRetrySeconds = 0.0,
        ?float $deadlineMonotonic = null,
    ): array {
        ++$this->statusCalls;
        $this->statusDeadlines[] = (float)$deadlineMonotonic;
        return $this->status;
    }

    public function prepare(
        ?array $observedStatus = null,
        ?float $deadlineMonotonic = null,
    ): array {
        ++$this->prepareCalls;
        $this->prepareDeadlines[] = (float)$deadlineMonotonic;
        return $this->prepared;
    }
}

final class FakeGatewayStartupBootstrapper implements GatewayStartupBootstrapperInterface
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    public array $observed = [];

    /** @var list<float> */
    public array $deadlines = [];

    /** @param array<string,mixed> $result */
    public function __construct(private readonly array $result)
    {
    }

    public function bootstrap(array $observedStatus, float $deadlineMonotonic): array
    {
        ++$this->calls;
        $this->observed = $observedStatus;
        $this->deadlines[] = $deadlineMonotonic;
        return $this->result;
    }
}
