<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\AiSiteDomainPreparationService;
use Weline\Websites\Service\AiSiteDomainPurchaseAccountService;
use Weline\Websites\Service\AiSiteWebsiteTargetResolver;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\DomainPurchaseService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

final class AiSiteDomainPreparationServiceTest extends TestCase
{
    public function testTestModePreparesDurableLocalDomainWithoutCallingPurchaseServices(): void
    {
        $adapter = $this->createMock(DomainRegistrarInterface::class);
        $adapter->expects(self::never())->method('checkAvailability');
        $accountService = $this->accountService($adapter);
        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects(self::never())->method('createAndProcessOrder');
        $defaultWebsiteService = $this->createMock(DefaultWebsiteService::class);
        $defaultWebsiteService->expects(self::once())
            ->method('ensureDefaultWebsite')
            ->with(false)
            ->willReturn([]);
        $hostsSyncService = $this->createMock(LocalWelineHostsSyncService::class);
        $hostsSyncService->expects(self::once())
            ->method('ensureHostsInjected')
            ->with('demo-site.weline.test')
            ->willReturn(['success' => true, 'message' => 'ok']);
        $certificateService = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certificateService->expects(self::once())
            ->method('ensureWildcardCertificateForDomain')
            ->with('demo-site.weline.test', Website::ID_DEFAULT)
            ->willReturn(['success' => true, 'message' => 'ok']);
        $websiteTargetResolver = $this->createMock(AiSiteWebsiteTargetResolver::class);
        $websiteTargetResolver->expects(self::once())
            ->method('resolve')
            ->with(self::isInstanceOf(AiSiteProvisioningRequest::class), 'demo-site.weline.test', '')
            ->willReturn(21);

        $beforeExternalPurchaseCalled = false;
        $result = (new AiSiteDomainPreparationService(
            $accountService,
            $purchaseService,
            $defaultWebsiteService,
            $this->expectedLocalPool('demo-site.weline.test'),
            $this->bindingDomain(21),
            $hostsSyncService,
            $certificateService,
            $websiteTargetResolver,
            $this->unusedWebsite(),
        ))->prepare(
            $this->request(AiSiteProvisioningRequest::DOMAIN_MODE_TEST, 'demo-site.weline.test'),
            static function () use (&$beforeExternalPurchaseCalled): void {
                $beforeExternalPurchaseCalled = true;
            }
        );

        self::assertFalse($beforeExternalPurchaseCalled);
        self::assertSame(21, $result['website_id']);
        self::assertSame(0, $result['purchase_order_id']);
        self::assertTrue($result['local_ready']);
        self::assertSame([
            'domain' => 'demo-site.weline.test',
            'available' => true,
            'simulated' => true,
        ], $result['availability']);
    }

    public function testTestModeProjectsMacAuthorizationAsRetryablePendingWithoutPreparingCertificate(): void
    {
        $adapter = $this->createMock(DomainRegistrarInterface::class);
        $adapter->expects(self::never())->method('checkAvailability');
        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects(self::never())->method('createAndProcessOrder');
        $defaultWebsiteService = $this->createMock(DefaultWebsiteService::class);
        $defaultWebsiteService->expects(self::once())
            ->method('ensureDefaultWebsite')
            ->with(false)
            ->willReturn([]);
        $hostsSyncService = $this->createMock(LocalWelineHostsSyncService::class);
        $hostsSyncService->expects(self::once())
            ->method('ensureHostsInjected')
            ->with('demo-site.weline.test')
            ->willReturn([
                'success' => false,
                'authorization_pending' => true,
                'authorization_already_started' => false,
                'message' => 'authorization pending',
            ]);
        $certificateService = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certificateService->expects(self::never())->method('ensureWildcardCertificateForDomain');
        $websiteTargetResolver = $this->createMock(AiSiteWebsiteTargetResolver::class);
        $websiteTargetResolver->expects(self::never())->method('resolve');

        $result = (new AiSiteDomainPreparationService(
            $this->accountService($adapter),
            $purchaseService,
            $defaultWebsiteService,
            $this->unusedPool(),
            $this->bindingDomain(),
            $hostsSyncService,
            $certificateService,
            $websiteTargetResolver,
            $this->unusedWebsite(),
        ))->prepare(
            $this->request(AiSiteProvisioningRequest::DOMAIN_MODE_TEST, 'demo-site.weline.test')
        );

        self::assertTrue($result['authorization_pending']);
        self::assertFalse($result['authorization_already_started']);
        self::assertFalse($result['local_ready']);
        self::assertSame(0, $result['website_id']);
        self::assertSame('demo-site.weline.test', $result['availability']['domain']);
    }

