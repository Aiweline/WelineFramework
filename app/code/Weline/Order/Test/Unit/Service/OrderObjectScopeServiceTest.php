<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderObjectScopeService;

final class OrderObjectScopeServiceTest extends TestCase
{
    private OrderObjectScopeService $service;

    protected function setUp(): void
    {
        $this->service = new OrderObjectScopeService(
            static fn(int $websiteId, int $storeId): array => [
                'website_code' => $websiteId === 0 ? 'default' : 'shop',
                'store_code' => $storeId === 0 ? '' : 'main',
                'store_mode' => ScopeIdentity::MODE_NORMAL,
            ],
        );
    }

    public function testWebsiteZeroRemainsDefaultWebsiteNotGlobal(): void
    {
        $scope = $this->service->fromPersistedIds(0, 0);

        self::assertSame(ScopeIdentity::KIND_WEBSITE, $scope->scopeKind);
        self::assertSame(0, $scope->websiteId);
        self::assertSame('default', $scope->websiteCode);
    }

    public function testPersistedStoreBuildsStoreScope(): void
    {
        $scope = $this->service->fromPersistedIds(17, 9);

        self::assertSame(ScopeIdentity::KIND_STORE, $scope->scopeKind);
        self::assertSame(17, $scope->websiteId);
        self::assertSame('main', $scope->storeCode);
    }

    public function testCreateRequiresBothExplicitIdsEvenWhenTheyAreZero(): void
    {
        $scope = $this->service->fromExplicitCreate([
            Order::schema_fields_WEBSITE_ID => 0,
            Order::schema_fields_STORE_ID => 0,
        ]);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $scope->scopeKind);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order_create_requires_explicit_scope');
        $this->service->fromExplicitCreate([
            Order::schema_fields_WEBSITE_ID => 0,
        ]);
    }
}
