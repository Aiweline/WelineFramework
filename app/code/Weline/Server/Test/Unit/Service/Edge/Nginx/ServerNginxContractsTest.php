<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Server\Service\Capacity\HostCapacityTier;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\FpcPierceNginxMapWriter;
use Weline\Server\Service\Edge\Nginx\FpcQueryAllowlistNginxMapWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigViewService;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxEdgeAvailability;
use Weline\Server\Service\Edge\Nginx\ManagedNginxLocalHostCatalog;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicPortProbeInterface;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Edge\Nginx\SitemapEdgeNginxMapWriter;

/**
 * Aggregated contract suite (merged from 8 *ContractTest files).
 * Observe one overall pass/fail via testAllServerNginxContractsPass().
 */
final class ServerNginxContractsTest extends TestCase
{
    public function testAllServerNginxContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ManagedNginxConfigViewService::testSnapshotIsReadOnlyAndMasksPierceSecret' => function () {
            $this->setUp_ManagedNginxConfigViewService();
            try {
                $this->assert_ManagedNginxConfigViewService_testSnapshotIsReadOnlyAndMasksPierceSecret();
            } finally {
                $this->tearDown_ManagedNginxConfigViewService();
            }
        },
            'ManagedNginxConfigWriterCapacityTierLimit::testTinyEdgeFragmentsIncludeLimitReqAndConn' => function () {
            $this->assert_ManagedNginxConfigWriterCapacityTierLimit_testTinyEdgeFragmentsIncludeLimitReqAndConn();
        },
            'ManagedNginxConfigWriterCapacityTierLimit::testWriterSourceInjectsEdgeLimitOnOriginNotTlsProbe' => function () {
            $this->assert_ManagedNginxConfigWriterCapacityTierLimit_testWriterSourceInjectsEdgeLimitOnOriginNotTlsProbe();
        },
            'ManagedNginxConfigWriterCapacityTierLimit::testDisabledTierEmitsEmptyEdgeFragments' => function () {
            $this->assert_ManagedNginxConfigWriterCapacityTierLimit_testDisabledTierEmitsEmptyEdgeFragments();
        },
            'ManagedNginxConfigWriterDistinctHostCertificate::testWriterSourcePeelsDistinctCertificateHosts' => function () {
            $this->assert_ManagedNginxConfigWriterDistinctHostCertificate_testWriterSourcePeelsDistinctCertificateHosts();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testHostOccupiedWhenEitherPublicPortIsForeign' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostOccupiedWhenEitherPublicPortIsForeign();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testUnbindablePrivilegedPortsDoNotBlockManagedAutoEdge' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testUnbindablePrivilegedPortsDoNotBlockManagedAutoEdge();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testHostOccupiedWhenPublicPortIsForeignOnly' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostOccupiedWhenPublicPortIsForeignOnly();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testHostNotOccupiedWhenPortsAreFreeEvenIfSystemNginxBinaryExists' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostNotOccupiedWhenPortsAreFreeEvenIfSystemNginxBinaryExists();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testHostNotOccupiedWhenPortsHeldBySelfManagedNginx' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostNotOccupiedWhenPortsHeldBySelfManagedNginx();
        },
            'ManagedNginxEdgeAvailabilityPortOccupancy::testExplicitProbeClosureStillOverridesPortInspect' => function () {
            $this->assert_ManagedNginxEdgeAvailabilityPortOccupancy_testExplicitProbeClosureStillOverridesPortInspect();
        },
            'ManagedNginxLocalHostCatalog::testExpandServerNamesAddsManagedLocalHostsAndWildcard' => function () {
            $this->assert_ManagedNginxLocalHostCatalog_testExpandServerNamesAddsManagedLocalHostsAndWildcard();
        },
            'ManagedNginxLocalHostCatalog::testResolveEdgeCertificatePrefersActiveWildcardWhenLocalHostsPresent' => function () {
            $this->assert_ManagedNginxLocalHostCatalog_testResolveEdgeCertificatePrefersActiveWildcardWhenLocalHostsPresent();
        },
            'ManagedNginxOwnerCertificateRebind::testResolveOwnerCertificateGenerationRebindsUnmanagedOwner' => function () {
            $this->assert_ManagedNginxOwnerCertificateRebind_testResolveOwnerCertificateGenerationRebindsUnmanagedOwner();
        },
            'ManagedNginxOwnerCertificateRebind::testUnmanagedOwnerRebindsToProjectActiveGenerationWhenPresent' => function () {
            $this->assert_ManagedNginxOwnerCertificateRebind_testUnmanagedOwnerRebindsToProjectActiveGenerationWhenPresent();
        },
            'ManagedNginxPathsCapacityTier::testTinyForceClampsConnectionsAndKeepalive' => function () {
            try {
                $this->assert_ManagedNginxPathsCapacityTier_testTinyForceClampsConnectionsAndKeepalive();
            } finally {
                $this->tearDown_ManagedNginxPathsCapacityTier();
            }
        },
            'SitemapEdgeNginxMapWriter::testPublishWritesHostMapAndDiskLocations' => function () {
            $this->setUp_SitemapEdgeNginxMapWriter();
            try {
                $this->assert_SitemapEdgeNginxMapWriter_testPublishWritesHostMapAndDiskLocations();
            } finally {
                $this->tearDown_SitemapEdgeNginxMapWriter();
            }
        },
            'SitemapEdgeNginxMapWriter::testManagedNginxWriterIncludesSitemapEdgeSnippets' => function () {
            $this->setUp_SitemapEdgeNginxMapWriter();
            try {
                $this->assert_SitemapEdgeNginxMapWriter_testManagedNginxWriterIncludesSitemapEdgeSnippets();
            } finally {
                $this->tearDown_SitemapEdgeNginxMapWriter();
            }
        },
            'FpcQueryAllowlistNginxMapWriter::testExtractRulesFromSnapshotAndPublishMap' => function () {
            $this->setUp_FpcQueryAllowlistNginxMapWriter();
            try {
                $this->assert_FpcQueryAllowlistNginxMapWriter_testExtractRulesFromSnapshotAndPublishMap();
            } finally {
                $this->tearDown_FpcQueryAllowlistNginxMapWriter();
            }
        },
            'FpcQueryAllowlistNginxMapWriter::testManagedNginxWriterIncludesAllowlistBypass' => function () {
            $this->setUp_FpcQueryAllowlistNginxMapWriter();
            try {
                $this->assert_FpcQueryAllowlistNginxMapWriter_testManagedNginxWriterIncludesAllowlistBypass();
            } finally {
                $this->tearDown_FpcQueryAllowlistNginxMapWriter();
            }
        },
            'FpcQueryAllowlistNginxMapWriter::testRejectsWildcardAndHardcodedBusinessKeysAbsent' => function () {
            $this->setUp_FpcQueryAllowlistNginxMapWriter();
            try {
                $this->assert_FpcQueryAllowlistNginxMapWriter_testRejectsWildcardAndHardcodedBusinessKeysAbsent();
            } finally {
                $this->tearDown_FpcQueryAllowlistNginxMapWriter();
            }
        },
        ];
    }

