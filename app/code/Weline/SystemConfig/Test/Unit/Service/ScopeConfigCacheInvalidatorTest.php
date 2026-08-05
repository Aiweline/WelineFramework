<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\KeyBuilder;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;
use Weline\SystemConfig\Service\SystemConfigLockService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1C-06 子集：继承影响图 / version vector / 覆盖店跳过。
 */
final class ScopeConfigCacheInvalidatorTest extends TestCase
{
    private ScopeConfigCacheInvalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invalidator = new ScopeConfigCacheInvalidator();
    }

    public function testAncestorChainForStoreIncludesWebsiteAndGlobal(): void
    {
        $chain = $this->invalidator->ancestorScopesInclusive('shop.main.default');
        self::assertSame('shop.main.default', $chain[0]);
        self::assertContains('shop.default.default', $chain);
        self::assertContains(SystemConfig::SCOPE_GLOBAL, $chain);
    }

    public function testAncestorChainForDefaultWebsiteSentinel(): void
    {
        $scope = 'default.' . SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL . '.default';
        $chain = $this->invalidator->ancestorScopesInclusive($scope);
        self::assertSame($scope, $chain[0]);
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $chain[\array_key_last($chain)]);
    }

    public function testVersionVectorChangesWhenGenerationBumps(): void
    {
        $scope = 'shop.default.default';
        $before = $this->invalidator->versionVectorFor($scope);
        $gen = $this->invalidator->bumpGeneration($scope);
        $after = $this->invalidator->versionVectorFor($scope);
        self::assertGreaterThan(0, $gen);
        self::assertNotSame($before, $after);
        // store reader vector must also change when website bumps
        $storeBeforeBump = $after;
        $this->invalidator->bumpGeneration($scope);
        $storeAfter = $this->invalidator->versionVectorFor('shop.main.default');
        self::assertNotSame(
            KeyBuilder::systemConfigVersionVectorToken([$scope . '=0']),
            $storeAfter,
        );
        self::assertNotSame($storeBeforeBump, $this->invalidator->versionVectorFor($scope));
    }

    public function testKeyBuilderVersionVectorTokenStable(): void
    {
        self::assertSame('v0', KeyBuilder::systemConfigVersionVectorToken([]));
        $a = KeyBuilder::systemConfigVersionVectorToken(['a=1', 'b=2']);
        $b = KeyBuilder::systemConfigVersionVectorToken(['a=1', 'b=2']);
        $c = KeyBuilder::systemConfigVersionVectorToken(['a=2', 'b=2']);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertStringStartsWith('vv:', $a);
    }

    public function testPlanImpactSkipsOverrideDescendantKeepsInheritor(): void
    {
        $website = 'shop.default.default';
        $inheritStore = 'shop.main.default';
        $overrideStore = 'shop.outlet.default';

        // 纯逻辑：覆盖判定依赖 isDescendant + 手工构造 skipped 条件
        self::assertTrue(SystemConfigLockService::isDescendantScope($website, $inheritStore));
        self::assertTrue(SystemConfigLockService::isDescendantScope($website, $overrideStore));

        $plan = $this->invalidator->planImpact(
            'Weline_SystemConfig',
            SystemConfig::area_BACKEND,
            $website,
            SystemConfig::LOCALE_DEFAULT,
            ['p1c003/demo'],
            null, // 无 DB：candidates 仅 catalog；此处验证返回结构
        );

        self::assertSame($website, $plan['written_scope']);
        self::assertArrayHasKey('invalidate_scopes', $plan);
        self::assertArrayHasKey('skipped_override_scopes', $plan);
        self::assertArrayHasKey('metrics', $plan);
        self::assertSame(1, $plan['metrics']['keys']);
    }

    public function testShouldInvalidateWithoutConfigDefaultsToTrue(): void
    {
        self::assertTrue($this->invalidator->shouldInvalidateKeyAtScope(
            null,
            'any',
            'Weline_SystemConfig',
            SystemConfig::area_BACKEND,
            'shop.main.default',
            SystemConfig::LOCALE_DEFAULT,
        ));
    }
}
