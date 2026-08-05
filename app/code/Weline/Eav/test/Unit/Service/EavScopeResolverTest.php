<?php

declare(strict_types=1);

namespace Weline\Eav\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Scope\EavScopeValue;
use Weline\Eav\Service\EavScopeResolver;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * TEST-P1B-03（解析语义）：explicit / cleared / locale / ScopeIdentity 链。
 */
final class EavScopeResolverTest extends TestCase
{
    private EavScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EavScopeResolver();
    }

    public function testExplicitReturnsNearestScopeValue(): void
    {
        $identity = ScopeIdentity::store(0, 'default', 'main', ScopeIdentity::MODE_NORMAL);
        $records = [
            EavScopeResolver::recordKey('default', '') => [
                EavScopeResolver::KEY_VALUE => 'website-val',
                EavScopeResolver::KEY_CLEARED => false,
            ],
            EavScopeResolver::recordKey('default.main', '') => [
                EavScopeResolver::KEY_VALUE => 'store-val',
                EavScopeResolver::KEY_CLEARED => false,
            ],
        ];

        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertTrue($result->isExplicit());
        self::assertSame('store-val', $result->value);
        self::assertSame('default.main', $result->resolvedScope);
    }

    public function testClearedStopsParentFallback(): void
    {
        $identity = ScopeIdentity::store(0, 'default', 'main', ScopeIdentity::MODE_NORMAL);
        $records = [
            EavScopeResolver::recordKey('default', '') => [
                EavScopeResolver::KEY_VALUE => 'website-val',
                EavScopeResolver::KEY_CLEARED => false,
            ],
            EavScopeResolver::recordKey('default.main', '') => [
                EavScopeResolver::KEY_VALUE => null,
                EavScopeResolver::KEY_CLEARED => true,
            ],
        ];

        $result = $this->resolver->resolveForIdentity($records, $identity);
        self::assertTrue($result->isCleared());
        self::assertNull($result->value);
        self::assertSame('default.main', $result->resolvedScope);
    }

    public function testLocalePreferredThenDefaultLocale(): void
    {
        $identity = ScopeIdentity::website(0, 'default');
        $records = [
            EavScopeResolver::recordKey('default', '') => [
                EavScopeResolver::KEY_VALUE => 'default-locale',
                EavScopeResolver::KEY_CLEARED => false,
            ],
            EavScopeResolver::recordKey('default', 'zh_Hans_CN') => [
                EavScopeResolver::KEY_VALUE => 'zh-value',
                EavScopeResolver::KEY_CLEARED => false,
            ],
        ];

        $zh = $this->resolver->resolveForIdentity($records, $identity, 'zh_Hans_CN');
        self::assertSame('zh-value', $zh->value);

        $fallback = $this->resolver->resolveForIdentity($records, $identity, 'en_US');
        self::assertSame('default-locale', $fallback->value);
    }

    public function testClearedAtLocaleStopsFurtherLocaleAndParent(): void
    {
        $identity = ScopeIdentity::website(0, 'default');
        $records = [
            EavScopeResolver::recordKey('', '') => [
                EavScopeResolver::KEY_VALUE => 'global',
                EavScopeResolver::KEY_CLEARED => false,
            ],
            EavScopeResolver::recordKey('default', '') => [
                EavScopeResolver::KEY_VALUE => 'website-default-locale',
                EavScopeResolver::KEY_CLEARED => false,
            ],
            EavScopeResolver::recordKey('default', 'zh_Hans_CN') => [
                EavScopeResolver::KEY_CLEARED => true,
            ],
        ];

        $result = $this->resolver->resolveForIdentity($records, $identity, 'zh_Hans_CN');
        self::assertTrue($result->isCleared());
        self::assertSame(EavScopeValue::SOURCE_CLEARED, $result->source);
    }

    public function testChainFromIdentityIncludesZeroWebsite(): void
    {
        $identity = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_NORMAL);
        self::assertSame(
            ['default.main.web', 'default.main', 'default', ''],
            $this->resolver->chainFromIdentity($identity),
        );
    }
}
