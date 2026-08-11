<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SslCertificateService;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

final class StartCommandArgsSolidificationTest extends TestCase
{
    public function testNoSslFlagForcesHttpOnlyMode(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', ['no-ssl' => true]);

        self::assertTrue((bool)($config['no_ssl'] ?? false));
    }

    public function testExplicitPureWlsPersistsItsActiveCertificateSource(): void
    {
        $start = $this->createProbe();

        self::assertTrue($start->shouldPersistCertificateSource(
            sslEnabled: true,
            certificatePending: false,
            gatewayMode: false,
            pureWlsMode: true,
            requestedMode: 'wls',
        ));
        self::assertFalse($start->shouldPersistCertificateSource(
            sslEnabled: true,
            certificatePending: false,
            gatewayMode: false,
            pureWlsMode: false,
            requestedMode: 'legacy',
        ));
    }

    public function testNoSslSkipsManagedWildcardCertificatePreparation(): void
    {
        $start = $this->createProbe();
        $start->resolveConfig('default', ['no-ssl' => true]);

        self::assertSame(0, $start->managedWildcardCertificateCalls);
    }

    public function testWls2ReusablePrimaryCertificateDefersLegacyWildcardMutation(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('canReuseConfiguredCertificate')->willReturn(true);
        $sslService->method('certificateMatchesHost')->willReturn(true);
        ObjectManager::setInstance(SslCertificateService::class, $sslService);
        $start = new StartConfigProbe([
            'host' => 'unit-test.weline.test',
            'ssl_cert' => '/tmp/unit-primary-cert.pem',
            'ssl_key' => '/tmp/unit-primary-key.pem',
            'edge_mode' => 'wls',
        ]);

        $start->resolveConfig('default', []);

        self::assertSame(0, $start->managedWildcardCertificateCalls);
    }

    public function testHttpOnlyAliasAlsoForcesHttpOnlyMode(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', ['http-only' => true]);

        self::assertTrue((bool)($config['no_ssl'] ?? false));
    }

    public function testStartupCertificateFilesReenableHttpsForReusablePublicHostCertificate(): void
    {
        $certDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-start-cert-' . \str_replace('.', '', \uniqid('', true)) . DIRECTORY_SEPARATOR;
        \mkdir($certDir, 0777, true);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        \file_put_contents($certPath, 'unit-cert');
        \file_put_contents($keyPath, 'unit-key');

        try {
            $sslService = $this->createMock(SslCertificateService::class);
            $sslService->expects($this->once())
                ->method('getCertificateDir')
                ->with('pre.example.com')
                ->willReturn($certDir);
            $sslService->expects($this->once())
                ->method('canReuseConfiguredCertificate')
                ->with($certPath, $keyPath)
                ->willReturn(true);
            $sslService->expects($this->once())
                ->method('certificateMatchesHost')
                ->with($certPath, 'pre.example.com')
                ->willReturn(true);
            $sslService->expects($this->once())
                ->method('parseCertificate')
                ->with($certPath)
                ->willReturn(['issuer' => 'Unit CA', 'expires_at' => '2026-12-31 00:00:00']);

            $result = $this->createProbe()->useStartupCertificateFiles($sslService, 'pre.example.com', 'pre.example.com');

            self::assertIsArray($result);
            self::assertTrue((bool)($result['success'] ?? false));
            self::assertTrue((bool)($result['ssl_enabled'] ?? false));
            self::assertSame($certPath, $result['cert_path'] ?? null);
            self::assertSame($keyPath, $result['key_path'] ?? null);
            self::assertTrue((bool)($result['storage_sync_deferred'] ?? false));
        } finally {
            @\unlink($certPath);
            @\unlink($keyPath);
            @\rmdir($certDir);
        }
    }

    public function testNoDaemonRunsForegroundUnlessRestartRequested(): void
    {
        $start = $this->createProbe();

        $foregroundConfig = $start->resolveConfig('default', ['no-daemon' => true]);
        self::assertFalse((bool)($foregroundConfig['daemon'] ?? true));

        $restartConfig = $start->resolveConfig('default', ['no-daemon' => true, 'r' => true]);
        self::assertTrue((bool)($restartConfig['daemon'] ?? false));
    }

