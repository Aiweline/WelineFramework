<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\ScopeSelectorCatalog;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

final class ScopeSelectorCatalogTest extends TestCase
{
    public function testBuildsCanonicalGlobalWebsiteStoreAndChannelTree(): void
    {
        $service = new ScopeSelectorCatalog(new SystemConfigScopeResolver(), new SelectorCatalogFixture());

        $result = $service->build('shop.__store__.default');
        $byValue = [];
        foreach ($result['options'] as $option) {
            $byValue[$option['value']] = $option['kind'];
        }

        self::assertSame('shop.__store__.default', $result['selected_scope']);
        self::assertSame('store', $result['selected_kind']);
        self::assertSame('global', $byValue['default.default.default']);
        self::assertSame('website', $byValue['shop.default.default']);
        self::assertSame('store', $byValue['shop.__store__.default']);
        self::assertSame('store', $byValue['shop.cn.default']);
        self::assertSame('channel', $byValue['shop.cn.web']);
        self::assertSame('website', $result['tree_options'][1]['kind']);
        self::assertSame('store', $result['tree_options'][1]['children'][0]['kind']);
    }

    public function testOpaqueScopeFallsBackToGlobalAndIsMarkedReadonly(): void
    {
        $service = new ScopeSelectorCatalog(new SystemConfigScopeResolver(), new SelectorCatalogFixture());

        $result = $service->build('dashboard_view:42');

        self::assertSame('default.default.default', $result['selected_scope']);
        self::assertTrue($result['legacy_readonly']);
        self::assertSame('dashboard_view:42', $result['legacy_scope']);
    }
}

final class SelectorCatalogFixture implements ScopeIdentityCatalogInterface
{
    public function websiteIdForCode(string $websiteCode): int
    {
        return $websiteCode === 'shop' ? 7 : 0;
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        return $candidate;
    }

    public function options(): array
    {
        return [[
            'code' => 'shop',
            'name' => '商城',
            'website_id' => 7,
            'stores' => [
                [
                    'id' => 11,
                    'code' => 'default',
                    'name' => '默认店',
                    'store_mode' => ScopeIdentity::MODE_NORMAL,
                    'channels' => [],
                ],
                [
                    'id' => 12,
                    'code' => 'cn',
                    'name' => '中国店',
                    'store_mode' => ScopeIdentity::MODE_NORMAL,
                    'channels' => [['id' => 21, 'code' => 'web', 'name' => '网页']],
                ],
            ],
        ]];
    }
}
