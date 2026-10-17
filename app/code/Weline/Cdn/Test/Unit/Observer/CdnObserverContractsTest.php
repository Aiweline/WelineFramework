<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Observer\ResolveClientIpObserver;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnObserverContractsPass().
 */
final class CdnObserverContractsTest extends TestCase
{
    public function testAllCdnObserverContractsPass(): void
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
            'ApplyOriginNoStoreEdgeHeaders::testObserverListensAndCloudflareWritesVendorHeaders' => function () {
            $this->assert_ApplyOriginNoStoreEdgeHeaders_testObserverListensAndCloudflareWritesVendorHeaders();
        },
            'ResolveClientIpObserver::testCloudflareResolvesCfConnectingIp' => function () {
            $this->assert_ResolveClientIpObserver_testCloudflareResolvesCfConnectingIp();
        },
            'ResolveClientIpObserver::testCloudflareReturnsNullWithoutVendorHeader' => function () {
            $this->assert_ResolveClientIpObserver_testCloudflareReturnsNullWithoutVendorHeader();
        },
            'ResolveClientIpObserver::testCloudflarePrefersCfConnectingIpOverTrueClientIp' => function () {
            $this->assert_ResolveClientIpObserver_testCloudflarePrefersCfConnectingIpOverTrueClientIp();
        },
            'ResolveClientIpObserver::testObserverIgnoresWhenTrustedProxyFalse' => function () {
            $this->assert_ResolveClientIpObserver_testObserverIgnoresWhenTrustedProxyFalse();
        },
            'ResolveClientIpObserver::testEventXmlRegistersServerOwnedResolveClientIp' => function () {
            $this->assert_ResolveClientIpObserver_testEventXmlRegistersServerOwnedResolveClientIp();
        },
            'ResolveClientIpObserver::testAdapterInterfaceDeclaresResolveClientIpFromHeaders' => function () {
            $this->assert_ResolveClientIpObserver_testAdapterInterfaceDeclaresResolveClientIpFromHeaders();
        },
        ];
    }

private function assert_ApplyOriginNoStoreEdgeHeaders_testObserverListensAndCloudflareWritesVendorHeaders(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn';
    $eventXml = (string) file_get_contents($root . '/etc/event.xml');
    $observer = (string) file_get_contents($root . '/Observer/ApplyOriginNoStoreEdgeHeaders.php');
    $cloudflare = (string) file_get_contents($root . '/Adapter/Cloudflare.php');
    $iface = (string) file_get_contents($root . '/Api/OriginNoStoreEdgeHeaderWriterInterface.php');
    self::assertFileExists($root . '/Api/OriginNoStoreEdgeHeaderWriterInterface.php');
    self::assertStringContainsString('Weline_Framework::response::shared_cache_forbidden', $eventXml);
    self::assertStringContainsString('ApplyOriginNoStoreEdgeHeaders', $eventXml);
    self::assertStringContainsString('OriginNoStoreEdgeHeaderWriterInterface', $observer);
    self::assertStringContainsString('\bno-store\b', $observer);
    self::assertStringContainsString('OriginNoStoreEdgeHeaderWriterInterface', $cloudflare);
    self::assertStringContainsString('applyOriginNoStoreEdgeHeaders', $cloudflare);
    self::assertStringContainsString("setHeader('CDN-Cache-Control', 'no-store')", $cloudflare);
    self::assertStringContainsString("setHeader('Cloudflare-CDN-Cache-Control', 'no-store')", $cloudflare);
    self::assertStringContainsString('applyOriginNoStoreEdgeHeaders', $iface);
}

private function assert_ResolveClientIpObserver_testCloudflareResolvesCfConnectingIp(): void
{
    $adapter = new Cloudflare();
    self::assertSame('203.0.113.77', $adapter->resolveClientIpFromHeaders(['cf-connecting-ip' => '203.0.113.77', 'x-forwarded-for' => '203.0.113.77, 104.22.83.24']));
}

private function assert_ResolveClientIpObserver_testCloudflareReturnsNullWithoutVendorHeader(): void
{
    $adapter = new Cloudflare();
    self::assertNull($adapter->resolveClientIpFromHeaders(['x-forwarded-for' => '203.0.113.77, 104.22.83.24']));
}

private function assert_ResolveClientIpObserver_testCloudflarePrefersCfConnectingIpOverTrueClientIp(): void
{
    $adapter = new Cloudflare();
    self::assertSame('203.0.113.1', $adapter->resolveClientIpFromHeaders(['cf-connecting-ip' => '203.0.113.1', 'true-client-ip' => '203.0.113.2']));
}

private function assert_ResolveClientIpObserver_testObserverIgnoresWhenTrustedProxyFalse(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Cdn/Observer/ResolveClientIpObserver.php');
    self::assertStringContainsString("getData('trusted_proxy')", $src);
    self::assertStringContainsString('resolveClientIpFromHeaders', $src);
    self::assertStringContainsString("setData('resolved_by'", $src);
}

private function assert_ResolveClientIpObserver_testEventXmlRegistersServerOwnedResolveClientIp(): void
{
    $xml = (string) \file_get_contents(BP . '/app/code/Weline/Cdn/etc/event.xml');
    self::assertStringContainsString('Weline_Server::security::resolve_client_ip', $xml);
    self::assertStringContainsString('Weline\Cdn\Observer\ResolveClientIpObserver', $xml);
}

private function assert_ResolveClientIpObserver_testAdapterInterfaceDeclaresResolveClientIpFromHeaders(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Cdn/Api/AdapterInterface.php');
    self::assertStringContainsString('function resolveClientIpFromHeaders(array $headers): ?string', $src);
}
}