    public function testPublicEdgeCliModesExcludeInternalLegacyMode(): void
    {
        $start = $this->createProbe();

        self::assertSame('auto', $start->normalizeEdgeCli(' AUTO '));
        self::assertSame('gateway', $start->normalizeEdgeCli('gateway'));
        self::assertSame('wls', $start->normalizeEdgeCli('WLS'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('legacy');
        $start->normalizeEdgeCli('legacy');
    }

    public function testUnknownPublicEdgeCliModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('auto');

        $this->createProbe()->normalizeEdgeCli('system-nginx');
    }

    public function testLegacyAdapterIntentIsMappedAcrossSavedAndEnvShapes(): void
    {
        $cases = [
            'saved flat wls' => [['edge_adapter' => 'wls'], [], 'default', 'wls'],
            'saved nested wls' => [['edge' => ['adapter' => 'wls']], [], 'default', 'wls'],
            'saved flat nginx' => [['edge_adapter' => 'nginx'], [], 'default', 'legacy'],
            'saved nested nginx' => [['edge' => ['adapter' => 'nginx']], [], 'default', 'legacy'],
            'env flat wls' => [null, ['wls' => ['edge_adapter' => 'wls']], 'default', 'wls'],
            'env nested nginx' => [null, ['wls' => ['edge' => ['adapter' => 'nginx']]], 'default', 'legacy'],
            'instance env flat nginx' => [
                null,
                ['wls' => ['servers' => ['shop' => ['edge_adapter' => 'nginx']]]],
                'shop',
                'legacy',
            ],
            'instance env nested wls' => [
                null,
                ['wls' => ['servers' => ['shop' => ['edge' => ['adapter' => 'wls']]]]],
                'shop',
                'wls',
            ],
        ];

        foreach ($cases as $label => [$saved, $env, $instance, $expected]) {
            $config = $this->createProbe($saved, $env)->resolveConfig(
                $instance,
                ['no-ssl' => true],
            );
            self::assertSame($expected, $config['edge_mode'] ?? null, $label);
            self::assertSame(
                $expected === 'wls' ? 'wls' : 'nginx',
                $config['edge_adapter'] ?? null,
                $label,
            );
        }
    }

    public function testEdgeIntentPriorityIsCliThenSavedThenInstanceEnvThenBaseEnv(): void
    {
        $env = ['wls' => [
            'edge_adapter' => 'nginx',
            'servers' => ['shop' => ['edge_adapter' => 'wls']],
        ]];
        $instanceEnv = $this->createProbe(null, $env)->resolveConfig(
            'shop',
            ['no-ssl' => true],
        );
        self::assertSame('wls', $instanceEnv['edge_mode'] ?? null);

        $saved = $this->createProbe(['edge_adapter' => 'nginx'], $env)
            ->resolveConfig('shop', ['no-ssl' => true]);
        self::assertSame('legacy', $saved['edge_mode'] ?? null);

        $cli = $this->createProbe(['edge_adapter' => 'nginx'], $env)
            ->resolveConfig('shop', ['edge' => 'gateway', 'no-ssl' => true]);
        self::assertSame('gateway', $cli['edge_mode'] ?? null);
        self::assertSame('nginx', $cli['edge_adapter'] ?? null);
    }

    public function testExplicitModeOutranksLegacyAdapterWithinTheSameLayer(): void
    {
        $config = $this->createProbe([
            'edge_mode' => 'auto',
            'edge_adapter' => 'nginx',
        ])->resolveConfig('default', ['no-ssl' => true]);

        self::assertSame('auto', $config['edge']['mode'] ?? null);
        self::assertSame('nginx', $config['edge_adapter'] ?? null);
    }

    public function testDefaultNginxAdapterDoesNotImplicitlySelectLegacyMode(): void
    {
        $config = $this->createProbe()->resolveConfig(
            'default',
            ['no-ssl' => true],
        );

        self::assertSame('auto', $config['edge']['mode'] ?? null);
    }

    public function testStartupFallbackKeepsRedispatchingTheSameEnvelopeUntilProjection(): void
    {
        $method = new \ReflectionMethod(
            Start::class,
            'requestAutoGatewayStartupFallback',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertGreaterThanOrEqual(
            2,
            \substr_count($source, 'sendAutoGatewayStartupFallbackRequest('),
            'A forwarded request must be replayed after an Agent reconnect until a serving projection exists.',
        );
        self::assertStringContainsString(
            'startupFallbackRequestRedispatchSeconds()',
            $source,
        );
        self::assertStringNotContainsString("['request_id'] =", $source);
        self::assertStringNotContainsString("['request_digest'] =", $source);
        $manifestAt = \strpos(
            $source,
            'buildAutoGatewayStartupFallbackServingManifest(',
        );
        $issueAt = \strpos($source, 'GatewayStartupFallbackRequest::issue(');
        self::assertIsInt($manifestAt);
        self::assertIsInt($issueAt);
        self::assertLessThan(
            $issueAt,
            $manifestAt,
            'Project TLS serving truth must be published before the request is issued.',
        );
        self::assertStringContainsString(
            'activeCertificateFenceForDomain(',
            $source,
        );
        self::assertStringContainsString(
            'fallbackServingObservation($latest, $projectionDeadline)',
            $source,
        );
        self::assertSame(
            2,
            \substr_count($source, 'sleepWithinStartupFallbackDeadline('),
            'Both fallback polling loops must clip their sleep to the owning absolute deadline.',
        );
        self::assertStringNotContainsString(
            'SchedulerSystem::usleep(100_000)',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/sendAutoGatewayStartupFallbackRequest\(\s*\$instanceName,\s*'
                . '\$request,\s*\$requestDeadline,\s*\)/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/sendAutoGatewayStartupFallbackRequest\(\s*\$instanceName,\s*'
                . '\$request,\s*\$projectionDeadline,\s*\)/s',
            $source,
        );
        $dispatch = new \ReflectionMethod(
            Start::class,
            'sendAutoGatewayStartupFallbackRequest',
        );
        $dispatchSource = \implode('', \array_slice(
            $lines,
            $dispatch->getStartLine() - 1,
            $dispatch->getEndLine() - $dispatch->getStartLine() + 1,
        ));
        self::assertMatchesRegularExpression(
            '/->command\(\s*\$instanceName,.*?1\.0,\s*'
                . '\$deadlineMonotonic,\s*\)/s',
            $dispatchSource,
        );
        self::assertStringNotContainsString('ProjectCertificateGenerationStore', $source);
    }

    public function testStartupFallbackSleepNeverExceedsRemainingDeadlineBudget(): void
    {
        $bounded = new \ReflectionMethod(
            Start::class,
            'boundedStartupFallbackSleepMicroseconds',
        );

        self::assertSame(100_000, $bounded->invoke(null, 10.5, 10.0));
        self::assertSame(100_000, $bounded->invoke(null, PHP_FLOAT_MAX, 10.0));
        self::assertSame(62_500, $bounded->invoke(null, 10.0625, 10.0));
        self::assertSame(0, $bounded->invoke(null, 10.0, 10.0));
        self::assertSame(0, $bounded->invoke(null, 9.0, 10.0));
        self::assertSame(0, $bounded->invoke(null, INF, 10.0));
    }

    public function testExistingStartupPollDeadlinesClipTheirFinalSleep(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Start::class))->getFileName(),
        );

        self::assertGreaterThanOrEqual(
            5,
            \substr_count(
                $source,
                'boundedNanosecondDeadlineSleepMicroseconds(',
            ),
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count(
                $source,
                'boundedMonotonicDeadlineSleepMicroseconds(',
            ),
        );
        self::assertStringContainsString(
            '$sleepMilliseconds = \min($waitStepMs, $remainingWaitMs);',
            $source,
        );
        self::assertStringNotContainsString(
            'SchedulerSystem::usleep(300000)',
            $source,
        );
        self::assertStringNotContainsString(
            'SchedulerSystem::usleep(25_000)',
            $source,
        );
        self::assertStringNotContainsString(
            'SchedulerSystem::usleep(20_000)',
            $source,
        );

        $seconds = new \ReflectionMethod(
            Start::class,
            'boundedMonotonicDeadlineSleepMicroseconds',
        );
        self::assertSame(62_500, $seconds->invoke(null, 10.0625, 10.0, 300_000));
        self::assertSame(0, $seconds->invoke(null, 10.0, 10.0, 300_000));

        $nanoseconds = new \ReflectionMethod(
            Start::class,
            'boundedNanosecondDeadlineSleepMicroseconds',
        );
        self::assertSame(
            20_000,
            $nanoseconds->invoke(null, 100_000_000, 0, 20_000),
        );
        self::assertSame(
            15_625,
            $nanoseconds->invoke(null, 15_625_000, 0, 20_000),
        );
        self::assertSame(0, $nanoseconds->invoke(null, 100, 100, 20_000));
    }

