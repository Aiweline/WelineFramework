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

    public function testNoSslSkipsManagedWildcardCertificatePreparation(): void
    {
        $start = $this->createProbe();
        $start->resolveConfig('default', ['no-ssl' => true]);

        self::assertSame(0, $start->managedWildcardCertificateCalls);
    }

    public function testReusablePrimaryCertificateDoesNotSkipManagedWildcardValidation(): void
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

        self::assertSame(1, $start->managedWildcardCertificateCalls);
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

    public function testPureWlsLocalDevelopmentDomainKeepsSelfSignedColdStart(): void
    {
        $result = $this->createProbe()->resolveMissingCertificate([
            'edge_mode' => 'wls',
            'gateway' => ['requested_mode' => 'auto'],
        ], 'p8af22c44.weline.test', true, false);

        self::assertNull($result);
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
            ['edge_mode' => 'wls'],
            'project.weline.test',
            true,
            false,
        ));
        self::assertNull($probe->resolveMissingCertificate(
            ['edge_mode' => 'gateway'],
            'shop.example.com',
            false,
            true,
        ));
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

    private function createProbe(?array $savedConfig = null, array $envConfig = []): StartConfigProbe
    {
        $sslServiceMock = $this->createMock(SslCertificateService::class);
        ObjectManager::setInstance(SslCertificateService::class, $sslServiceMock);

        return new StartConfigProbe($savedConfig, $envConfig);
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