private string $root_ManagedNginxConfigViewService = '';

private function setUp_ManagedNginxConfigViewService(): void
{
    $this->root_ManagedNginxConfigViewService = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls-nginx-view-' . \bin2hex(\random_bytes(6));
    self::assertTrue(\mkdir($this->root_ManagedNginxConfigViewService, 0700, true));
}

private function tearDown_ManagedNginxConfigViewService(): void
{
    $this->removeTree_ManagedNginxConfigViewService($this->root_ManagedNginxConfigViewService);
    FpcPierceStamp::clearMemo();
}

private function assert_ManagedNginxConfigViewService_testSnapshotIsReadOnlyAndMasksPierceSecret(): void
{
    $paths = new ManagedNginxPaths($this->root_ManagedNginxConfigViewService, ['runtime_root' => 'runtime', 'install_root' => 'install', 'listen_http' => 18092, 'listen_https' => 18492]);
    $paths->ensureRuntimeDirectories();
    $conf = $paths->confFile();
    self::assertNotFalse(\file_put_contents($conf, "worker_processes 1;\nevents {}\n"));
    FpcPierceStamp::clearMemo();
    $secret = \bin2hex(\random_bytes(16));
    // Persist via map writer body path without touching global secret file:
    $mapPath = $paths->confDir() . \DIRECTORY_SEPARATOR . FpcPierceNginxMapWriter::MAP_BASENAME;
    $mapBody = "map \$arg_w_fpc_pierce \$wls_fpc_pierce_arg_ok {\n    default 0;\n    \"{$secret}\" 1;\n}\n";
    self::assertNotFalse(\file_put_contents($mapPath, $mapBody));
    $view = new ManagedNginxConfigViewService($paths);
    // Force mask path by memoizing the same secret used in the map.
    $ref = new \ReflectionClass(FpcPierceStamp::class);
    $memo = $ref->getProperty('memoSecret');
    $memo->setAccessible(true);
    $memo->setValue(null, $secret);
    $snap = $view->snapshot();
    self::assertSame($paths->confDir(), $snap['conf_dir']);
    self::assertNotEmpty($snap['files']);
    $byId = [];
    foreach ($snap['files'] as $file) {
        $byId[$file['id']] = $file;
    }
    self::assertArrayHasKey('nginx.conf', $byId);
    self::assertTrue($byId['nginx.conf']['exists']);
    self::assertStringContainsString('worker_processes 1;', $byId['nginx.conf']['content']);
    self::assertArrayHasKey('fpc-pierce.map.conf', $byId);
    $pierce = $byId['fpc-pierce.map.conf'];
    self::assertTrue($pierce['exists']);
    self::assertTrue($pierce['redacted']);
    self::assertStringNotContainsString($secret, $pierce['content']);
    self::assertStringContainsString(\substr($secret, 0, 6), $pierce['content']);
    // Source files must remain unmasked on disk.
    self::assertStringContainsString($secret, (string) \file_get_contents($mapPath));
}

