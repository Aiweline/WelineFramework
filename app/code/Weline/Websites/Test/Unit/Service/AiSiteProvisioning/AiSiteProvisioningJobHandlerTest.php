<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\AiSiteDomainPreparationService;
use Weline\Websites\Service\AiSiteDomainPurchaseAccountService;
use Weline\Websites\Service\AiSiteProvisioningJobHandler;
use Weline\Websites\Service\AiSiteProvisioningRequestRepository;
use Weline\Websites\Service\AiSiteWebsiteTargetResolver;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\DomainPurchaseService;
use Weline\Websites\Service\DomainRegistrarResolverService;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

final class AiSiteProvisioningJobHandlerTest extends TestCase
{
    public function testHandlerMarksPreparedTestDomainAsBoundToSystemDefaultWebsite(): void
    {
        $request = new AiSiteProvisioningRequest();
        $request->setData([
            AiSiteProvisioningRequest::schema_fields_ID => 9,
            AiSiteProvisioningRequest::schema_fields_REQUEST_ID => 'request-9',
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => 'demo-site.weline.test',
            AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => null,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
            AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN => 'token-9',
        ]);

        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $repository->expects(self::once())
            ->method('findByRequestId')
            ->with('request-9')
            ->willReturn($request);
        $repository->expects(self::exactly(2))->method('save')->with($request)->willReturn($request);

        $result = (new AiSiteProvisioningJobHandler(
            $repository,
            $this->testDomainPreparationService()
        ))->handle('request-9', 'token-9');

        self::assertSame(AiSiteProvisioningRequest::STATUS_DONE, $result['status']);
        self::assertSame(AiSiteProvisioningRequest::DOMAIN_MODE_TEST, $result['domain_mode']);
        self::assertSame('demo-site.weline.test', $result['target_domain']);
        self::assertSame(0, $result['purchase_order_id']);
        self::assertSame(1, $result['website_bound']);
        self::assertSame(0, $result['website_id']);
    }

