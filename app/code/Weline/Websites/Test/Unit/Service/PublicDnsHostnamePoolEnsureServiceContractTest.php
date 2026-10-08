<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 公网 DNS 通后入域名池 + 证书：架构契约（源码门禁，不打真 CA）。
 */
final class PublicDnsHostnamePoolEnsureServiceContractTest extends TestCase
{
    private function ensureSource(): string
    {
        $path = BP . '/app/code/Weline/Websites/Service/PublicDnsHostnamePoolEnsureService.php';
        self::assertFileExists($path);

        return (string) \file_get_contents($path);
    }

    public function testServiceOwnsPoolUpsertAndImmediateDns01Cert(): void
    {
        $src = $this->ensureSource();
        self::assertStringContainsString('class PublicDnsHostnamePoolEnsureService', $src);
        self::assertStringContainsString('ensureRootDomain', $src);
        self::assertStringContainsString('ensurePoolHostname', $src);
        self::assertStringContainsString('ensureDnsRegistrarAccount', $src);
        self::assertStringContainsString("challenge_strategy' => 'dns01'", $src);
        self::assertStringContainsString('CertificateRequestService', $src);
        self::assertStringContainsString('applyAfterResolvePass', $src);
        self::assertStringContainsString('markCertPending', $src);
        self::assertStringNotContainsString('LocalDomainRegisteredEventDispatcher', $src);
        self::assertStringNotContainsString('Weline_Server::domain::local_domain_registered', $src);
    }

    public function testQueryProviderExposesEnsureOperation(): void
    {
        $path = BP . '/app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("'ensurePublicDnsHostnamesInPool'", $src);
        self::assertStringContainsString('function ensurePublicDnsHostnamesInPool', $src);
        self::assertStringContainsString('PublicDnsHostnamePoolEnsureService', $src);
    }

    public function testMailDnsApplyHooksPoolEnsureViaQuery(): void
    {
        $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('ensureMailHostnamesInDomainPool', $src);
        self::assertStringContainsString("w_query('websites', 'ensurePublicDnsHostnamesInPool'", $src);
        self::assertStringContainsString("'source' => 'mail_dns'", $src);
    }
}