private function removeTree_ManagedNginxConfigViewService(string $dir): void
{
    if ($dir === '' || !\is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            @\rmdir($path);
        } else {
            @\unlink($path);
        }
    }
    @\rmdir($dir);
}

private function assert_ManagedNginxConfigWriterCapacityTierLimit_testTinyEdgeFragmentsIncludeLimitReqAndConn(): void
{
    $edge = HostCapacityTier::nginxEdgeLimitFragments(1024);
    self::assertTrue($edge['enabled']);
    self::assertStringContainsString('limit_req_zone $binary_remote_addr zone=wls_req:10m rate=20r/s;', $edge['http_zone_block']);
    self::assertStringContainsString('limit_conn_zone $binary_remote_addr zone=wls_conn:10m;', $edge['http_zone_block']);
    self::assertStringContainsString('limit_req zone=wls_req burst=40 nodelay;', $edge['location_directives']);
    self::assertStringContainsString('limit_conn wls_conn 20;', $edge['location_directives']);
}

private function assert_ManagedNginxConfigWriterCapacityTierLimit_testWriterSourceInjectsEdgeLimitOnOriginNotTlsProbe(): void
{
    // __DIR__=.../Test/Unit/Service/Edge/Nginx → up 5 = Server
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Nginx/ManagedNginxConfigWriter.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('HostCapacityTier::nginxEdgeLimitFragments()', $src);
    self::assertStringContainsString('{$edgeLimitHttpZone}', $src);
    self::assertStringContainsString('{$edgeLimitLocation}', $src);
    self::assertStringContainsString('location @wls_html_origin', $src);
    self::assertStringContainsString('location = /_wls/nginx/tls-session-probe', $src);
    // Probe block must not reference edgeLimitLocation (exemption).
    $probePos = \strpos($src, 'location = /_wls/nginx/tls-session-probe');
    self::assertNotFalse($probePos);
    $probeSlice = \substr($src, $probePos, 500);
    self::assertStringNotContainsString('edgeLimitLocation', $probeSlice);
    self::assertStringContainsString('{$edgeLimitLocation}', $src);
}

