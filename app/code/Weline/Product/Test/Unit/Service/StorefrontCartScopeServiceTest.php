<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Service\StorefrontCartScopeService;

final class StorefrontCartScopeServiceTest extends TestCase
{
    public function testStoreScopeIsSerializedForBrowserCartMutation(): void
    {
        $scope = ScopeIdentity::store(
            0,
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );

        self::assertSame([
            'scope_kind' => ScopeIdentity::KIND_STORE,
            'website_id' => 0,
            'website_code' => 'default',
            'store_code' => 'default',
            'channel_code' => null,
            'store_mode' => ScopeIdentity::MODE_NORMAL,
            'context_version' => ScopeIdentity::CONTEXT_VERSION,
        ], (new StorefrontCartScopeService())->forScope($scope));
    }

    public function testMissingOrGlobalScopeIsNotExposedToBrowser(): void
    {
        $service = new StorefrontCartScopeService();

        self::assertSame([], $service->forScope(null));
        self::assertSame([], $service->forScope(ScopeIdentity::global()));
    }
}
