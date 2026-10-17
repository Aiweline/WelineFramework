<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\ClientIpEventResolver;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllServerSecurityContractsPass().
 */
final class ServerSecurityContractsTest extends TestCase
{
    public function testAllServerSecurityContractsPass(): void
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
            'ClientIpEventResolver::testEventNameIsServerOwnedSecurityResolveClientIp' => function () {
            $this->assert_ClientIpEventResolver_testEventNameIsServerOwnedSecurityResolveClientIp();
        },
            'ClientIpEventResolver::testUntrustedProxyNeverDispatchesAndKeepsDefaultIp' => function () {
            $this->assert_ClientIpEventResolver_testUntrustedProxyNeverDispatchesAndKeepsDefaultIp();
        },
            'ClientIpEventResolver::testWorkerPolicyKernelCallsClientIpEventResolverAfterIdentity' => function () {
            $this->assert_ClientIpEventResolver_testWorkerPolicyKernelCallsClientIpEventResolverAfterIdentity();
        },
            'ClientIpEventResolver::testManagedNginxForwardsCfConnectingIpHeader' => function () {
            $this->assert_ClientIpEventResolver_testManagedNginxForwardsCfConnectingIpHeader();
        },
            'ClientIpEventResolver::testEventRegisteredInServerEventPhp' => function () {
            $this->assert_ClientIpEventResolver_testEventRegisteredInServerEventPhp();
        },
            'CrawlerAllowPassSharedBan::testKernelSkipsSharedBanWhenCrawlerAllowPass' => function () {
            $this->assert_CrawlerAllowPassSharedBan_testKernelSkipsSharedBanWhenCrawlerAllowPass();
        },
            'CrawlerAllowPassSharedBan::testCatalogExposesIsAllowPass' => function () {
            $this->assert_CrawlerAllowPassSharedBan_testCatalogExposesIsAllowPass();
        },
            'StaticExtensionM4a::testWorkerStaticAllowlistIncludesM4aAudioMime' => function () {
            $this->assert_StaticExtensionM4a_testWorkerStaticAllowlistIncludesM4aAudioMime();
        },
        ];
    }

private function assert_ClientIpEventResolver_testEventNameIsServerOwnedSecurityResolveClientIp(): void
{
    self::assertSame('Weline_Server::security::resolve_client_ip', ClientIpEventResolver::EVENT);
}

private function assert_ClientIpEventResolver_testUntrustedProxyNeverDispatchesAndKeepsDefaultIp(): void
{
    $out = ClientIpEventResolver::refine('203.0.113.9', false, '198.51.100.1', ['cf-connecting-ip' => '203.0.113.50'], ['127.0.0.0/8']);
    self::assertSame('203.0.113.9', $out['ip']);
    self::assertNull($out['resolved_by']);
}

private function assert_ClientIpEventResolver_testWorkerPolicyKernelCallsClientIpEventResolverAfterIdentity(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Server/Security/WorkerPolicyKernel.php');
    self::assertStringContainsString('ClientIpEventResolver::refine(', $src);
    self::assertStringContainsString("\$clientIp = \$refined['ip'];", $src);
    self::assertStringNotContainsString('CF-Connecting-IP', $src);
    self::assertStringNotContainsString('cf-connecting-ip', $src);
}

private function assert_ClientIpEventResolver_testManagedNginxForwardsCfConnectingIpHeader(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php');
    self::assertStringContainsString('proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;', $src);
    self::assertGreaterThanOrEqual(7, \substr_count($src, 'proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;'));
}

private function assert_ClientIpEventResolver_testEventRegisteredInServerEventPhp(): void
{
    $events = include BP . '/app/code/Weline/Server/event.php';
    self::assertArrayHasKey(ClientIpEventResolver::EVENT, $events);
    self::assertSame('security/解析客户端IP.md', $events[ClientIpEventResolver::EVENT]['doc'] ?? null);
}

private function assert_CrawlerAllowPassSharedBan_testKernelSkipsSharedBanWhenCrawlerAllowPass(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Security/WorkerPolicyKernel.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('isCrawlerAllowPass', $src);
    self::assertStringContainsString('CrawlerBlockCatalog::isAllowPass', $src);
    $banPos = \strpos($src, "return \$this->deny(\$parsed, \$clientIp, \$trustedProxy, 403, 'shared_ban')");
    self::assertNotFalse($banPos);
    $window = \substr($src, \max(0, $banPos - 500), 600);
    self::assertStringContainsString('isCrawlerAllowPass', $window);
    $pathScanPos = \strpos($src, "pathScan['enabled']");
    self::assertNotFalse($pathScanPos);
    $pathWindow = \substr($src, $pathScanPos, 700);
    self::assertStringContainsString('isCrawlerAllowPass', $pathWindow);
    self::assertStringContainsString('allowUniquePath', $pathWindow);
    // Attack deny may still happen; IP ban must gate on allow pass.
    self::assertStringContainsString('never poison IP ban list', $src);
}

private function assert_CrawlerAllowPassSharedBan_testCatalogExposesIsAllowPass(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Security/CrawlerBlockCatalog.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function isAllowPass', $src);
    self::assertStringContainsString('Hard floor', $src);
    self::assertStringContainsString('not an Origin-Token', $src);
}

private function assert_StaticExtensionM4a_testWorkerStaticAllowlistIncludesM4aAudioMime(): void
{
    $worker = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
    $workerSsl = (string) \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
    $policy = (string) \file_get_contents(BP . 'app/code/Weline/Server/Security/WorkerPolicyKernel.php');
    self::assertMatchesRegularExpression("/'mp3',\\s*'m4a'/", $worker);
    self::assertMatchesRegularExpression("/'mp3',\\s*'m4a'/", $workerSsl);
    self::assertStringContainsString("'m4a' => 'audio/mp4'", $worker);
    self::assertStringContainsString("'m4a' => 'audio/mp4'", $workerSsl);
    self::assertStringContainsString("'m4a' => true", $policy);
}
}