    public function testStartupCertificateSelectorsShareBoundedAbsoluteDeadlines(): void
    {
        $start = (string)\file_get_contents(
            (string)(new \ReflectionClass(Start::class))->getFileName(),
        );

        self::assertStringContainsString(
            'STARTUP_CERTIFICATE_STATE_BUDGET_SECONDS = 8.0',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/->activate\([\s\S]*?\$certificateRoots,\s*'
                . '\$this->startupCertificateStateDeadline\(\),\s*'
                . '\$certificateTrustProfile,\s*'
                . '\$this->resolveCertificateProvider\(\$sslResult\),\s*\)/',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/->disabled\(\s*\$certificateHost,\s*\$deadlineMonotonic,\s*\)/',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/->active\(\s*\$certificateHost,\s*\$deadlineMonotonic,\s*'
                . '\$trustProfile,\s*\)/',
            $start,
        );
        self::assertStringContainsString(
            '->buildServingManifest($instanceName, $deadlineMonotonic)',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/activeProjectCertificateResult\(\s*\$certificateHost,\s*'
                . '\$sslService,\s*\$activeGeneration,\s*true,\s*'
                . '\$trustProfile,\s*\)/',
            $start,
        );
        self::assertStringContainsString(
            'Active certificate after-image is incomplete before edge launch.',
            $start,
        );
    }

    public function testLegacyCertificateSelectorMigrationRequiresCompleteExplicitPair(): void
    {
        $method = new \ReflectionMethod(
            Start::class,
            'hasExplicitCertificatePairForLegacySelectorMigration',
        );

        self::assertTrue($method->invoke(new Start(), [
            'ssl_cert' => '/project/app/etc/ssl/unit/fullchain.pem',
            'ssl_key' => '/project/app/etc/ssl/unit/privkey.pem',
        ]));
        self::assertFalse($method->invoke(new Start(), [
            'ssl_cert' => '/project/app/etc/ssl/unit/fullchain.pem',
            'ssl_key' => '',
        ]));
        self::assertFalse($method->invoke(new Start(), [
            'ssl_cert' => '',
            'ssl_key' => '/project/app/etc/ssl/unit/privkey.pem',
        ]));
        self::assertFalse($method->invoke(new Start(), []));

        $ensure = new \ReflectionMethod(Start::class, 'ensureSslCertificate');
        $source = (string)\file_get_contents((string)$ensure->getFileName());
        self::assertStringContainsString(
            '$this->hasExplicitCertificatePairForLegacySelectorMigration($config)',
            $source,
        );
    }

    public function testStartupListenerLeaseOperationsShareBoundedPhaseDeadlines(): void
    {
        $start = (string)\file_get_contents(
            (string)(new \ReflectionClass(Start::class))->getFileName(),
        );

        self::assertStringContainsString(
            'STARTUP_LISTENER_STATE_BUDGET_SECONDS = 120.0',
            $start,
        );
        self::assertStringContainsString(
            'STARTUP_LISTENER_CLEANUP_BUDGET_SECONDS = 1.0',
            $start,
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count(
                $start,
                'operationDeadlineMonotonic: $this->startupListenerStateDeadline()',
            ),
        );
        self::assertStringContainsString(
            'createGatewayStartupDecisionForListenerPhase()',
            $start,
        );
        self::assertStringContainsString(
            'operationDeadlineMonotonic: $cleanupDeadline',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/->projectUuid\(\s*\$this->startupListenerStateDeadline\(\),\s*\)/',
            $start,
        );
        self::assertMatchesRegularExpression(
            '/->advanceInstanceGeneration\(\s*\$instanceName,\s*'
                . 'deadlineMonotonic: \$this->startupListenerStateDeadline\(\),\s*\)/',
            $start,
        );
        $identityDeadlineAt = \strpos(
            $start,
            '$this->beginStartupListenerStateDeadline();',
        );
        $projectIdentityAt = \strpos(
            $start,
            'new \\Weline\\Server\\Service\\Edge\\Gateway\\ProjectIdentityStore()',
        );
        self::assertIsInt($identityDeadlineAt);
        self::assertIsInt($projectIdentityAt);
        self::assertLessThan($projectIdentityAt, $identityDeadlineAt);
        self::assertStringNotContainsString(
            'new \\Weline\\Server\\Service\\Edge\\Gateway\\GatewayPortLeaseAllocator();',
            $start,
        );
    }

    public function testShutdownFallbackRetiresGatewayBeforeKillingTheAgent(): void
    {
        $method = new \ReflectionMethod(
            Start::class,
            'shutdownCleanupOrphanWlsProcessesIfNeeded',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $retireAt = \strpos(
            $source,
            'retirePossibleGatewayRegistrationBeforeFailedStartupCleanup(',
        );
        $cleanupAt = \strpos($source, 'cleanupFailedStartupProcesses(');

        self::assertNotFalse($retireAt);
        self::assertNotFalse($cleanupAt);
        self::assertLessThan(
            $cleanupAt,
            $retireAt,
            'Host registration must be fenced while the exact Agent is still alive.',
        );
    }

    public function testGatewayWithoutPublicCertificateStartsChallengeOnly(): void
    {
        $result = $this->createProbe()->resolveMissingCertificate([
            'edge_mode' => 'gateway',
            'gateway' => ['mode' => 'gateway'],
        ], 'shop.example.com', false, false);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['success'] ?? false));
        self::assertFalse((bool)($result['ssl_enabled'] ?? true));
        self::assertTrue((bool)($result['pending_certificate'] ?? false));
        self::assertSame('PENDING_CERTIFICATE', $result['code'] ?? null);
        self::assertSame('', $result['cert_path'] ?? null);
        self::assertSame('', $result['key_path'] ?? null);
    }

    public function testGatewayPendingCertificateDoesNotInitializeProjectStorage(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->willReturn(false);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-pending-cert-does-not-exist' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::never())->method('ensureCertificateStorageReady');

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('gateway-pending', [
            'host' => 'shop.example.com',
            'public_host' => 'shop.example.com',
            'edge_mode' => 'gateway',
            'gateway' => ['mode' => 'gateway'],
        ]);

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertFalse((bool)($result['ssl_enabled'] ?? true));
        self::assertTrue((bool)($result['pending_certificate'] ?? false));
        self::assertSame('PENDING_CERTIFICATE', $result['code'] ?? null);
    }

    public function testPublicDevTldIsNotClassifiedAsLocalDevelopmentDomain(): void
    {
        $sslService = new SslCertificateService(true);

        self::assertFalse($sslService->isLocalDomain('shop.dev'));
        self::assertTrue($sslService->isLocalDomain('shop.test'));
        self::assertTrue($sslService->isLocalDomain('127.0.0.1'));
        self::assertTrue($sslService->isLocalDomain('192.168.10.20'));
        self::assertTrue($sslService->isLocalDomain('::1'));
        self::assertTrue($sslService->isLocalDomain('fd12:3456:789a::1'));
        self::assertTrue($sslService->isLocalDomain('fe80::1234'));
        self::assertTrue($sslService->isLocalDomain('::ffff:192.168.10.20'));
        self::assertFalse($sslService->isLocalDomain('8.8.8.8'));
        self::assertFalse($sslService->isLocalDomain('::ffff:8.8.8.8'));
        self::assertFalse($sslService->isLocalDomain('2001:4860:4860::8888'));
    }

    public function testPublicDomainNeedsExplicitDevelopmentProfileBeforePrivateDnsCanSelfSign(): void
    {
        $production = new SslCertificateDomainPolicyProbe(false, [
            'shop.dev' => ['127.0.0.1'],
            'missing.dev' => [],
        ]);
        $development = new SslCertificateDomainPolicyProbe(true, [
            'shop.dev' => ['127.0.0.1'],
            'missing.dev' => [],
        ]);

        self::assertFalse($production->needsSelfSignedCertificate('shop.dev'));
        self::assertTrue($development->needsSelfSignedCertificate('shop.dev'));
        self::assertFalse($production->needsSelfSignedCertificate('missing.dev'));
        self::assertFalse($development->needsSelfSignedCertificate('missing.dev'));
        self::assertTrue($production->needsSelfSignedCertificate('shop.test'));
    }

    public function testPublicDevGatewayStartsChallengeOnlyWithoutSelfSigningOrRetirementReplay(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->with('shop.dev')->willReturn(false);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-public-dev-gateway-missing' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->with('shop.dev')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::never())->method('ensureCertificateStorageReady');
        $sslService->expects(self::never())->method('generateSelfSignedCertificate');

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('gateway-public-dev', [
            'host' => 'shop.dev',
            'public_host' => 'shop.dev',
            'edge_mode' => 'gateway',
            'gateway' => ['mode' => 'gateway'],
        ]);

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertSame('PENDING_CERTIFICATE', $result['code'] ?? null);
        self::assertTrue((bool)($result['pending_certificate'] ?? false));
    }

    public function testPublicDevPureWlsFailsClosedWithoutImplicitSelfSigning(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->with('shop.dev')->willReturn(false);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-public-dev-standalone-missing' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->with('shop.dev')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::once())->method('ensureCertificateStorageReady');
        $sslService->expects(self::never())->method('generateSelfSignedCertificate');

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('wls-public-dev', [
            'host' => 'shop.dev',
            'public_host' => 'shop.dev',
            'edge_mode' => 'wls',
            'gateway' => ['mode' => 'wls'],
        ]);

        self::assertFalse((bool)($result['success'] ?? true));
        self::assertSame('TLS_CERTIFICATE_UNAVAILABLE', $result['code'] ?? null);
    }

    public function testLocalWlsCertificateStorageIsPreparedExactlyOnce(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->with('shop.test')->willReturn(true);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-local-cert-missing' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->with('shop.test')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::once())->method('ensureCertificateStorageReady');
        $sslService->method('ensureCertificate')->willReturn([
            'success' => true,
            'cert_path' => '/tmp/wls-local-cert.pem',
            'key_path' => '/tmp/wls-local-key.pem',
            'issuer' => 'unit-local-ca',
            'is_new' => true,
        ]);

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('wls-local-cert', [
            'host' => 'shop.test',
            'public_host' => 'shop.test',
            'edge_mode' => 'wls',
            'certificate_profile' => 'test',
            'gateway' => ['mode' => 'wls'],
        ]);

        self::assertTrue((bool)($result['success'] ?? false));
    }

    public function testLegacyCertificateStorageIsPreparedExactlyOnce(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->with('shop.example.com')->willReturn(false);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-legacy-cert-missing' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->with('shop.example.com')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::once())->method('ensureCertificateStorageReady');
        $sslService->method('generateSelfSignedCertificate')->willReturn([
            'success' => true,
            'cert_path' => '/tmp/wls-legacy-cert.pem',
            'key_path' => '/tmp/wls-legacy-key.pem',
            'issuer' => 'unit-legacy-ca',
            'is_new' => true,
        ]);

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('legacy-cert', [
            'host' => 'shop.example.com',
            'public_host' => 'shop.example.com',
            'edge_mode' => 'legacy',
            'gateway' => ['mode' => 'legacy'],
        ]);

        self::assertTrue((bool)($result['success'] ?? false));
    }

    public function testGatewayConfigParsingWithMissingSavedCertificateDoesNotInitializeStorage(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->expects(self::never())->method('ensureCertificateStorageReady');

        $probe = new StartConfigProbe([
            'host' => 'shop.example.com',
            'public_host' => 'shop.example.com',
            'ssl_domain' => 'shop.example.com',
            'ssl_cert' => '/does/not/exist/fullchain.pem',
            'ssl_key' => '/does/not/exist/privkey.pem',
            'edge_mode' => 'gateway',
        ], [], $sslService);
        $config = $probe->resolveConfig('gateway-pending-config', []);

        self::assertSame('shop.example.com', $config['ssl_domain'] ?? null);
        self::assertTrue((bool)($config['_certificate_preparation_deferred'] ?? false));
        self::assertSame(0, $probe->localCertificateCalls);
        self::assertSame(0, $probe->managedWildcardCertificateCalls);
        self::assertSame(0, $probe->certificateMapCalls);
    }

    public function testPublicGatewaySkipsDeferredLocalCertificateMutation(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->willReturn(false);
        $sslService->expects(self::never())->method('ensureCertificateStorageReady');
        $probe = new StartConfigProbe(null, [], $sslService);

        $probe->completeCertificatePreparation('gateway-public', [
            'host' => 'shop.example.com',
            'public_host' => 'shop.example.com',
        ], true, 'shop.example.com');

        self::assertSame(0, $probe->localCertificateCalls);
        self::assertSame(0, $probe->managedWildcardCertificateCalls);
        self::assertSame(0, $probe->certificateMapCalls);
    }

    public function testPureWlsMissingCertificateAttemptsPostgresqlRestoreBeforeFailingClosed(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('needsSelfSignedCertificate')->willReturn(false);
        $sslService->method('getCertificateDir')->willReturn(
            \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-standalone-cert-does-not-exist' . DIRECTORY_SEPARATOR,
        );
        $sslService->method('hasValidLocalCertificate')->willReturn(false);
        $sslService->expects(self::never())->method('replayPendingCertificateRetirements');
        $sslService->expects(self::once())->method('ensureCertificateStorageReady');

        $result = (new StartConfigProbe(null, [], $sslService))->ensureSslResult('wls-no-cert', [
            'host' => 'shop.example.com',
            'public_host' => 'shop.example.com',
            'edge_mode' => 'wls',
            'gateway' => ['mode' => 'wls'],
        ]);

        self::assertFalse((bool)($result['success'] ?? true));
        self::assertSame('TLS_CERTIFICATE_UNAVAILABLE', $result['code'] ?? null);
    }

    public function testPureWlsWithoutPublicCertificateFailsClosed(): void
    {
        $result = $this->createProbe()->resolveMissingCertificate([
            'edge_mode' => 'wls',
            'gateway' => ['requested_mode' => 'auto'],
        ], 'shop.example.com', false, false);

        self::assertIsArray($result);
        self::assertFalse((bool)($result['success'] ?? true));
        self::assertFalse((bool)($result['pending_certificate'] ?? true));
        self::assertSame('TLS_CERTIFICATE_UNAVAILABLE', $result['code'] ?? null);
        self::assertStringContainsString(
            'TLS_CERTIFICATE_UNAVAILABLE',
            (string)($result['message'] ?? ''),
        );
    }

    public function testRetiredCertificateTombstoneStartsWithoutPemInHttpOnlyMode(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Console/Server/Start.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $method = new \ReflectionMethod(Start::class, 'ensureSslCertificate');
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            "'code' => 'TLS_CERTIFICATE_RETIRED_HTTP_ONLY'",
            $source,
        );
        self::assertStringContainsString("'success' => true", $source);
        self::assertStringContainsString("'cert_path' => ''", $source);
        self::assertStringContainsString("'key_path' => ''", $source);
        self::assertStringContainsString("'ssl_enabled' => false", $source);
    }