    public function testHandlerKeepsAuthorizationPendingRequestRetryable(): void
    {
        $request = new AiSiteProvisioningRequest();
        $request->setData([
            AiSiteProvisioningRequest::schema_fields_ID => 10,
            AiSiteProvisioningRequest::schema_fields_REQUEST_ID => 'request-10',
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => 'demo-site.weline.test',
            AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => null,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
            AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN => 'token-10',
        ]);

        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $repository->expects(self::once())
            ->method('findByRequestId')
            ->with('request-10')
            ->willReturn($request);
        $repository->expects(self::exactly(2))->method('save')->with($request)->willReturn($request);

        $result = (new AiSiteProvisioningJobHandler(
            $repository,
            $this->testDomainPreparationService([
                'success' => false,
                'authorization_pending' => true,
                'authorization_already_started' => false,
                'message' => 'authorization pending',
            ])
        ))->handle('request-10', 'token-10');

        self::assertSame(AiSiteProvisioningRequest::STATUS_PENDING, $result['status']);
        self::assertTrue($result['authorization_pending']);
        self::assertFalse($result['authorization_already_started']);
        self::assertSame('', $request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE));
        self::assertSame(
            'authorization pending',
            $request->getData(AiSiteProvisioningRequest::schema_fields_MESSAGE)
        );
    }

    public function testHandlerMarksExpiredAuthorizationWaitAsStableTerminalError(): void
    {
        $request = new AiSiteProvisioningRequest();
        $request->setData([
            AiSiteProvisioningRequest::schema_fields_ID => 11,
            AiSiteProvisioningRequest::schema_fields_REQUEST_ID => 'request-11',
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => 'demo-site.weline.test',
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
            AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN => 'token-11',
        ]);

        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $repository->expects(self::once())
            ->method('findByRequestId')
            ->with('request-11')
            ->willReturn($request);
        $repository->expects(self::once())->method('save')->with($request)->willReturn($request);

        try {
            (new AiSiteProvisioningJobHandler(
                $repository,
                $this->unusedDomainPreparationService()
            ))->handle('request-11', 'token-11', true);
            self::fail('Expired authorization wait must be terminal.');
        } catch (AiSiteProvisioningException $exception) {
            self::assertSame(
                'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
                $exception->getErrorCode()
            );
        }

        self::assertSame(
            AiSiteProvisioningRequest::STATUS_ERROR,
            $request->getData(AiSiteProvisioningRequest::schema_fields_STATUS)
        );
        self::assertSame(
            'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
            $request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE)
        );
    }

    /** @param array<string,mixed> $hostsResult */
    private function testDomainPreparationService(array $hostsResult = ['success' => true]): AiSiteDomainPreparationService
    {
        $accountService = new AiSiteDomainPurchaseAccountService(
            $this->createStub(DomainRegistrarAccount::class),
            $this->createStub(DomainRegistrar::class),
            $this->createStub(DomainRegistrarResolverService::class),
        );
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
            ->willReturn($hostsResult);
        $certificateService = $this->createMock(LocalWelineWildcardCertificateService::class);
        if (($hostsResult['authorization_pending'] ?? false) === true) {
            $certificateService->expects(self::never())->method('ensureWildcardCertificateForDomain');
        } else {
            $certificateService->expects(self::once())
                ->method('ensureWildcardCertificateForDomain')
                ->with('demo-site.weline.test', 0)
                ->willReturn(['success' => true]);
        }

        $poolId = 0;
        $siteCreated = false;
        $domainPool = $this->getMockBuilder(DomainPool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'clearData',
                'loadByDomain',
                'loadByPoolId',
                'getPoolId',
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
                'save',
            ])
            ->getMock();
        $domainPool->method('clearData')->willReturnSelf();
        $domainPool->method('loadByDomain')->willReturnSelf();
        $domainPool->method('loadByPoolId')->willReturnSelf();
        $domainPool->method('getPoolId')->willReturnCallback(static function () use (&$poolId): int {
            return $poolId;
        });
        foreach ([
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
        ] as $method) {
            $domainPool->method($method)->willReturnSelf();
        }
        $domainPool->method('isSiteCreated')->willReturnCallback(static function () use (&$siteCreated): bool {
            return $siteCreated;
        });
        $domainPool->method('setSiteCreated')->willReturnCallback(
            static function (bool $created) use (&$siteCreated, $domainPool): DomainPool {
                $siteCreated = $created;
                return $domainPool;
            }
        );
        $domainPool->method('save')->willReturnCallback(static function () use (&$poolId): int {
            $poolId = 12;
            return 1;
        });

        $persisted = false;
        $websiteDomain = $this->getMockBuilder(WebsiteDomain::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'clearData',
                'loadByDomainAndSubPath',
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
        $websiteDomain->method('clearData')->willReturnSelf();
        $websiteDomain->method('loadByDomainAndSubPath')->willReturnSelf();
        $websiteDomain->method('getDomainId')->willReturnCallback(static function () use (&$persisted): int {
            return $persisted ? 1 : 0;
        });
        $websiteDomain->method('getWebsiteId')->willReturn(0);
        $websiteDomain->method('getStatus')->willReturn(WebsiteDomain::STATUS_ACTIVE);
        foreach ([
            'setWebsiteId',
            'setDomain',
            'setSubPath',
            'setIsPrimary',
            'setHttpsEnabled',
            'setStatus',
            'setPoolId',
        ] as $method) {
            $websiteDomain->method($method)->willReturnSelf();
        }
        $websiteDomain->method('save')->willReturnCallback(static function () use (&$persisted): int {
            $persisted = true;
            return 1;
        });
        $websiteTargetResolver = $this->createMock(AiSiteWebsiteTargetResolver::class);
        $websiteTargetResolver->method('resolve')->willReturn(0);

        return new AiSiteDomainPreparationService(
            $accountService,
            $purchaseService,
            $defaultWebsiteService,
            $domainPool,
            $websiteDomain,
            $hostsSyncService,
            $certificateService,
            $websiteTargetResolver,
        );
    }

    private function unusedDomainPreparationService(): AiSiteDomainPreparationService
    {
        return new AiSiteDomainPreparationService(
            new AiSiteDomainPurchaseAccountService(
                $this->createStub(DomainRegistrarAccount::class),
                $this->createStub(DomainRegistrar::class),
                $this->createStub(DomainRegistrarResolverService::class),
            ),
            $this->createStub(DomainPurchaseService::class),
            $this->createStub(DefaultWebsiteService::class),
            $this->createStub(DomainPool::class),
            $this->createStub(WebsiteDomain::class),
            $this->createStub(LocalWelineHostsSyncService::class),
            $this->createStub(LocalWelineWildcardCertificateService::class),
            $this->createStub(AiSiteWebsiteTargetResolver::class),
        );
    }
}
