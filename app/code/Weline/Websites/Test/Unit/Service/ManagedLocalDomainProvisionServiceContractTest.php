<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;
use Weline\Websites\Service\ManagedLocalDomainProvisionService;

final class ManagedLocalDomainProvisionServiceContractTest extends TestCase
{
    public function testPublicDomainIsSkippedWithoutHostsOrCertificateCalls(): void
    {
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::never())->method('ensureHostsInjected');
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');

        $service = new ManagedLocalDomainProvisionService($hosts, $certs, null, static fn (): array => [
            'ok' => true,
            'skipped' => true,
        ]);
        $result = $service->ensureForManualCreate('example.com');

        self::assertFalse($result['success']);
        self::assertTrue($result['skipped'] ?? false);
        self::assertFalse($result['local_ready']);
        self::assertFalse($service->isManagedLocalDomain('example.com'));
    }

    public function testManagedLocalDomainRunsHostsThenCertificateAndReportsReady(): void
    {
        $domain = 'grocery.test.weline.com';
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::once())
            ->method('ensureHostsInjected')
            ->with($domain)
            ->willReturn(['success' => true, 'domain' => $domain]);

        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::once())
            ->method('ensureWildcardCertificateForDomain')
            ->with($domain, 0)
            ->willReturn([
                'success' => true,
                'domain' => $domain,
                'wildcard_domain' => '*.test.weline.com',
                'cert_id' => 42,
            ]);

        $pool = $this->createMock(DomainPool::class);
        $pool->method('loadByDomain')->willReturnSelf();
        $pool->method('getPoolId')->willReturn(7);
        $pool->method('getHttpsStatus')->willReturn(DomainPool::HTTPS_STATUS_NONE);
        $pool->expects(self::atLeastOnce())->method('setIsLocalServer')->with(true)->willReturnSelf();
        $pool->expects(self::atLeastOnce())->method('setHttpsStatus')->willReturnSelf();
        $pool->expects(self::atLeastOnce())->method('setCertId')->with(42)->willReturnSelf();
        $pool->expects(self::atLeastOnce())->method('save')->willReturn(true);
        $pool->method('setStatus')->willReturnSelf();
        $pool->method('setResolveStatus')->willReturnSelf();
        $pool->method('setDnsStatus')->willReturnSelf();
        $pool->method('setCdnStatus')->willReturnSelf();
        $pool->method('setResolveCheckedAt')->willReturnSelf();
        $pool->method('setResolveError')->willReturnSelf();
        $pool->method('setResolvedIp')->willReturnSelf();
        $pool->method('setHttpsError')->willReturnSelf();
        $pool->method('setPoolLifecycleStage')->willReturnSelf();
        $pool->method('setSiteReady')->willReturnSelf();
        $pool->method('calculateSiteReady')->willReturn(true);

        $edgeCalled = false;
        $service = new ManagedLocalDomainProvisionService(
            $hosts,
            $certs,
            $pool,
            static function () use (&$edgeCalled): array {
                $edgeCalled = true;

                return ['ok' => true, 'message' => 'edge synced'];
            },
        );
        self::assertTrue($service->isManagedLocalDomain($domain));

        $result = $service->ensureForManualCreate($domain, 0);

        self::assertTrue($result['success']);
        self::assertTrue($result['local_ready']);
        self::assertSame($domain, $result['domain']);
        self::assertTrue(($result['hosts']['success'] ?? false) === true);
        self::assertTrue(($result['certificate']['success'] ?? false) === true);
        self::assertTrue($edgeCalled);
        self::assertTrue(($result['edge']['ok'] ?? false) === true);
    }

    public function testAuthorizationPendingStopsBeforePretendingReady(): void
    {
        $domain = 'pending-demo.test.weline.com';
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::once())
            ->method('ensureHostsInjected')
            ->willReturn([
                'success' => false,
                'authorization_pending' => true,
                'authorization_already_started' => true,
                'message' => '等待管理员批准',
            ]);
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');

        $pool = $this->createMock(DomainPool::class);
        $pool->method('loadByDomain')->willReturnSelf();
        $pool->method('getPoolId')->willReturn(3);
        $pool->method('getHttpsStatus')->willReturn(DomainPool::HTTPS_STATUS_NONE);
        $pool->method('setStatus')->willReturnSelf();
        $pool->method('setResolveStatus')->willReturnSelf();
        $pool->method('setDnsStatus')->willReturnSelf();
        $pool->method('setCdnStatus')->willReturnSelf();
        $pool->method('setIsLocalServer')->willReturnSelf();
        $pool->method('setResolveCheckedAt')->willReturnSelf();
        $pool->method('setResolveError')->willReturnSelf();
        $pool->method('setResolvedIp')->willReturnSelf();
        $pool->method('setHttpsStatus')->willReturnSelf();
        $pool->method('setPoolLifecycleStage')->willReturnSelf();
        $pool->method('setSiteReady')->willReturnSelf();
        $pool->method('calculateSiteReady')->willReturn(false);
        $pool->method('save')->willReturn(true);

        $service = new ManagedLocalDomainProvisionService(
            $hosts,
            $certs,
            $pool,
            static function (): array {
                self::fail('edge sync must not run while hosts authorization is pending');
            },
        );
        $result = $service->ensureForManualCreate($domain);

        self::assertFalse($result['success']);
        self::assertFalse($result['local_ready']);
        self::assertTrue($result['authorization_pending'] ?? false);
        self::assertTrue($result['authorization_already_started'] ?? false);
    }
}