    public function testRetiredHttpOnlyExceptionIsLimitedToTheSharedGateway(): void
    {
        $source = (string)\file_get_contents(
            \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
                . 'app/code/Weline/Server/Console/Server/Start.php',
        );
        self::assertStringContainsString(
            '&& !($gatewayMode && $retiredHttpOnly)',
            $source,
        );
    }

    public function testGatewayDefaultBackendUsesDistinctHostCoordinatedLease(): void
    {
        $probe = $this->createProbe();

        self::assertSame(23456, $probe->resolveGatewayBackendPort(
            'shop',
            Start::DEFAULT_PORT,
            false,
            true,
        ));
        self::assertSame(23456, $probe->resolveGatewayBackendPort(
            'shop',
            Start::DEFAULT_PORT_FALLBACK,
            false,
            true,
        ));
        self::assertSame(9981, $probe->resolveGatewayBackendPort(
            'shop',
            9981,
            true,
            true,
        ));
        self::assertSame(9981, $probe->resolveGatewayBackendPort(
            'shop',
            9981,
            false,
            false,
        ));
        self::assertSame(3, $probe->gatewayBackendLeaseCalls);
    }

    public function testLocalExistingAndLegacyCertificatePoliciesRemainUnchanged(): void
    {
        $probe = $this->createProbe();

        self::assertNull($probe->resolveMissingCertificate(
            ['edge_mode' => 'wls', 'certificate_profile' => 'test'],
            'project.weline.test',
            true,
            false,
        ));
        $gatewayPending = $probe->resolveMissingCertificate(
            ['edge_mode' => 'gateway'],
            'shop.example.com',
            false,
            true,
        );
        self::assertIsArray($gatewayPending);
        self::assertSame('PENDING_CERTIFICATE', $gatewayPending['code'] ?? null);
        self::assertTrue((bool)($gatewayPending['pending_certificate'] ?? false));
        self::assertNull($probe->resolveMissingCertificate(
            ['edge_mode' => 'legacy'],
            'shop.example.com',
            false,
            false,
        ));
    }

