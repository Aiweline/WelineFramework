<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Payment\Service\PaymentObjectScopeService;

final class PaymentObjectScopeServiceTest extends TestCase
{
    private PaymentObjectScopeService $service;

    protected function setUp(): void
    {
        $this->service = new PaymentObjectScopeService(
            static fn(string $code): int => match ($code) {
                'default' => 0,
                'shop' => 17,
                default => -1,
            },
        );
    }

    public function testDefaultScopeIsSystemWebsiteNotGlobal(): void
    {
        $scope = $this->service->fromPersistedScope('default.default.default');

        self::assertSame(ScopeIdentity::KIND_WEBSITE, $scope->scopeKind);
        self::assertSame(0, $scope->websiteId);
        self::assertSame('default', $scope->websiteCode);
    }

    public function testExplicitGlobalMustUseDedicatedValue(): void
    {
        $scope = $this->service->fromExplicitTarget(['target_scope' => 'global']);

        self::assertTrue($scope->isGlobal());
    }

    public function testStoreAndChannelScopesUsePersistedWebsiteId(): void
    {
        $store = $this->service->fromPersistedScope('shop.main.default');
        $channel = $this->service->fromPersistedScope('shop.main.web');

        self::assertSame(ScopeIdentity::KIND_STORE, $store->scopeKind);
        self::assertSame(17, $store->websiteId);
        self::assertSame(ScopeIdentity::KIND_CHANNEL, $channel->scopeKind);
        self::assertSame('web', $channel->channelCode);
    }

    public function testAdminTargetCannotFallBackToImplicitDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payment_admin_requires_explicit_target_scope');

        $this->service->fromExplicitTarget([]);
    }
}