private function assert_ManagedNginxConfigWriterCapacityTierLimit_testDisabledTierEmitsEmptyEdgeFragments(): void
{
    $edge = HostCapacityTier::nginxEdgeLimitFragments(512, ['enabled' => false]);
    self::assertFalse($edge['enabled']);
    self::assertSame('', $edge['http_zone_block']);
    self::assertSame('', $edge['location_directives']);
}

private function assert_ManagedNginxConfigWriterDistinctHostCertificate_testWriterSourcePeelsDistinctCertificateHosts(): void
{
    // __DIR__=.../Server/Test/Unit/Service/Edge/Nginx → up 5 = .../Server
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . \DIRECTORY_SEPARATOR . 'Service' . \DIRECTORY_SEPARATOR . 'Edge' . \DIRECTORY_SEPARATOR . 'Nginx' . \DIRECTORY_SEPARATOR . 'ManagedNginxConfigWriter.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('peelDistinctCertificateHosts', $src);
    self::assertStringContainsString('buildDistinctCertificateHostServerBlocks', $src);
    self::assertStringContainsString('certificateCoversHostname', $src);
    self::assertStringContainsString('resolveHostSslMaterial', $src);
    self::assertStringContainsString('WLS distinct-certificate host', $src);
    self::assertStringContainsString('distinct_certificate_hosts', $src);
    self::assertStringContainsString('[$names, $distinctHostSsl] = $this->peelDistinctCertificateHosts($names, $ssl)', $src);
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostOccupiedWhenEitherPublicPortIsForeign(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_FREE, 443 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN]);
    $availability = new ManagedNginxEdgeAvailability(null, null, $probe);
    self::assertTrue($availability->hostNginxOccupied());
    self::assertSame([80, 443], \array_slice($probe->inspectedPorts, 0, 2));
    self::assertStringContainsString('foreign listener', $availability->unavailableReason());
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testUnbindablePrivilegedPortsDoNotBlockManagedAutoEdge(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE, 443 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE]);
    $availability = new ManagedNginxEdgeAvailability(null, null, $probe);
    self::assertFalse($availability->hostNginxOccupied(), 'weline 用户探测特权端口 UNBINDABLE 是常态；托管 Nginx 靠 setcap 绑定，不得因此降级纯 WLS');
    self::assertSame([80, 443], $probe->inspectedPorts);
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostOccupiedWhenPublicPortIsForeignOnly(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN, 443 => ManagedNginxPublicPortProbeInterface::STATE_FREE]);
    $availability = new ManagedNginxEdgeAvailability(null, null, $probe);
    self::assertTrue($availability->hostNginxOccupied());
    self::assertSame([80], $probe->inspectedPorts);
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostNotOccupiedWhenPortsAreFreeEvenIfSystemNginxBinaryExists(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_FREE, 443 => ManagedNginxPublicPortProbeInterface::STATE_FREE]);
    $availability = new ManagedNginxEdgeAvailability(null, null, $probe);
    self::assertFalse($availability->hostNginxOccupied());
    self::assertSame([80, 443], $probe->inspectedPorts);
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testHostNotOccupiedWhenPortsHeldBySelfManagedNginx(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_SELF, 443 => ManagedNginxPublicPortProbeInterface::STATE_SELF]);
    $availability = new ManagedNginxEdgeAvailability(null, null, $probe);
    self::assertFalse($availability->hostNginxOccupied(), '本项目托管 Nginx 已听 80/443 时 auto 必须继续认领 legacy 网关，不得当外人占用降级纯 WLS');
}

