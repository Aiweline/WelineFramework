<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\AiSiteDomainPurchaseAccountService;
use Weline\Websites\Service\DomainRegistrarResolverService;

final class AiSiteDomainPurchaseAccountServiceTest extends TestCase
{
    public function testSelectableAccountsExposeOnlyCredentialFreePurchaseProjection(): void
    {
        [$service] = $this->serviceWithAdapter([[
            DomainRegistrarAccount::schema_fields_ID => 33,
            DomainRegistrarAccount::schema_fields_REGISTRAR_ID => 7,
            DomainRegistrarAccount::schema_fields_ACCOUNT_NAME => '正式域名账户',
            DomainRegistrarAccount::schema_fields_API_KEY => 'encoded-secret-key',
            DomainRegistrarAccount::schema_fields_API_SECRET => 'encoded-secret-value',
            DomainRegistrarAccount::schema_fields_EXTRA_CONFIG => '{"token":"must-not-leak"}',
            DomainRegistrarAccount::schema_fields_REGION => 'cn-hangzhou',
            DomainRegistrarAccount::schema_fields_STATUS => DomainRegistrarAccount::STATUS_ACTIVE,
        ]]);

        $accounts = $service->listSelectable();

        self::assertCount(1, $accounts);
        self::assertSame([
            'account_id',
            'account_name',
            'registrar_code',
            'registrar_name',
            'region',
            'status',
            'purchase_capable',
        ], \array_keys($accounts[0]));
        self::assertSame(33, $accounts[0]['account_id']);
        self::assertSame('正式域名账户', $accounts[0]['account_name']);
        self::assertSame('gname', $accounts[0]['registrar_code']);
        self::assertTrue($accounts[0]['purchase_capable']);

        $encoded = (string)\json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('encoded-secret', $encoded);
        self::assertStringNotContainsString('must-not-leak', $encoded);
        self::assertStringNotContainsString('credentials', $encoded);
    }

    public function testAvailabilityResultIsNormalizedWithoutReturningAccountCredentials(): void
    {
        [$service, $adapter] = $this->serviceWithAdapter([]);
        $adapter->expects(self::once())
            ->method('checkAvailability')
            ->with('brand-example.com', [
                'api_key' => 'private-key',
                'api_secret' => 'private-secret',
            ])
            ->willReturn([
                'domain' => 'BRAND-EXAMPLE.COM',
                'available' => true,
                'price' => '12.50',
                'currency' => 'USD',
                'premium' => false,
                'message' => 'available',
                'api_secret' => 'provider-must-not-leak',
            ]);

        $availability = $service->checkAvailability(33, 'brand-example.com');

        self::assertSame([
            'domain' => 'brand-example.com',
            'available' => true,
            'price' => 12.5,
            'currency' => 'USD',
            'premium' => false,
            'message' => 'available',
        ], $availability);
        self::assertArrayNotHasKey('api_secret', $availability);
    }

    /**
     * @param list<array<string,mixed>> $selectableRows
     * @return array{AiSiteDomainPurchaseAccountService,DomainRegistrarInterface&MockObject}
     */
    private function serviceWithAdapter(array $selectableRows): array
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
            ->addMethods(['clearQuery', 'where', 'order', 'select', 'fetchArray'])
            ->getMock();
        $accountModel->method('clearData')->willReturnSelf();
        $accountModel->method('clearQuery')->willReturnSelf();
        $accountModel->method('where')->willReturnSelf();
        $accountModel->method('order')->willReturnSelf();
        $accountModel->method('select')->willReturnSelf();
        $accountModel->method('fetchArray')->willReturn($selectableRows);
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

        $adapter = $this->createMock(DomainRegistrarInterface::class);
        $adapter->method('isDomainRegistrar')->willReturn(true);
        $resolver = $this->createMock(DomainRegistrarResolverService::class);
        $resolver->method('getAdapter')->with('gname')->willReturn($adapter);

        return [
            new AiSiteDomainPurchaseAccountService($accountModel, $registrarModel, $resolver),
            $adapter,
        ];
    }
}