    public function testPurchaseModeAcceptsOnlyAnExactSuccessfulDomainResult(): void
    {
        $adapter = $this->createMock(DomainRegistrarInterface::class);
        $adapter->method('isDomainRegistrar')->willReturn(true);
        $adapter->expects(self::once())
            ->method('checkAvailability')
            ->with('brand-example.com', [
                'api_key' => 'private-key',
                'api_secret' => 'private-secret',
            ])
            ->willReturn([
                'domain' => 'brand-example.com',
                'available' => true,
                'price' => 12.5,
                'currency' => 'USD',
            ]);
        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects(self::once())
            ->method('createAndProcessOrder')
            ->with(
                33,
                self::callback(static function (array $items): bool {
                    return $items === [[
                        'domain' => 'brand-example.com',
                        'years' => 2,
                        'website_id' => Website::ID_DEFAULT,
                        'auto_create_site' => 'no',
                        'resolve_to_local' => 'yes',
                        'subdomains' => ['@', 'www'],
                        'start_lifecycle' => '1',
                    ]];
                }),
                true
            )
            ->willReturn([
                'success' => true,
                'order_id' => 73,
                'results' => [['domain' => 'BRAND-EXAMPLE.COM', 'success' => true]],
            ]);
        $defaultWebsiteService = $this->createMock(DefaultWebsiteService::class);
        $defaultWebsiteService->expects(self::once())
            ->method('ensureDefaultWebsite')
            ->with(false)
            ->willReturn([]);

        $beforeExternalPurchaseCalls = 0;
        $result = $this->preparation(
            $this->accountService($adapter),
            $purchaseService,
            $defaultWebsiteService
        )->prepare(
            $this->request(AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE, 'brand-example.com', [
                AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => 33,
                AiSiteProvisioningRequest::schema_fields_YEARS => 2,
                AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 1,
            ]),
            static function () use (&$beforeExternalPurchaseCalls): void {
                $beforeExternalPurchaseCalls++;
            }
        );

        self::assertSame(1, $beforeExternalPurchaseCalls);
        self::assertSame(73, $result['purchase_order_id']);
        self::assertFalse($result['local_ready']);
        self::assertSame('brand-example.com', $result['availability']['domain']);
    }

    public function testPurchaseModeRejectsAggregateSuccessWithoutExactTargetSuccess(): void
    {
        $adapter = $this->createMock(DomainRegistrarInterface::class);
        $adapter->method('isDomainRegistrar')->willReturn(true);
        $adapter->method('checkAvailability')->willReturn([
            'domain' => 'brand-example.com',
            'available' => true,
        ]);
        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects(self::once())
            ->method('createAndProcessOrder')
            ->willReturn([
                'success' => true,
                'order_id' => 73,
                'results' => [['domain' => 'other-domain.com', 'success' => true]],
            ]);
        $defaultWebsiteService = $this->createMock(DefaultWebsiteService::class);
        $defaultWebsiteService->expects(self::never())->method('ensureDefaultWebsite');

        try {
            $this->preparation(
                $this->accountService($adapter),
                $purchaseService,
                $defaultWebsiteService
            )->prepare($this->request(AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE, 'brand-example.com', [
                AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => 33,
                AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 1,
            ]));
            self::fail('A purchase result for a different domain must not satisfy the target-domain gate.');
        } catch (AiSiteProvisioningException $exception) {
            self::assertSame('DOMAIN_PURCHASE_FAILED', $exception->getErrorCode());
        }
    }

    private function preparation(
        AiSiteDomainPurchaseAccountService $accountService,
        DomainPurchaseService $purchaseService,
        DefaultWebsiteService $defaultWebsiteService
    ): AiSiteDomainPreparationService {
        $hostsSyncService = $this->createMock(LocalWelineHostsSyncService::class);
        $hostsSyncService->expects(self::never())->method('ensureHostsInjected');
        $certificateService = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certificateService->expects(self::never())->method('ensureWildcardCertificateForDomain');
        $websiteTargetResolver = $this->createMock(AiSiteWebsiteTargetResolver::class);
        $websiteTargetResolver->method('resolve')->willReturn(Website::ID_DEFAULT);

        return new AiSiteDomainPreparationService(
            $accountService,
            $purchaseService,
            $defaultWebsiteService,
            $this->unusedPool(),
            $this->bindingDomain(),
            $hostsSyncService,
            $certificateService,
            $websiteTargetResolver,
            $this->unusedWebsite(),
        );
    }