private function assert_ManagedNginxEdgeAvailabilityPortOccupancy_testExplicitProbeClosureStillOverridesPortInspect(): void
{
    $probe = new FakePublicPortProbe([80 => ManagedNginxPublicPortProbeInterface::STATE_FREE, 443 => ManagedNginxPublicPortProbeInterface::STATE_FREE]);
    $availability = new ManagedNginxEdgeAvailability(null, static fn(): bool => true, $probe);
    self::assertTrue($availability->hostNginxOccupied());
    self::assertSame([], $probe->inspectedPorts);
}

private function assert_ManagedNginxLocalHostCatalog_testExpandServerNamesAddsManagedLocalHostsAndWildcard(): void
{
    $catalog = new ManagedNginxLocalHostCatalog();
    $names = $catalog->expandServerNames(['p05113ef3.test.weline.com'], static fn(): array => [['domain' => 'grocery.test.weline.com', 'website_id' => 544], ['domain' => 'example.com', 'website_id' => 1]]);
    self::assertContains('p05113ef3.test.weline.com', $names);
    self::assertContains('grocery.test.weline.com', $names);
    self::assertContains('*.test.weline.com', $names);
    self::assertNotContains('example.com', $names);
}

private function assert_ManagedNginxLocalHostCatalog_testResolveEdgeCertificatePrefersActiveWildcardWhenLocalHostsPresent(): void
{
    $catalog = new ManagedNginxLocalHostCatalog();
    $store = new class
    {
        public function active(string $domain): ?array
        {
            if ($domain !== '*.test.weline.com') {
                return null;
            }
            return ['domain' => '*.test.weline.com', 'generation' => 24, 'source_digest' => \str_repeat('a', 64), 'cert_path' => '/tmp/fullchain.pem', 'key_path' => '/tmp/privkey.pem', 'chain_path' => '', 'leaf_fingerprint_sha256' => \str_repeat('b', 64), 'cert_sha256' => \str_repeat('c', 64), 'key_sha256' => \str_repeat('d', 64), 'chain_sha256' => ''];
        }
    };
    $preferred = ['domain' => 'p05113ef3.test.weline.com', 'generation' => 30, 'source_digest' => \str_repeat('e', 64)];
    $resolved = $catalog->resolveEdgeCertificate($preferred, ['p05113ef3.test.weline.com', 'grocery.test.weline.com', '*.test.weline.com'], $store);
    self::assertIsArray($resolved);
    self::assertSame('*.test.weline.com', $resolved['domain'] ?? null);
    self::assertSame(24, (int) ($resolved['generation'] ?? 0));
}

private function assert_ManagedNginxOwnerCertificateRebind_testResolveOwnerCertificateGenerationRebindsUnmanagedOwner(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Nginx/ManagedNginxService.php');
    self::assertStringContainsString('resolveActiveCertificateGenerationForOwner', $source);
    self::assertStringContainsString('Owner started on mutable app/etc/ssl', $source);
    // Hard refuse without rebind attempt must not remain the only path.
    $methodPos = \strpos($source, 'function resolveOwnerCertificateGeneration(');
    self::assertNotFalse($methodPos);
    $next = \strpos($source, 'function resolveActiveCertificateGenerationForOwner(', $methodPos);
    self::assertNotFalse($next);
    $body = \substr($source, $methodPos, $next - $methodPos);
    self::assertStringContainsString('resolveActiveCertificateGenerationForOwner', $body);
    self::assertStringNotContainsString('Managed Nginx owner is not bound to an immutable certificate generation.', $body);
}