    public function testGatewayPublicationGateUsesExactPrimaryDomain(): void
    {
        $probe = $this->createProbe();
        $routes = [
            ['domain' => 'other.example.com', 'status' => 'ACTIVE'],
            ['domain' => 'shop.example.com', 'status' => 'PENDING_CERTIFICATE'],
        ];

        $withoutPendingIntent = $probe->resolvePrimaryRouteGate(
            $routes,
            'Shop.Example.com.',
            false,
        );
        self::assertFalse($withoutPendingIntent['accepted']);
        self::assertFalse($withoutPendingIntent['active']);
        self::assertFalse($withoutPendingIntent['challenge_only']);
        self::assertSame(1, $withoutPendingIntent['active_count']);

        $challengeOnly = $probe->resolvePrimaryRouteGate(
            $routes,
            'Shop.Example.com.',
            true,
        );
        self::assertTrue($challengeOnly['accepted']);
        self::assertFalse($challengeOnly['active']);
        self::assertTrue($challengeOnly['challenge_only']);
        self::assertSame('PENDING_CERTIFICATE', $challengeOnly['primary_status']);
        self::assertSame('shop.example.com', $challengeOnly['public_host']);
    }

    public function testGatewayPublicationGateAcceptsPrimaryActiveAndRejectsMissingPrimary(): void
    {
        $probe = $this->createProbe();
        $active = $probe->resolvePrimaryRouteGate([
            ['domain' => 'shop.example.com', 'status' => 'ACTIVE'],
            ['domain' => 'other.example.com', 'status' => 'ACTIVE'],
        ], 'shop.example.com', false);
        self::assertTrue($active['accepted']);
        self::assertTrue($active['active']);
        self::assertSame(2, $active['active_count']);

        $missing = $probe->resolvePrimaryRouteGate([
            ['domain' => 'other.example.com', 'status' => 'ACTIVE'],
        ], 'shop.example.com', true);
        self::assertFalse($missing['accepted']);
        self::assertSame('', $missing['primary_status']);
    }

    public function testGatewayPublicationGateNormalizesUnicodePrimaryDomain(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('IDNA normalization requires ext-intl.');
        }
        $ascii = (string)\idn_to_ascii(
            'täst.example',
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46,
        );