    /** @return DomainPool&MockObject */
    private function expectedLocalPool(string $domain): DomainPool
    {
        $poolId = 0;
        $siteCreated = false;
        $pool = $this->poolMock([
            'loadByPoolId',
            'isSiteCreated',
            'setSiteCreated',
            'setDomain',
            'setStatus',
            'setResolveStatus',
            'setDnsStatus',
            'setCdnStatus',
            'setResolvedIp',
            'setIsLocalServer',
            'setResolveCheckedAt',
            'setHttpsStatus',
            'setSiteReady',
            'setPoolLifecycleStage',
            'setConnectivityStatus',
            'setConnectivityCheckedAt',
        ]);
        $pool->method('clearData')->willReturnSelf();
        $pool->expects(self::once())->method('loadByDomain')->with($domain)->willReturnSelf();
        $pool->expects(self::once())->method('loadByPoolId')->with(12)->willReturnSelf();
        $pool->method('getPoolId')->willReturnCallback(static function () use (&$poolId): int {
            return $poolId;
        });
        $pool->expects(self::once())->method('setDomain')->with($domain)->willReturnSelf();
        $pool->expects(self::once())->method('setStatus')->with(DomainPool::STATUS_ACTIVE)->willReturnSelf();
        $pool->expects(self::once())->method('setResolveStatus')->with(DomainPool::RESOLVE_STATUS_RESOLVED)->willReturnSelf();
        $pool->expects(self::once())->method('setDnsStatus')->with(DomainPool::INFRA_STATUS_READY)->willReturnSelf();
        $pool->expects(self::once())->method('setCdnStatus')->with(DomainPool::INFRA_STATUS_READY)->willReturnSelf();
        $pool->expects(self::once())->method('setResolvedIp')->with('127.0.0.1')->willReturnSelf();
        $pool->expects(self::once())->method('setIsLocalServer')->with(true)->willReturnSelf();
        $pool->expects(self::once())->method('setResolveCheckedAt')
            ->with(self::matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D'))
            ->willReturnSelf();
        $pool->expects(self::once())->method('setHttpsStatus')->with(DomainPool::HTTPS_STATUS_VALID)->willReturnSelf();
        $pool->expects(self::once())->method('setSiteReady')->with(true)->willReturnSelf();
        $pool->expects(self::once())->method('setPoolLifecycleStage')
            ->with(DomainPool::LIFECYCLE_CERT_VALID)->willReturnSelf();
        $pool->expects(self::once())->method('setConnectivityStatus')
            ->with(DomainPool::CONNECTIVITY_OK)->willReturnSelf();
        $pool->expects(self::once())->method('setConnectivityCheckedAt')
            ->with(self::matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D'))
            ->willReturnSelf();
        $pool->method('isSiteCreated')->willReturnCallback(static function () use (&$siteCreated): bool {
            return $siteCreated;
        });
        $pool->expects(self::once())->method('setSiteCreated')->with(true)->willReturnCallback(
            static function (bool $created) use (&$siteCreated, $pool): DomainPool {
                $siteCreated = $created;
                return $pool;
            }
        );
        $pool->expects(self::exactly(2))->method('save')->willReturnCallback(
            static function () use (&$poolId): int {
                $poolId = 12;
                return 1;
            }
        );

        return $pool;
    }

    /** @return DomainPool&MockObject */
    private function unusedPool(): DomainPool
    {
        $pool = $this->poolMock(['loadByPoolId', 'isSiteCreated', 'setSiteCreated']);
        $pool->expects(self::never())->method('loadByDomain');
        $pool->expects(self::never())->method('loadByPoolId');
        $pool->expects(self::never())->method('save');

        return $pool;
    }

    /** @param list<string> $extraMethods @return DomainPool&MockObject */
    private function poolMock(array $extraMethods): DomainPool
    {
        return $this->getMockBuilder(DomainPool::class)
            ->disableOriginalConstructor()
            ->onlyMethods(\array_values(\array_unique(\array_merge([
                'clearData',
                'loadByDomain',
                'getPoolId',
                'save',
            ], $extraMethods))))
            ->getMock();
    }

    /** @return WebsiteDomain&MockObject */
    private function bindingDomain(int $websiteId = Website::ID_DEFAULT): WebsiteDomain
    {
        $persisted = false;
        $binding = $this->getMockBuilder(WebsiteDomain::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'clearData',
                'loadByDomainAndSubPath',
                'findConflict',
                'getDomainId',
                'getWebsiteId',
                'getStatus',
                'setWebsiteId',
                'setDomain',
                'setSubPath',
                'setIsPrimary',
                'setHttpsEnabled',
                'setStatus',
                'setPoolId',
                'save',
            ])
            ->getMock();
        $binding->method('clearData')->willReturnSelf();
        $binding->method('loadByDomainAndSubPath')->willReturnSelf();
        $binding->method('findConflict')->willReturn(null);
        $binding->method('getDomainId')->willReturnCallback(static function () use (&$persisted): int {
            return $persisted ? 1 : 0;
        });
        $binding->method('getWebsiteId')->willReturn($websiteId);
        $binding->method('getStatus')->willReturn(WebsiteDomain::STATUS_ACTIVE);
        foreach ([
            'setWebsiteId',
            'setDomain',
            'setSubPath',
            'setIsPrimary',
            'setHttpsEnabled',
            'setStatus',
            'setPoolId',
        ] as $method) {
            $binding->method($method)->willReturnSelf();
        }
        $binding->method('save')->willReturnCallback(static function () use (&$persisted): int {
            $persisted = true;
            return 1;
        });

        return $binding;
    }

    private function unusedWebsite(): Website|MockObject
    {
        $website = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getWebsiteId', 'getUrl', 'setUrl', 'save'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $website->method('clearData')->willReturnSelf();
        $website->method('clearQuery')->willReturnSelf();
        $website->method('load')->willReturnSelf();
        $website->method('getWebsiteId')->willReturn(0);
        $website->method('getUrl')->willReturn('');
        $website->method('setUrl')->willReturnSelf();
        $website->method('save')->willReturn(1);

        return $website;
    }

    private function accountService(DomainRegistrarInterface $adapter): AiSiteDomainPurchaseAccountService
    {
        $accountModel = $this->getMockBuilder(DomainRegistrarAccount::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'clearData',
                'load',
                'getAccountId',
                'getRegistrarId',
                'getStatus',
                'getCredentials',
            ])
            ->addMethods(['clearQuery'])
            ->getMock();
        $accountModel->method('clearData')->willReturnSelf();
        $accountModel->method('clearQuery')->willReturnSelf();
        $accountModel->method('load')->willReturnSelf();
        $accountModel->method('getAccountId')->willReturn(33);
        $accountModel->method('getRegistrarId')->willReturn(7);
        $accountModel->method('getStatus')->willReturn(DomainRegistrarAccount::STATUS_ACTIVE);
        $accountModel->method('getCredentials')->willReturn([
            'api_key' => 'private-key',
            'api_secret' => 'private-secret',
        ]);

        $registrarModel = $this->getMockBuilder(DomainRegistrar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getData'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $registrarModel->method('clearData')->willReturnSelf();
        $registrarModel->method('clearQuery')->willReturnSelf();
        $registrarModel->method('load')->willReturnSelf();
        $registrarModel->method('getData')->willReturnCallback(
            static fn (mixed $key = null): mixed => match ($key) {
                DomainRegistrar::schema_fields_ID => 7,
                DomainRegistrar::schema_fields_CODE => 'gname',
                DomainRegistrar::schema_fields_NAME => 'Gname',
                default => null,
            }
        );

        $resolver = $this->createMock(DomainRegistrarResolverService::class);
        $resolver->method('getAdapter')->with('gname')->willReturn($adapter);

        return new AiSiteDomainPurchaseAccountService($accountModel, $registrarModel, $resolver);
    }

    /** @param array<string,mixed> $extra */
    private function request(string $mode, string $domain, array $extra = []): AiSiteProvisioningRequest
    {
        $request = new AiSiteProvisioningRequest();
        $request->setData(\array_replace([
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => $mode,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => $domain,
            AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => null,
            AiSiteProvisioningRequest::schema_fields_YEARS => 1,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID => 0,
        ], $extra));

        return $request;
    }
}