private function assert_ManagedNginxOwnerCertificateRebind_testUnmanagedOwnerRebindsToProjectActiveGenerationWhenPresent(): void
{
    $domain = 'p05113ef3.test.weline.com';
    $store = new ProjectCertificateGenerationStore();
    try {
        $active = $store->active($domain);
    } catch (\Throwable) {
        self::markTestSkipped('ProjectCertificateGenerationStore unavailable.');
    }
    if (!\is_array($active) || (int) ($active['generation'] ?? 0) < 1) {
        self::markTestSkipped('No active certificate generation for ' . $domain);
    }
    $service = ManagedNginxService::fromEnv();
    $method = new \ReflectionMethod($service, 'resolveOwnerCertificateGeneration');
    $method->setAccessible(true);
    $resolved = $method->invoke($service, ['certificate_generation_managed' => false, 'certificate_domain' => '', 'certificate_generation' => 0, 'server_names' => [$domain]]);
    self::assertIsArray($resolved);
    self::assertSame((int) $active['generation'], (int) ($resolved['generation'] ?? 0));
    self::assertSame(\strtolower((string) $active['source_digest']), \strtolower((string) ($resolved['source_digest'] ?? '')));
}

private function tearDown_ManagedNginxPathsCapacityTier(): void
{
    @\putenv('WLS_CAPACITY_TIER_FORCE');
    unset($_ENV['WLS_CAPACITY_TIER_FORCE'], $_SERVER['WLS_CAPACITY_TIER_FORCE']);
    @\putenv('WLS_HOST_LIMIT_MB');
    unset($_ENV['WLS_HOST_LIMIT_MB'], $_SERVER['WLS_HOST_LIMIT_MB']);
    parent::tearDown();
}

private function assert_ManagedNginxPathsCapacityTier_testTinyForceClampsConnectionsAndKeepalive(): void
{
    @\putenv('WLS_CAPACITY_TIER_FORCE=tiny');
    $_ENV['WLS_CAPACITY_TIER_FORCE'] = 'tiny';
    $_SERVER['WLS_CAPACITY_TIER_FORCE'] = 'tiny';
    $paths = new ManagedNginxPaths('/tmp/wls-nginx-capacity-tier', ['worker_connections' => 32768, 'upstream_keepalive' => 256]);
    self::assertSame(4096, $paths->workerConnections());
    self::assertSame(32, $paths->upstreamKeepalive());
    self::assertSame(4096, HostCapacityTier::clampNginxWorkerConnections(32768, null, ['force' => 'tiny']));
}

private string $root_SitemapEdgeNginxMapWriter = '';

private function setUp_SitemapEdgeNginxMapWriter(): void
{
    $this->root_SitemapEdgeNginxMapWriter = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-sitemap-edge-' . \bin2hex(\random_bytes(8));
    self::assertTrue(\mkdir($this->root_SitemapEdgeNginxMapWriter, 0700, true));
    $canonical = \realpath($this->root_SitemapEdgeNginxMapWriter);
    self::assertIsString($canonical);
    $this->root_SitemapEdgeNginxMapWriter = $canonical;
}

private function tearDown_SitemapEdgeNginxMapWriter(): void
{
    $this->removeTree_SitemapEdgeNginxMapWriter($this->root_SitemapEdgeNginxMapWriter);
}

private function assert_SitemapEdgeNginxMapWriter_testPublishWritesHostMapAndDiskLocations(): void
{
    $paths = new ManagedNginxPaths($this->root_SitemapEdgeNginxMapWriter, ['runtime_root' => 'runtime', 'install_root' => 'install', 'listen_http' => 18092, 'listen_https' => 18492]);
    $paths->ensureRuntimeDirectories();
    $writer = new SitemapEdgeNginxMapWriter($paths);
    $result = $writer->publish(['default_website_code' => 'default', 'host_to_code' => ['www.example.test' => 'default', 'shop.example.test' => 'shop'], 'index_locations' => [['path' => '/sitemap.xml', 'website_code' => 'default'], ['path' => '/shop/sitemap.xml', 'website_code' => 'shop']]]);
    self::assertTrue($result['ok']);
    $map = (string) \file_get_contents($writer->mapFilePath());
    $loc = (string) \file_get_contents($writer->locationsFilePath());
    self::assertStringContainsString('map $host $wls_sitemap_website_code', $map);
    self::assertStringContainsString('"www.example.test" "default"', $map);
    self::assertStringContainsString('"shop.example.test" "shop"', $map);
    self::assertStringContainsString('location ^~ /sitemaps/', $loc);
    self::assertStringContainsString('X-Wls-Sitemap-Disk "1"', $loc);
    self::assertStringContainsString('try_files /sitemaps/$wls_sitemap_website_code/canonical/sitemap.xml =404;', $loc);
    self::assertStringContainsString('location = /shop/sitemap.xml', $loc);
    self::assertStringContainsString('try_files /sitemaps/shop/canonical/sitemap.xml =404;', $loc);
    self::assertSame(1, \substr_count($loc, 'location = /sitemap.xml'));
}