        $gate = $this->createProbe()->resolvePrimaryRouteGate(
            [['domain' => $ascii, 'status' => 'PENDING_CERTIFICATE']],
            'TÄST.Example.',
            true,
        );

        self::assertTrue($gate['accepted']);
        self::assertTrue($gate['challenge_only']);
        self::assertSame($ascii, $gate['public_host']);
        self::assertSame(
            $ascii,
            $this->createProbe()->resolveCertificateDomain([], 'TÄST.Example.'),
            '证书目录、DNS 校验与 ACME 请求必须与网关路由使用同一 IDNA 身份',
        );
    }

    public function testWorkerCountAcceptsBothLongAndShortFlags(): void
    {
        $start = $this->createProbe();

        $longFlagConfig = $start->resolveConfig('default', ['count' => '6']);
        self::assertSame(6, (int)($longFlagConfig['worker_count'] ?? 0));

        $shortFlagConfig = $start->resolveConfig('default', ['c' => '5']);
        self::assertSame(5, (int)($shortFlagConfig['worker_count'] ?? 0));
    }

    public function testWorkerMemoryLimitAcceptsEnvAndCliFlags(): void
    {
        $envConfig = $this->createProbe(null, ['wls' => ['worker_memory_limit' => '384m']])
            ->resolveConfig('default', []);
        self::assertSame('384M', (string)($envConfig['worker_memory_limit'] ?? ''));

        $cliConfig = $this->createProbe(null, ['wls' => ['worker_memory_limit' => '384M']])
            ->resolveConfig('default', ['worker-memory-limit' => '768']);
        self::assertSame('768M', (string)($cliConfig['worker_memory_limit'] ?? ''));
    }

    public function testDispatcherMemoryLimitDefaultsToWorkerWhenSolidified(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfo('unit-memory');

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('512M', $info['worker_memory_limit'] ?? null);
        self::assertSame('512M', $info['dispatcher_memory_limit'] ?? null);
    }

    public function testSslCertAndKeyCanBeProvidedViaCliFlags(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', [
            'ssl-cert' => '/tmp/test-cert.pem',
            'ssl-key' => '/tmp/test-key.pem',
        ]);

        self::assertSame('/tmp/test-cert.pem', (string)($config['ssl_cert'] ?? ''));
        self::assertSame('/tmp/test-key.pem', (string)($config['ssl_key'] ?? ''));
    }

    public function testLegacyManagedLocalHostFallsBackToGeneratedProjectHost(): void
    {
        $start = $this->createProbe(
            ['host' => 'p11005ce4.weline.local', 'ssl_domain' => 'p11005ce4.weline.local']
        );
        $config = $start->resolveConfig('default', []);

        self::assertSame('unit-test.weline.test', (string)($config['host'] ?? ''));
        self::assertArrayNotHasKey('ssl_domain', $config);
    }

    public function testMissingHostAlsoFallsBackToGeneratedProjectHost(): void
    {
        $start = $this->createProbe(['host' => '', 'ssl_domain' => 'localhost']);
        $config = $start->resolveConfig('default', []);

        self::assertSame('unit-test.weline.test', (string)($config['host'] ?? ''));
        self::assertArrayNotHasKey('ssl_domain', $config);
    }

    public function testCustomHostIsPreservedWhenPresent(): void
    {
        $start = $this->createProbe(['host' => 'custom.example.test']);
        $config = $start->resolveConfig('default', []);

        self::assertSame('custom.example.test', (string)($config['host'] ?? ''));
    }

    public function testManagedLocalHostListenAddressUsesLoopback(): void
    {
        $start = $this->createProbe();

        self::assertSame('127.0.0.1', $start->resolveListenHost('p11005ce4.weline.test'));
        self::assertSame('127.0.0.1', $start->resolveListenHost('demo.weline.localhost'));
        self::assertSame('0.0.0.0', $start->resolveListenHost('0.0.0.0'));
        self::assertSame('0.0.0.0', $start->resolveListenHost('www.example.com'));
        self::assertSame('192.168.1.10', $start->resolveListenHost('192.168.1.10'));
    }

    public function testGatewayFallbackBindDefaultsToLoopbackForPublicDomain(): void
    {
        $start = $this->createProbe();

        self::assertSame(
            '127.0.0.1',
            $start->resolveFallbackBind(
                ['bind_host' => '127.0.0.1'],
                'shop.example.test',
            ),
        );
    }

    public function testGatewayFallbackBindPreservesExplicitIpAndAddressFamily(): void
    {
        $start = $this->createProbe();

        self::assertSame(
            '::',
            $start->resolveFallbackBind(
                ['bind_host' => '127.0.0.1'],
                '::',
            ),
        );
        self::assertSame(
            '192.0.2.15',
            $start->resolveFallbackBind(
                ['gateway' => ['fallback_bind_host' => '192.0.2.15']],
                'shop.example.test',
            ),
        );
    }

    public function testGatewayFallbackBindRejectsUnresolvedHostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resolved IPv4 or IPv6');

        $this->createProbe()->resolveFallbackBind(
            ['gateway' => ['fallback_bind_host' => 'bind.example.test']],
            'shop.example.test',
        );
    }

    public function testEnvCustomHostOverridesSavedLegacyHost(): void
    {
        $start = $this->createProbe(
            ['host' => 'p11005ce4.weline.local'],
            ['wls' => ['host' => 'demo.internal.example']]
        );
        $config = $start->resolveConfig('default', []);

        self::assertSame('demo.internal.example', (string)($config['host'] ?? ''));
    }

    public function testRestartReusesSavedServingIdentityOverEnvironmentDefaults(): void
    {
        $start = $this->createProbe(
            [
                'host' => 'frz004.localhost',
                'public_host' => 'frz004.localhost',
                'ssl_domain' => 'frz004.localhost',
                'ssl_cert' => '/saved/fullchain.pem',
                'ssl_key' => '/saved/privkey.pem',
                'certificate_profile' => 'test',
                'edge_mode' => 'wls',
            ],
            ['wls' => [
                'host' => 'environment.weline.test',
                'public_host' => 'environment.weline.test',
                'ssl_domain' => 'environment.weline.test',
                'ssl_cert' => '/environment/fullchain.pem',
                'ssl_key' => '/environment/privkey.pem',
            ]],
        );

        $config = $start->resolveConfig('restart-saved-identity', [
            'r' => true,
            'no-ssl' => true,
        ]);

        self::assertSame('frz004.localhost', (string)($config['host'] ?? ''));
        self::assertSame('frz004.localhost', (string)($config['public_host'] ?? ''));
        self::assertSame('frz004.localhost', (string)($config['ssl_domain'] ?? ''));
        self::assertSame('/saved/fullchain.pem', (string)($config['ssl_cert'] ?? ''));
        self::assertSame('/saved/privkey.pem', (string)($config['ssl_key'] ?? ''));
        self::assertSame('test', (string)($config['certificate_profile'] ?? ''));
    }

    public function testRestartBindsNativeManifestRecoveryToTheSolidifiedHost(): void
    {
        $method = new \ReflectionMethod(Start::class, 'execute');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $configAt = \strpos($source, '$config = $this->getServerConfig($instanceName, $args);');
        $hostAt = \strpos($source, '$host = $config[\'host\'];', $configAt ?: 0);
        $manifestAt = \strpos(
            $source,
            'NativeServingManifestStartupRecovery::fromEndpoint(',
            $configAt ?: 0,
        );

        self::assertIsInt($configAt);
        self::assertIsInt($hostAt);
        self::assertIsInt($manifestAt);
        self::assertLessThan(
            $manifestAt,
            $hostAt,
            'The requested Host must be solidified before native manifest recovery validates it.',
        );
    }

    public function testBaseGetEnvConfigReturnsArray(): void
    {
        $start = new StartBaseEnvConfigProbe();

        self::assertIsArray($start->readEnvConfig());
    }

    public function testSaveInstanceInfoUsesManagerAppendOnlySemantics(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfo('unit-solidify');

        self::assertCount(1, $manager->savedInstances);
        self::assertSame('unit-solidify', $manager->savedInstances[0]['name']);

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('unit-solidify', $info['name'] ?? null);
        self::assertSame('127.0.0.1', $info['host'] ?? null);
        self::assertSame('127.0.0.1', $info['public_host'] ?? null);
        self::assertSame(9443, $info['port'] ?? null);
        self::assertSame(2, $info['count'] ?? null);
        self::assertSame(19443, $info['worker_port'] ?? null);
        self::assertSame(80, $info['http_redirect_port'] ?? null);
    }

    public function testSaveInstanceInfoKeepsPublicHostSeparateFromListenHost(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfoWithPublicHost('unit-public-host');

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('127.0.0.1', $info['host'] ?? null);
        self::assertSame('p11005ce4.weline.test', $info['public_host'] ?? null);
    }

    public function testBaseStartExposesInstanceManagerForRuntimePersistence(): void
    {
        $start = new StartInstanceManagerAccessorProbe();

        self::assertInstanceOf(ServerInstanceManager::class, $start->readInstanceManager());
    }

    public function testMaintenanceMutationAndPollingShareOneAbsoluteDeadline(): void
    {
        $method = new \ReflectionMethod(Start::class, 'syncWlsMaintenanceMode');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $deadlineAt = \strpos($source, '$deadlineMonotonic = $deadlineNs / 1_000_000_000;');
        $mutationAt = \strpos($source, 'setMaintenanceModeBeforeDeadline(');
        self::assertIsInt($deadlineAt);
        self::assertIsInt($mutationAt);
        self::assertLessThan(
            $mutationAt,
            $deadlineAt,
            'The maintenance deadline must be created before the first control mutation.',
        );
        self::assertMatchesRegularExpression(
            '/setMaintenanceModeBeforeDeadline\(\s*\$instanceName,\s*'
                . '\$enabled,\s*6\.0,\s*\$deadlineMonotonic,\s*\)/',
            $source,
        );
        self::assertStringContainsString(
            '$dispatchService->setMaintenanceModeBeforeDeadline(',
            $source,
        );
        self::assertStringContainsString(
            '$status = $gateway->getStatusBeforeDeadline(' . "\n"
                . '                        $targetInstance,' . "\n"
                . '                        \max(0.1, \min(0.75, $remainingSec)),' . "\n"
                . '                        $deadlineMonotonic,',
            $source,
        );
    }

    private function createProbe(?array $savedConfig = null, array $envConfig = []): StartConfigProbe
    {
        $sslServiceMock = $this->createMock(SslCertificateService::class);
        ObjectManager::setInstance(SslCertificateService::class, $sslServiceMock);

        return new StartConfigProbe($savedConfig, $envConfig);
    }
}

