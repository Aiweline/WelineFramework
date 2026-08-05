<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Service\AiSiteLocalDomainReadinessService;
use Weline\Websites\Service\AiSiteProvisioningQueueGateway;
use Weline\Websites\Service\AiSiteProvisioningRequestRepository;
use Weline\Websites\Service\AiSiteProvisioningService;
use Weline\Websites\Service\AiSiteStartPageService;

final class AiSiteProvisioningServiceTest extends TestCase
{
    public function testTestModeCreatesOnePendingQueueWithServerOwnedAdminAndDefaultWebsiteBindingTarget(): void
    {
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::once())
            ->method('prepare')
            ->with('demo-site.weline.test', true)
            ->willReturn([
                'can_start' => false,
                'authorization_pending' => true,
                'code' => 'TEST_DOMAIN_HOSTS_AUTHORIZATION_PENDING',
            ]);
        $repository->expects(self::once())
            ->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (array $data): AiSiteProvisioningRequest {
                self::assertSame(27, $data[AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID]);
                self::assertSame(
                    AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
                    $data[AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE]
                );
                self::assertSame(
                    'demo-site.weline.test',
                    $data[AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN]
                );
                self::assertNull($data[AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID]);
                self::assertSame(0, $data[AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED]);
                self::assertSame(0, $data[AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED]);
                self::assertSame(0, $data[AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID]);
                self::assertSame(0, $data[AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID]);

                return $this->request($data);
            });
        $gateway->expects(self::once())
            ->method('enqueue')
            ->with(
                self::callback(static fn (string $requestId): bool => \strlen($requestId) === 32),
                self::callback(static fn (string $token): bool => \strlen($token) === 32)
            )
            ->willReturn([
                'queue_id' => 41,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:test',
            ]);
        $repository->expects(self::once())->method('save');
        $gateway->expects(self::once())
            ->method('get')
            ->with(41)
            ->willReturn([
                'queue_id' => 41,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:test',
            ]);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'HTTPS://Demo-Site.WELINE.TEST/path',
        ]);

        self::assertTrue($result['success']);
        self::assertSame(AiSiteProvisioningRequest::STATUS_PENDING, $result['status']);
        self::assertSame(AiSiteProvisioningRequest::DOMAIN_MODE_TEST, $result['domain_mode']);
        self::assertSame('demo-site.weline.test', $result['target_domain']);
        self::assertSame(0, $result['purchase_order_id']);
        self::assertSame(0, $result['website_bound']);
        self::assertSame(0, $result['website_id']);
        self::assertSame(['queue_id' => 41, 'status' => 'pending'], $result['queue']);
    }

    public function testSamePurchaseCommandReturnsExistingQueueWithoutCreatingDuplicate(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_REQUEST_ID => 'existing-request',
            AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID => 27,
            AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE => 'GuoLaiRen_PageBuilder',
            AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID => 'pagebuilder-session-1',
            AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID => 'cmd-1',
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => 'brand-example.com',
            AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => 9,
            AiSiteProvisioningRequest::schema_fields_YEARS => 2,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 1,
            AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::never())->method('inspect');
        $repository->expects(self::once())
            ->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')
            ->willReturn($request);
        $repository->expects(self::never())->method('create');
        $gateway->expects(self::never())->method('enqueue');
        $gateway->expects(self::once())
            ->method('get')
            ->with(52)
            ->willReturn([
                'queue_id' => 52,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:existing-request',
            ]);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'purchase',
            'target_domain' => 'brand-example.com',
            'registrar_account_id' => 9,
            'years' => 2,
            'confirm' => true,
        ]);

        self::assertSame(52, $result['queue']['queue_id']);
        self::assertSame(AiSiteProvisioningRequest::DOMAIN_MODE_PURCHASE, $result['domain_mode']);
        self::assertSame(9, $result['registrar_account_id']);
        self::assertSame(0, $result['website_id']);
        self::assertSame(0, $result['website_bound']);
    }

    public function testCompletedPageBuilderDefaultBindingIsRequeuedForDedicatedWebsite(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_DONE,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 1,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::never())->method('prepare');
        $repository->expects(self::once())
            ->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')
            ->willReturn($request);
        $repository->expects(self::exactly(2))->method('save');
        $gateway->expects(self::once())
            ->method('enqueue')
            ->with(
                $request->getRequestId(),
                self::callback(static fn (string $token): bool => \strlen($token) === 32)
            )
            ->willReturn(['queue_id' => 77, 'status' => 'pending']);
        $gateway->expects(self::once())
            ->method('get')
            ->with(77)
            ->willReturn(['queue_id' => 77, 'status' => 'pending']);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
            'source_module' => 'GuoLaiRen_PageBuilder',
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'demo-site.weline.test',
        ]);

        self::assertSame(AiSiteProvisioningRequest::STATUS_PENDING, $result['status']);
        self::assertSame(['queue_id' => 77, 'status' => 'pending'], $result['queue']);
        self::assertSame(0, $result['website_bound']);
        self::assertSame(0, $result['website_id']);
    }

    public function testExplicitRetryRearmsExistingErrorRequestWithoutChangingIdentity(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_ERROR,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
            AiSiteProvisioningRequest::schema_fields_ERROR_CODE => 'TEST_DOMAIN_HOSTS_FAILED',
            AiSiteProvisioningRequest::schema_fields_MESSAGE => 'previous failure',
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::once())
            ->method('prepare')
            ->with('demo-site.weline.test', true)
            ->willReturn([
                'can_start' => false,
                'authorization_pending' => true,
                'code' => 'TEST_DOMAIN_HOSTS_AUTHORIZATION_PENDING',
            ]);
        $repository->expects(self::once())->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')->willReturn($request);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::once())->method('save')->with(self::identicalTo($request));
        $gateway->expects(self::never())->method('enqueue');
        $gateway->expects(self::once())->method('rearm')
            ->with($request->getRequestId(), 'abcdef0123456789abcdef0123456789')
            ->willReturn([
                'queue_id' => 52,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:' . $request->getRequestId(),
                'rearmed' => true,
                'idempotent' => false,
            ]);
        $gateway->expects(self::once())->method('get')->with(52)->willReturn([
            'queue_id' => 52,
            'status' => 'pending',
            'biz_key' => 'websites:ai_site_provisioning:' . $request->getRequestId(),
        ]);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'demo-site.weline.test',
            'rearm_failed' => true,
        ]);

        self::assertSame('0123456789abcdef0123456789abcdef', $result['request_id']);
        self::assertSame(AiSiteProvisioningRequest::STATUS_PENDING, $result['status']);
        self::assertSame(52, $result['queue_id']);
        self::assertSame('websites:ai_site_provisioning:' . $request->getRequestId(), $result['biz_key']);
        self::assertTrue($result['rearmed']);
        self::assertFalse($result['idempotent']);
        self::assertSame('', $request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE));
    }

    public function testOrdinaryStartReplayDoesNotImplicitlyRearmExistingError(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_ERROR,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
            AiSiteProvisioningRequest::schema_fields_ERROR_CODE => 'TEST_DOMAIN_HOSTS_FAILED',
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::never())->method('prepare');
        $repository->expects(self::once())->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')->willReturn($request);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');
        $gateway->expects(self::never())->method('rearm');
        $gateway->expects(self::never())->method('enqueue');
        $gateway->expects(self::once())->method('get')->with(52)->willReturn([
            'queue_id' => 52,
            'status' => 'error',
            'biz_key' => 'websites:ai_site_provisioning:' . $request->getRequestId(),
        ]);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'demo-site.weline.test',
        ]);

        self::assertSame(AiSiteProvisioningRequest::STATUS_ERROR, $result['status']);
        self::assertSame('error', $result['queue']['status']);
        self::assertSame(
            'TEST_DOMAIN_HOSTS_FAILED',
            $request->getData(AiSiteProvisioningRequest::schema_fields_ERROR_CODE)
        );
    }

    public function testStatusCanOnlyBeReadByTheOwningAdminUser(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID => 27,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::never())->method('inspect');
        $repository->expects(self::exactly(2))
            ->method('findByRequestId')
            ->with($request->getRequestId())
            ->willReturn($request);
        $gateway->expects(self::once())
            ->method('get')
            ->with(52)
            ->willReturn([
                'queue_id' => 52,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:' . $request->getRequestId(),
            ]);

        $service = new AiSiteProvisioningService($repository, $gateway, $readiness);

        self::assertNull($service->getStatus([
            'request_id' => $request->getRequestId(),
            'admin_user_id' => 28,
        ]));
        self::assertSame($request->getRequestId(), $service->getStatus([
            'request_id' => $request->getRequestId(),
            'admin_user_id' => 27,
        ])['request_id']);
    }

    public function testStatusProjectsTerminalQueueFailureWhenRequestWasLeftRunning(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID => 27,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_RUNNING,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
            AiSiteProvisioningRequest::schema_fields_ERROR_CODE => '',
            AiSiteProvisioningRequest::schema_fields_MESSAGE => 'stale running message',
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $repository->expects(self::once())
            ->method('findByRequestId')
            ->with($request->getRequestId())
            ->willReturn($request);
        $repository->expects(self::never())->method('save');
        $gateway->expects(self::once())
            ->method('get')
            ->with(52)
            ->willReturn([
                'queue_id' => 52,
                'status' => 'error',
                'biz_key' => 'websites:ai_site_provisioning:' . $request->getRequestId(),
            ]);

        $result = (new AiSiteProvisioningService($repository, $gateway, $readiness))->getStatus([
            'request_id' => $request->getRequestId(),
            'admin_user_id' => 27,
        ]);

        self::assertIsArray($result);
        self::assertFalse($result['success']);
        self::assertSame(AiSiteProvisioningRequest::STATUS_ERROR, $result['status']);
        self::assertSame('PROVISIONING_QUEUE_FAILED', $result['code']);
        self::assertSame('error', $result['queue']['status']);
        self::assertSame(
            AiSiteProvisioningRequest::STATUS_RUNNING,
            $request->getData(AiSiteProvisioningRequest::schema_fields_STATUS),
            'Status reads must not rewrite provisioning truth.'
        );
    }

    public function testTestModeRejectsNestedWelineTestDomainBeforePersistenceOrQueueCreation(): void
    {
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::never())->method('inspect');
        $repository->expects(self::never())->method('findByCommand');
        $repository->expects(self::never())->method('create');
        $gateway->expects(self::never())->method('enqueue');

        try {
            (new AiSiteProvisioningService($repository, $gateway, $readiness))->requestBinding([
                'admin_user_id' => 27,
                'client_request_id' => 'cmd-invalid-domain',
                'source_public_id' => 'pagebuilder-session-1',
                'domain_mode' => 'test',
                'target_domain' => 'foo.bar.weline.test',
            ]);
            self::fail('Nested *.weline.test domains must not be accepted.');
        } catch (AiSiteProvisioningException $exception) {
            self::assertSame('TEST_DOMAIN_REQUIRED', $exception->getErrorCode());
        }
    }

    public function testTestModeAdmissionCreatesOnePendingQueueWithoutWaitingForReadinessAndReplayIsIdempotent(): void
    {
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $readiness->expects(self::once())
            ->method('prepare')
            ->with('not-ready.weline.test', true)
            ->willThrowException(new \RuntimeException('desktop authorization unavailable'));

        $createdRequest = null;
        $repository->expects(self::exactly(2))
            ->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-not-ready')
            ->willReturnCallback(static function () use (&$createdRequest): ?AiSiteProvisioningRequest {
                return $createdRequest;
            });
        $repository->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$createdRequest): AiSiteProvisioningRequest {
                $createdRequest = $this->request($data);

                return $createdRequest;
            });
        $repository->expects(self::once())->method('save');
        $gateway->expects(self::once())
            ->method('enqueue')
            ->with(
                self::callback(static fn (string $requestId): bool => \strlen($requestId) === 32),
                self::callback(static fn (string $token): bool => \strlen($token) === 32)
            )
            ->willReturn([
                'queue_id' => 61,
                'status' => 'pending',
                'biz_key' => 'websites:ai_site_provisioning:test',
            ]);
        $gateway->expects(self::exactly(2))
            ->method('get')
            ->with(61)
            ->willReturn(['queue_id' => 61, 'status' => 'pending']);

        $command = [
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-not-ready',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'not-ready.weline.test',
        ];
        $service = new AiSiteProvisioningService($repository, $gateway, $readiness);
        $first = $service->requestBinding($command);
        $replay = $service->requestBinding($command);

        self::assertSame(AiSiteProvisioningRequest::STATUS_PENDING, $first['status']);
        self::assertSame($first['request_id'], $replay['request_id']);
        self::assertSame(['queue_id' => 61, 'status' => 'pending'], $first['queue']);
        self::assertSame($first['queue'], $replay['queue']);
    }

    public function testCompletedOwnedBindingRegistersTheExactMaterializedHomePage(): void
    {
        $request = $this->request([
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_DONE,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 1,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 52,
        ]);
        $repository = $this->createMock(AiSiteProvisioningRequestRepository::class);
        $gateway = $this->createMock(AiSiteProvisioningQueueGateway::class);
        $readiness = $this->createMock(AiSiteLocalDomainReadinessService::class);
        $startPage = $this->createMock(AiSiteStartPageService::class);
        $repository->expects(self::once())
            ->method('findByCommand')
            ->with('GuoLaiRen_PageBuilder', 'cmd-1')
            ->willReturn($request);
        $gateway->expects(self::once())
            ->method('get')
            ->with(52)
            ->willReturn(['queue_id' => 52, 'status' => 'done']);
        $startPage->expects(self::once())
            ->method('configure')
            ->with(0, 'demo-site.weline.test', 44)
            ->willReturn([
                'website_id' => 0,
                'target_domain' => 'demo-site.weline.test',
                'page_id' => 44,
                'start_page_path' => 'pagebuilder/frontend/page/view?page_id=44',
                'cache_broadcast' => ['success' => true],
            ]);

        $result = (new AiSiteProvisioningService(
            $repository,
            $gateway,
            $readiness,
            $startPage
        ))->configureStartPage([
            'admin_user_id' => 27,
            'client_request_id' => 'cmd-1',
            'source_public_id' => 'pagebuilder-session-1',
            'domain_mode' => 'test',
            'target_domain' => 'demo-site.weline.test',
            'page_id' => 44,
        ]);

        self::assertTrue($result['success']);
        self::assertSame(44, $result['start_page']['page_id']);
    }

    /** @param array<string, mixed> $data */
    private function request(array $data): AiSiteProvisioningRequest
    {
        $defaults = [
            AiSiteProvisioningRequest::schema_fields_ID => 7,
            AiSiteProvisioningRequest::schema_fields_REQUEST_ID => '0123456789abcdef0123456789abcdef',
            AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE => 'GuoLaiRen_PageBuilder',
            AiSiteProvisioningRequest::schema_fields_ADMIN_USER_ID => 27,
            AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID => 'pagebuilder-session-1',
            AiSiteProvisioningRequest::schema_fields_CLIENT_REQUEST_ID => 'cmd-1',
            AiSiteProvisioningRequest::schema_fields_DOMAIN_MODE => AiSiteProvisioningRequest::DOMAIN_MODE_TEST,
            AiSiteProvisioningRequest::schema_fields_TARGET_DOMAIN => 'demo-site.weline.test',
            AiSiteProvisioningRequest::schema_fields_REGISTRAR_ACCOUNT_ID => null,
            AiSiteProvisioningRequest::schema_fields_YEARS => 1,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_CONFIRMED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ATTEMPTED => 0,
            AiSiteProvisioningRequest::schema_fields_PURCHASE_ORDER_ID => 0,
            AiSiteProvisioningRequest::schema_fields_REQUESTED_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_BOUND => 0,
            AiSiteProvisioningRequest::schema_fields_WEBSITE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_STATUS => AiSiteProvisioningRequest::STATUS_PENDING,
            AiSiteProvisioningRequest::schema_fields_QUEUE_ID => 0,
            AiSiteProvisioningRequest::schema_fields_EXECUTION_TOKEN => 'abcdef0123456789abcdef0123456789',
            AiSiteProvisioningRequest::schema_fields_ERROR_CODE => '',
            AiSiteProvisioningRequest::schema_fields_MESSAGE => '',
        ];
        $request = new AiSiteProvisioningRequest();
        $request->setData(\array_replace($defaults, $data));

        return $request;
    }
}
