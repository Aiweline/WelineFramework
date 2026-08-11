<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayInitialBootstrapCoordinator;
use Weline\Server\Service\Edge\Gateway\GatewayInitialBootstrapOperationsInterface;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayInitialBootstrapCoordinatorTest extends TestCase
{
    public function testMissingProjectReleasePackageFailsClosedBeforeHostMutation(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->package = [
            'ok' => false,
            'state' => 'PACKAGE_UNAVAILABLE',
            'reason' => 'No signed package was distributed for this target.',
            'path' => '',
            'target_profile' => 'darwin-arm64',
        ];

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PACKAGE_UNAVAILABLE', $result['state']);
        self::assertSame(1, $operations->resolveCalls);
        self::assertSame(0, $operations->lockCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testConcurrentTrustedWinnerIsJoinedBeforeLoserPackageDiscovery(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = self::trustedStatus();
        $operations->package = [
            'ok' => false,
            'state' => 'PACKAGE_UNAVAILABLE',
            'reason' => 'This losing project release omitted the overlay.',
            'path' => '',
            'project_root' => '',
            'target_profile' => 'darwin-arm64',
        ];

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertTrue($result['ok']);
        self::assertSame('concurrent_gateway', $result['bootstrap_result']);
        self::assertSame(1, $operations->statusCalls);
        self::assertSame(0, $operations->resolveCalls);
        self::assertSame(0, $operations->preflightCalls);
        self::assertSame(0, $operations->lockCalls);
    }

    public function testConcurrentWinnerIsJoinedWithoutSecondInstall(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = self::trustedStatus();

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertTrue($result['ok']);
        self::assertSame('concurrent_gateway', $result['bootstrap_result']);
        self::assertSame(0, $operations->lockCalls);
        self::assertSame(1, $operations->statusCalls);
        self::assertSame(0, $operations->prepareCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testFirstProjectInstallsExactlyOneSignedPackageInsideHostLock(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = ['ok' => false, 'reason' => 'not installed'];
        $operations->prepared = [
            'ok' => false,
            'ready' => false,
            'state' => 'INSTALL_REQUIRED',
            'reason' => 'safe to establish',
            'listen_profile' => 'default',
        ];
        $operations->installed = self::trustedStatus();

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertTrue($result['ok']);
        self::assertSame('established', $result['bootstrap_result']);
        self::assertSame(1, $operations->lockCalls);
        self::assertSame(1, $operations->installCalls);
        self::assertSame('/project/extend/server/wls-gateway/darwin-arm64', $operations->installedPackage);
        self::assertSame('default', $operations->installedProfile);
        self::assertCount(2, $operations->preflightDeadlines);
        self::assertSame($operations->preflightDeadlines[0], $operations->preflightDeadlines[1]);
        self::assertSame($operations->preflightDeadlines[0], $operations->lockDeadlines[0]);
        self::assertSame($operations->preflightDeadlines[0], $operations->statusDeadlines[0]);
        self::assertSame($operations->preflightDeadlines[0], $operations->prepareDeadlines[0]);
        self::assertSame($operations->preflightDeadlines[0], $operations->installDeadlines[0]);
        self::assertSame(2, $operations->resolveCalls);
    }

    public function testPackageParentReplacementInsideBootstrapLockFailsBeforeHostInspectionOrWrite(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->packageAfterLock = \array_replace($operations->package, [
            'path' => '/replacement/extend/server/wls-gateway/darwin-arm64',
            'project_root' => '/replacement',
        ]);

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PACKAGE_CHANGED', $result['state']);
        self::assertSame(2, $operations->resolveCalls);
        self::assertSame(2, $operations->preflightCalls);
        self::assertSame(1, $operations->lockCalls);
        self::assertSame(1, $operations->statusCalls);
        self::assertSame(0, $operations->prepareCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testSignatureDigestReplacementInsideBootstrapLockFailsBeforeHostInspectionOrWrite(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->verificationAfterLock = [
            'package_dir' => $operations->package['path'],
            'package_digest' => \str_repeat('c', 64),
            'manifest_digest' => \str_repeat('c', 64),
            'signature_digest' => \str_repeat('d', 64),
        ];

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PACKAGE_CHANGED', $result['state']);
        self::assertSame(1, $operations->statusCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testPersistedIpv4OnlyProfileCannotAuthorizeVirginFirstInstall(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->prepared['listen_profile'] = 'ipv4-only';

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('REPAIR_REQUIRED', $result['state']);
        self::assertSame(0, $operations->installCalls);
    }

    public function testInvalidPackageFailsBeforeCreatingTheHostBootstrapLock(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->preflightFailure = new \RuntimeException(
            'Production install requires a release-ready production package.',
        );

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PACKAGE_INVALID', $result['state']);
        self::assertStringContainsString('release-ready', $result['reason']);
        self::assertSame(1, $operations->preflightCalls);
        self::assertSame(0, $operations->lockCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testProfileMismatchFailsBeforeCreatingTheHostBootstrapLock(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->preflightFailure = new \RuntimeException(
            'Gateway package does not support the requested listen profile.',
        );

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PACKAGE_INVALID', $result['state']);
        self::assertStringContainsString('listen profile', $result['reason']);
        self::assertSame(1, $operations->preflightCalls);
        self::assertSame(0, $operations->lockCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testPartialInstallNeverBecomesATrustedGateway(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = ['ok' => false, 'reason' => 'not installed'];
        $operations->installed = [
            'ok' => true,
            'ready' => false,
            'release_ready' => false,
            'state' => 'TEST_PACKAGE_INSTALLED',
            'reason' => 'not a trusted production gateway',
        ];

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertFalse($result['ok']);
        self::assertSame('BOOTSTRAP_UNTRUSTED', $result['state']);
        self::assertSame(1, $operations->installCalls);
        self::assertSame(3, $operations->statusCalls);
    }

    public function testReadyControlPlaneWithoutRunningDataPlaneNeverBecomesGateway(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $down = self::trustedStatus();
        $down['ready'] = false;
        $down['state'] = 'DATA_PLANE_DOWN';
        $down['reason'] = 'Nginx public listeners are not ready.';
        $down['data_plane'] = ['running' => false];
        $operations->status = ['ok' => false, 'reason' => 'not installed'];
        $operations->installed = $down;

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertFalse($result['ok']);
        self::assertSame('BOOTSTRAP_UNTRUSTED', $result['state']);
        self::assertSame('DATA_PLANE_DOWN', $result['install_state']);
        self::assertSame(1, $operations->installCalls);
    }

    public function testInterruptedConcurrentWinnerIsRecheckedAndNeverRestaged(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = [
            'ok' => false,
            'ready' => false,
            'state' => 'GATEWAY_STARTING',
            'reason' => 'A previous winner is still recovering.',
        ];
        $operations->prepared = $operations->status;

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('GATEWAY_STARTING', $result['state']);
        self::assertSame('not_installable', $result['bootstrap_result']);
        self::assertSame(2, $operations->statusCalls);
        self::assertSame(1, $operations->prepareCalls);
        self::assertSame(0, $operations->installCalls);
    }

    public function testLockWaitTimeoutUsesTheOriginalAbsoluteDeadlineAndFallsBack(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->lockFailure = new \RuntimeException(
            'Timed out acquiring the host-gateway initial bootstrap lock.',
        );
        $deadline = self::deadline();

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            $deadline,
        );

        self::assertSame('BOOTSTRAP_UNAVAILABLE', $result['state']);
        self::assertSame([$deadline], $operations->preflightDeadlines);
        self::assertSame([$deadline], $operations->lockDeadlines);
        self::assertSame(0, $operations->installCalls);
    }

    public function testPortOwnerRaceAfterElectionNeverRunsInstaller(): void
    {
        $operations = new FakeInitialBootstrapOperations();
        $operations->status = ['ok' => false, 'reason' => 'not installed'];
        $operations->prepared = [
            'ok' => false,
            'ready' => false,
            'state' => 'PORT_TAKEN',
            'reason' => 'Unknown listener owns a public port.',
            'owner' => 'unknown',
        ];

        $result = (new GatewayInitialBootstrapCoordinator($operations))->bootstrap(
            ['ok' => false, 'state' => 'INSTALL_REQUIRED'],
            self::deadline(),
        );

        self::assertSame('PORT_TAKEN', $result['state']);
        self::assertSame(0, $operations->installCalls);
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
            'data_plane' => ['running' => true],
        ];
    }

    private static function deadline(): float
    {
        return (\hrtime(true) / 1_000_000_000) + 5.0;
    }
}

final class FakeInitialBootstrapOperations implements GatewayInitialBootstrapOperationsInterface
{
    /** @var array<string,mixed> */
    public array $package = [
        'ok' => true,
        'state' => 'AVAILABLE',
        'reason' => 'signed project release package found',
        'path' => '/project/extend/server/wls-gateway/darwin-arm64',
        'project_root' => '/project',
        'target_profile' => 'darwin-arm64',
    ];

    /** @var array<string,mixed>|null */
    public ?array $packageAfterLock = null;

    /** @var array<string,mixed>|null */
    public ?array $verificationAfterLock = null;

    /** @var array<string,mixed> */
    public array $status = ['ok' => false, 'reason' => 'not installed'];

    /** @var array<string,mixed> */
    public array $prepared = [
        'ok' => false,
        'ready' => false,
        'state' => 'INSTALL_REQUIRED',
        'reason' => 'safe to establish',
        'listen_profile' => 'default',
    ];

    /** @var array<string,mixed> */
    public array $installed = [];

    public int $resolveCalls = 0;
    public int $preflightCalls = 0;
    public int $lockCalls = 0;
    public int $statusCalls = 0;
    public int $prepareCalls = 0;
    public int $installCalls = 0;
    public string $installedPackage = '';
    public string $installedProfile = '';
    public ?\Throwable $preflightFailure = null;
    public ?\Throwable $lockFailure = null;

    /** @var list<float> */
    public array $preflightDeadlines = [];

    /** @var list<float> */
    public array $lockDeadlines = [];

    /** @var list<float> */
    public array $statusDeadlines = [];

    /** @var list<float> */
    public array $prepareDeadlines = [];

    /** @var list<float> */
    public array $installDeadlines = [];

    public function resolveProjectReleasePackage(): array
    {
        ++$this->resolveCalls;
        if ($this->resolveCalls > 1 && $this->packageAfterLock !== null) {
            return $this->packageAfterLock;
        }
        return $this->package;
    }

    public function synchronized(\Closure $callback, float $deadlineMonotonic): mixed
    {
        ++$this->lockCalls;
        $this->lockDeadlines[] = $deadlineMonotonic;
        if ($this->lockFailure !== null) {
            throw $this->lockFailure;
        }
        return $callback();
    }

    public function preflightProjectReleasePackage(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array {
        ++$this->preflightCalls;
        $this->preflightDeadlines[] = $deadlineMonotonic;
        if ($this->preflightFailure !== null) {
            throw $this->preflightFailure;
        }
        if ($this->preflightCalls > 1 && $this->verificationAfterLock !== null) {
            return $this->verificationAfterLock;
        }
        return [
            'package_dir' => $packageDirectory,
            'package_digest' => \str_repeat('c', 64),
            'manifest_digest' => \str_repeat('c', 64),
            'signature_digest' => \str_repeat('e', 64),
        ];
    }

    public function status(float $deadlineMonotonic): array
    {
        ++$this->statusCalls;
        $this->statusDeadlines[] = $deadlineMonotonic;
        return $this->status;
    }

    public function prepare(array $observedStatus, float $deadlineMonotonic): array
    {
        ++$this->prepareCalls;
        $this->prepareDeadlines[] = $deadlineMonotonic;
        return $this->prepared;
    }

    public function install(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array {
        ++$this->installCalls;
        $this->installDeadlines[] = $deadlineMonotonic;
        $this->installedPackage = $packageDirectory;
        $this->installedProfile = $profile;
        return $this->installed;
    }
}