final class SslCertificateDomainPolicyProbe extends SslCertificateService
{
    /** @param array<string,list<string>> $resolvedIps */
    public function __construct(
        private readonly bool $development,
        private readonly array $resolvedIps,
    ) {
        parent::__construct(true);
    }

    public function isDevelopmentEnvironment(): bool
    {
        return $this->development;
    }

    /** @return list<string> */
    protected function resolveDomainIps(string $domain): array
    {
        return $this->resolvedIps[$domain] ?? [];
    }
}

final class StartConfigProbe extends Start
{
    public int $managedWildcardCertificateCalls = 0;
    public int $localCertificateCalls = 0;
    public int $certificateMapCalls = 0;
    public int $gatewayBackendLeaseCalls = 0;

    public function __construct(
        private readonly ?array $savedConfig = null,
        private readonly array $envConfig = [],
        private readonly ?SslCertificateService $sslService = null,
    ) {
    }

    public function resolveConfig(string $instanceName, array $args): array
    {
        return $this->getServerConfig($instanceName, $args);
    }

    public function resolveListenHost(string $host): string
    {
        return $this->resolveServerListenHost($host);
    }

    public function resolveFallbackBind(array $config, string $startHost): string
    {
        return $this->resolveGatewayFallbackBindHost($config, $startHost);
    }

    public function normalizeEdgeCli(string $mode): string
    {
        return $this->normalizePublicEdgeCliMode($mode);
    }

    public function useStartupCertificateFiles(
        SslCertificateService $sslService,
        string $domain,
        string $syncDomain
    ): ?array {
        return $this->tryUseStartupCertificateFiles($sslService, $domain, $syncDomain);
    }

    public function resolveMissingCertificate(
        array $config,
        string $domain,
        bool $needsLocalCertificate,
        bool $willReuseCertificate,
    ): ?array {
        return $this->resolveWls2MissingCertificateResult(
            $config,
            $domain,
            $needsLocalCertificate,
            $willReuseCertificate,
        );
    }

    public function resolvePrimaryRouteGate(
        array $routes,
        string $publicHost,
        bool $certificatePending,
    ): array {
        return $this->resolveGatewayPrimaryRouteGate(
            $routes,
            $publicHost,
            $certificatePending,
        );
    }