private function assert_SitemapEdgeNginxMapWriter_testManagedNginxWriterIncludesSitemapEdgeSnippets(): void
{
    $paths = new ManagedNginxPaths($this->root_SitemapEdgeNginxMapWriter, ['runtime_root' => 'runtime', 'install_root' => 'install', 'listen_http' => 18093, 'listen_https' => 18493, 'edge_cache' => true, 'gzip' => true]);
    $paths->ensureRuntimeDirectories();
    $result = (new ManagedNginxConfigWriter($paths))->write(19093, '127.0.0.1', ['_']);
    $config = (string) \file_get_contents($result['conf']);
    self::assertStringContainsString(SitemapEdgeNginxMapWriter::MAP_BASENAME, $config);
    self::assertStringContainsString(SitemapEdgeNginxMapWriter::LOCATIONS_BASENAME, $config);
    self::assertFileExists($paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::MAP_BASENAME);
    self::assertFileExists($paths->confDir() . DIRECTORY_SEPARATOR . SitemapEdgeNginxMapWriter::LOCATIONS_BASENAME);
}

private function removeTree_SitemapEdgeNginxMapWriter(string $path): void
{
    if (!\is_dir($path)) {
        return;
    }
    $items = \scandir($path);
    if (!\is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (\is_dir($full)) {
            $this->removeTree_SitemapEdgeNginxMapWriter($full);
        } else {
            @\unlink($full);
        }
    }
    @\rmdir($path);
}

private string $root_FpcQueryAllowlistNginxMapWriter = '';

private function setUp_FpcQueryAllowlistNginxMapWriter(): void
{
    $this->root_FpcQueryAllowlistNginxMapWriter = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-fpc-qa-' . \bin2hex(\random_bytes(8));
    self::assertTrue(\mkdir($this->root_FpcQueryAllowlistNginxMapWriter, 0700, true));
    $canonical = \realpath($this->root_FpcQueryAllowlistNginxMapWriter);
    self::assertIsString($canonical);
    $this->root_FpcQueryAllowlistNginxMapWriter = $canonical;
}

private function tearDown_FpcQueryAllowlistNginxMapWriter(): void
{
    $this->removeTree_SitemapEdgeNginxMapWriter($this->root_FpcQueryAllowlistNginxMapWriter);
}

