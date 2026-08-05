<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeSource;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1C-01 子集：四层覆盖 / 删除回落 / Global≠website(0) / 短 scope 禁写。
 * lock/unlock 归属 TASK-P1C-002。
 */
final class SystemConfigScopeResolverTest extends TestCase
{
    private SystemConfigScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SystemConfigScopeResolver();
    }

    public function testGlobalAndDefaultWebsiteUseDistinctStorageScopes(): void
    {
        $global = ScopeIdentity::global();
        $website0 = ScopeIdentity::website(0, 'default');
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $this->resolver->toStorageScope($global));
        self::assertSame(
            'default.' . SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL . '.default',
            $this->resolver->toStorageScope($website0),
        );
        self::assertNotSame(
            $this->resolver->toStorageScope($global),
            $this->resolver->toStorageScope($website0),
        );
    }

    public function testChannelFallbackChainOrder(): void
    {
        $identity = ScopeIdentity::channel(2, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        self::assertSame([
            'shop.main.app',
            'shop.main.default',
            'shop.default.default',
            SystemConfig::SCOPE_GLOBAL,
        ], $this->resolver->chainFromIdentity($identity));
    }

    public function testLayerOverridePrefersNearestExplicit(): void
    {
        $identity = ScopeIdentity::store(0, 'default', 'main', ScopeIdentity::MODE_NORMAL);
        $websiteStorage = $this->resolver->toStorageScope(ScopeIdentity::website(0, 'default'));
        $storeStorage = $this->resolver->toStorageScope($identity);
        $records = [
            SystemConfigScopeResolver::recordKey(SystemConfig::SCOPE_GLOBAL) => [
                SystemConfigScopeResolver::KEY_VALUE => 'global-v',
            ],
            SystemConfigScopeResolver::recordKey($websiteStorage) => [
                SystemConfigScopeResolver::KEY_VALUE => 'website-v',
            ],
            SystemConfigScopeResolver::recordKey($storeStorage) => [
                SystemConfigScopeResolver::KEY_VALUE => 'store-v',
            ],
        ];
        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertTrue($result->found());
        self::assertSame('store-v', $result->value);
        self::assertSame(ConfigScopeSource::KIND_EXACT, $result->source->sourceKind);
        self::assertSame($storeStorage, $result->source->storageScope);
    }

    public function testDeleteStoreFallsBackToWebsite(): void
    {
        $identity = ScopeIdentity::store(0, 'default', 'main', ScopeIdentity::MODE_NORMAL);
        $websiteStorage = $this->resolver->toStorageScope(ScopeIdentity::website(0, 'default'));
        $records = [
            SystemConfigScopeResolver::recordKey(SystemConfig::SCOPE_GLOBAL) => [
                SystemConfigScopeResolver::KEY_VALUE => 'global-v',
            ],
            SystemConfigScopeResolver::recordKey($websiteStorage) => [
                SystemConfigScopeResolver::KEY_VALUE => 'website-v',
            ],
        ];
        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertSame('website-v', $result->value);
        self::assertSame(ConfigScopeSource::KIND_FALLBACK, $result->source->sourceKind);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $result->source->scopeKind);
    }

    public function testLocalePrefersExactThenDefaultLocale(): void
    {
        $identity = ScopeIdentity::website(2, 'shop');
        $storage = $this->resolver->toStorageScope($identity);
        $records = [
            SystemConfigScopeResolver::recordKey($storage, 'zh_Hans_CN') => [
                SystemConfigScopeResolver::KEY_VALUE => 'zh-v',
            ],
            SystemConfigScopeResolver::recordKey($storage, '') => [
                SystemConfigScopeResolver::KEY_VALUE => 'default-loc-v',
            ],
        ];
        $hit = $this->resolver->resolveForIdentity($records, $identity, 'zh_Hans_CN');
        self::assertSame('zh-v', $hit->value);
        $fallback = $this->resolver->resolveForIdentity($records, $identity, 'en_US');
        self::assertSame('default-loc-v', $fallback->value);
    }

    public function testShortScopeWriteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('system_config_short_scope_write_forbidden');
        $this->resolver->assertWritableRawScope('default');
    }

    public function testDefaultValueSourceWhenNoRows(): void
    {
        $identity = ScopeIdentity::global();
        $result = $this->resolver->resolveForIdentity([], $identity, '', 'fallback-default', true);
        self::assertFalse($result->found());
        self::assertSame('fallback-default', $result->value);
        self::assertSame(ConfigScopeSource::KIND_DEFAULT, $result->source->sourceKind);
    }
}