    public function resolveGatewayBackendPort(
        string $instanceName,
        int $configuredPort,
        bool $portExplicit,
        bool $gatewayMode,
    ): int {
        return $this->resolveGatewayInitialBackendPort(
            $instanceName,
            $configuredPort,
            $portExplicit,
            $gatewayMode,
        );
    }

    /** @param array<string,mixed> $config */
    public function resolveCertificateDomain(array $config, string $host): string
    {
        return $this->resolveCertificateHost($config, $host);
    }

    public function shouldPersistCertificateSource(
        bool $sslEnabled,
        bool $certificatePending,
        bool $gatewayMode,
        bool $pureWlsMode,
        string $requestedMode,
    ): bool {
        return $this->shouldPersistGatewayCertificateSource(
            $sslEnabled,
            $certificatePending,
            $gatewayMode,
            $pureWlsMode,
            $requestedMode,
        );
    }

    /** @param array<string,mixed> $config */
    public function ensureSslResult(string $instanceName, array $config): array
    {
        $this->__init();

        return $this->ensureSslCertificate($instanceName, $config);
    }

    /** @param array<string,mixed> $config */
    public function completeCertificatePreparation(
        string $instanceName,
        array $config,
        bool $gatewayMode,
        string $publicHost,
    ): void {
        $this->completeDeferredCertificatePreparation(
            $instanceName,
            $config,
            $gatewayMode,
            $publicHost,
        );
    }

    protected function createSslCertificateService(bool $deferCertificateStorage = false): SslCertificateService
    {
        return $this->sslService ?? parent::createSslCertificateService($deferCertificateStorage);
    }

    protected function validatePublicHostResolvesToCurrentServer(
        string $host,
        SslCertificateService $sslService,
    ): array {
        unset($host, $sslService);

        return ['success' => true, 'skipped' => true];
    }

    protected function allocateGatewayInitialBackendPort(
        string $instanceName,
        ?int $exactPort = null,
    ): int
    {
        unset($instanceName);
        $this->gatewayBackendLeaseCalls++;
        return $exactPort ?? 23456;
    }

    protected function getDefaultHost(): string
    {
        return 'unit-test.weline.test';
    }

    protected function loadSavedInstanceConfig(string $instanceName): ?array
    {
        unset($instanceName);

        return $this->savedConfig;
    }

    protected function getEnvConfig(): array
    {
        return $this->envConfig;
    }

    protected function restoreManagedCertificateForConfig(array &$config, SslCertificateService $sslService, string $host): bool
    {
        unset($config, $sslService, $host);

        return false;
    }

    protected function autoDetectSslCertificates(): ?array
    {
        return null;
    }

    protected function ensureHostsFileConfigured(string $host): void
    {
        unset($host);
    }

    protected function ensureLocalSelfSignedCertificates(array $config = []): void
    {
        unset($config);
        $this->localCertificateCalls++;
    }

    protected function ensureManagedLocalWildcardCertificate(): void
    {
        $this->managedWildcardCertificateCalls++;
    }

    protected function generateCertificateMap(): void
    {
        $this->certificateMapCalls++;
    }

    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        unset($mode);

        if ($workerCount === 'auto' || $workerCount === null || $workerCount === '') {
            return 4;
        }

        return (int)$workerCount;
    }
}

final class StartBaseEnvConfigProbe extends Start
{
    public function readEnvConfig(): array
    {
        return $this->getEnvConfig();
    }

    protected function restoreManagedCertificateForConfig(array &$config, SslCertificateService $sslService, string $host): bool
    {
        unset($config, $sslService, $host);

        return false;
    }

    protected function autoDetectSslCertificates(): ?array
    {
        return null;
    }

    protected function ensureHostsFileConfigured(string $host): void
    {
        unset($host);
    }

    protected function ensureLocalSelfSignedCertificates(array $config = []): void
    {
        unset($config);
    }

    protected function generateCertificateMap(): void
    {
    }

    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        unset($mode);

        if ($workerCount === 'auto' || $workerCount === null || $workerCount === '') {
            return 4;
        }

        return (int)$workerCount;
    }
}

final class StartInstanceInfoProbe extends Start
{
    public function __construct(private readonly ServerInstanceManager $manager)
    {
    }

    public function persistInstanceInfo(string $instanceName): void
    {
        $this->saveInstanceInfo(
            instanceName: $instanceName,
            host: '127.0.0.1',
            port: 9443,
            count: 2,
            daemon: true,
            sslEnabled: true,
            sslCert: '/tmp/cert.pem',
            sslKey: '/tmp/key.pem',
            runtimeSelection: $this->runtimeSelection(),
            workerPort: 19443,
            httpRedirectPort: 80,
            windowMode: true,
            enableLog: true,
            workerBasePort: 19443,
            sharedStateRuntime: [],
            orchestratorRuntimeOptions: ['frontend_process_mode' => true],
            workerMemoryLimit: '512M',
            runtimeMetadata: ['container_registry_digest' => \str_repeat('a', 64)]
        );
    }

    public function persistInstanceInfoWithPublicHost(string $instanceName): void
    {
        $this->saveInstanceInfo(
            instanceName: $instanceName,
            host: '127.0.0.1',
            port: 9443,
            count: 2,
            daemon: true,
            sslEnabled: true,
            sslCert: '/tmp/cert.pem',
            sslKey: '/tmp/key.pem',
            runtimeSelection: $this->runtimeSelection(),
            workerPort: 19443,
            httpRedirectPort: 80,
            workerBasePort: 19443,
            workerMemoryLimit: '512M',
            publicHost: 'p11005ce4.weline.test',
            runtimeMetadata: ['container_registry_digest' => \str_repeat('b', 64)]
        );
    }

    private function runtimeSelection(): RuntimeSelection
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => 'dispatcher',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'single',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }

    protected function getInstanceManager(): ServerInstanceManager
    {
        return $this->manager;
    }
}

final class StartInstanceManagerProbe extends ServerInstanceManager
{
    /**
     * @var list<array{name: string, info: array<string, mixed>}>
     */
    public array $savedInstances = [];

    public function saveInstance(string $name, array $info): void
    {
        $this->savedInstances[] = [
            'name' => $name,
            'info' => $info,
        ];
    }
}

final class StartInstanceManagerAccessorProbe extends Start
{
    public function readInstanceManager(): ServerInstanceManager
    {
        return $this->getInstanceManager();
    }
}