private function assert_FpcQueryAllowlistNginxMapWriter_testExtractRulesFromSnapshotAndPublishMap(): void
{
    $paths = new ManagedNginxPaths($this->root_FpcQueryAllowlistNginxMapWriter, [
        'runtime_root' => 'runtime',
        'install_root' => 'install',
        'listen_http' => 18094,
        'listen_https' => 18494,
    ]);
    $paths->ensureRuntimeDirectories();
    $snapshot = [
        'schema_version' => FpcQueryAllowlistNginxMapWriter::SNAPSHOT_SCHEMA,
        'declarations' => [
            'a' => [
                'type' => 'fpc',
                'path_pattern' => '/products',
                'public_path_patterns' => ['/category/*'],
                'attrs' => [
                    'enabled' => true,
                    'ttl' => 3600,
                    'query_keys' => ['Price', 'af_hanfu_chao_dai', '*'],
                ],
            ],
            'b' => [
                'type' => 'fpc',
                'path_pattern' => '/about',
                'public_path_patterns' => [],
                'attrs' => [
                    'enabled' => true,
                    'ttl' => 3600,
                    // no query_keys → path-only, no edge rules
                ],
            ],
            'c' => [
                'type' => 'fpc',
                'path_pattern' => '/disabled',
                'attrs' => [
                    'enabled' => false,
                    'query_keys' => ['price'],
                ],
            ],
        ],
    ];
    $rules = FpcQueryAllowlistNginxMapWriter::extractRules($snapshot);
    $pairs = \array_map(
        static fn(array $r): string => $r['path_pattern'] . '|' . $r['query_key'],
        $rules,
    );
    \sort($pairs);
    self::assertSame([
        '/category/*|af_hanfu_chao_dai',
        '/category/*|price',
        '/products|af_hanfu_chao_dai',
        '/products|price',
    ], $pairs);

    $writer = new FpcQueryAllowlistNginxMapWriter($paths);
    $result = $writer->publish($snapshot);
    self::assertTrue($result['ok']);
    self::assertSame(4, $result['rule_count']);
    $body = (string)\file_get_contents($writer->mapFilePath());
    self::assertStringContainsString('map $request_uri $wls_fpc_html_query_allowlist_bypass', $body);
    self::assertStringContainsString('price=', $body);
    self::assertStringContainsString('af_hanfu_chao_dai=', $body);
    self::assertStringContainsString('/products', $body);
    self::assertStringNotContainsString('~q/', $body);
    self::assertStringNotContainsString('"*', $body);
}

private function assert_FpcQueryAllowlistNginxMapWriter_testManagedNginxWriterIncludesAllowlistBypass(): void
{
    $paths = new ManagedNginxPaths($this->root_FpcQueryAllowlistNginxMapWriter, [
        'runtime_root' => 'runtime',
        'install_root' => 'install',
        'listen_http' => 18095,
        'listen_https' => 18495,
        'edge_cache' => true,
        'gzip' => true,
    ]);
    $paths->ensureRuntimeDirectories();
    $result = (new ManagedNginxConfigWriter($paths))->write(19095, '127.0.0.1', ['_']);
    $config = (string)\file_get_contents($result['conf']);
    self::assertStringContainsString(FpcQueryAllowlistNginxMapWriter::MAP_BASENAME, $config);
    self::assertStringContainsString('$wls_fpc_html_query_allowlist_bypass', $config);
    self::assertFileExists($paths->confDir() . DIRECTORY_SEPARATOR . FpcQueryAllowlistNginxMapWriter::MAP_BASENAME);
    self::assertStringNotContainsString('~q/', $config);
}

private function assert_FpcQueryAllowlistNginxMapWriter_testRejectsWildcardAndHardcodedBusinessKeysAbsent(): void
{
    $src = (string)\file_get_contents(
        BP . '/app/code/Weline/Server/Service/Edge/Nginx/FpcQueryAllowlistNginxMapWriter.php',
    );
    // Writer must not embed storefront business query names; rules come from snapshot only.
    self::assertStringNotContainsString("'price'", $src);
    self::assertStringNotContainsString('af_hanfu', $src);
    self::assertSame([], FpcQueryAllowlistNginxMapWriter::normalizeQueryKeys(['*', '', 'Bad-Key', '1start']));
    self::assertSame(['price'], FpcQueryAllowlistNginxMapWriter::normalizeQueryKeys(['Price', 'price']));
}
}

final class FakePublicPortProbe implements ManagedNginxPublicPortProbeInterface
{
    /** @var list<int> */
    public array $inspectedPorts = [];
    /**
     * @param array<int, string> $statesByPort
     */
    public function __construct(private readonly array $statesByPort)
    {
    }
    public function inspect(int $port): array
    {
        $this->inspectedPorts[] = $port;
        $state = $this->statesByPort[$port] ?? self::STATE_FREE;
        return ['state' => $state, 'pid' => $state === self::STATE_FOREIGN ? 4242 : 0, 'pname' => $state === self::STATE_FOREIGN ? 'nginx' : '', 'detail' => 'fake:' . $state];
    }
}
