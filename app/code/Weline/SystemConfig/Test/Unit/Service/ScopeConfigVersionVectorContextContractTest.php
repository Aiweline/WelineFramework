<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;

final class ScopeConfigVersionVectorContextContractTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('system_config')->clear();
    }

    protected function tearDown(): void
    {
        Context::leave();
    }

    public function testBumpRefreshesMemoizedDescendantsButKeepsOtherWebsites(): void
    {
        $invalidator = new ScopeConfigCacheInvalidator();
        $scopes = ['shop.default.default', 'shop.main.default', 'shop.main.app', 'other.main.app'];
        $before = [];
        foreach ($scopes as $scope) {
            $before[$scope] = $invalidator->versionVectorFor($scope);
        }
        $invalidator->bumpGeneration('shop.default.default');
        foreach (array_slice($scopes, 0, 3) as $scope) {
            self::assertNotSame($before[$scope], $invalidator->versionVectorFor($scope), $scope);
        }
        self::assertSame($before[$scopes[3]], $invalidator->versionVectorFor($scopes[3]));
    }

    public function testGlobalBumpRefreshesDefaultWebsiteSentinelAndStore(): void
    {
        $invalidator = new ScopeConfigCacheInvalidator();
        $scope = 'default.__store__.__channel__';
        $before = $invalidator->versionVectorFor($scope);
        $invalidator->bumpGeneration(SystemConfig::SCOPE_GLOBAL);
        self::assertNotSame($before, $invalidator->versionVectorFor($scope));
    }
}
