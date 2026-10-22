<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

/**
 * 验收（T1）：作用域「全是 null」时必须回退到全局作用域：
 * - cacheable=true（允许 FPC 读/写，不丢缓存）
 * - 键指纹确定（跨请求一致，不再是随机请求栅栏）
 * - 维度 scope_state=frozen / scope_kind=global / website|store|channel=default
 */
final class StorefrontGlobalScopeFallbackContextTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testFactoryIsCacheableGlobalAndDeterministic(): void
    {
        $a = StorefrontCacheKeyContext::globalScopeFallback('zh_Hans_CN', 'CNY');
        $b = StorefrontCacheKeyContext::globalScopeFallback('zh_Hans_CN', 'CNY');

        self::assertTrue($a->cacheable, '全局回退上下文必须可缓存');
        self::assertTrue($a->isGlobalScopeFallback(), '必须可识别为全局作用域回退');
        self::assertTrue($a->hasCompleteFrozenScope(), '全局回退必须被视为完整冻结作用域');
        self::assertSame('', $a->failureCode, '全局回退不是失败降级，不得携带 failure_code');
        self::assertSame($a->cacheKeyFingerprint, $b->cacheKeyFingerprint, '同一输入下键指纹必须确定');

        $dims = $a->keyDimensions();
        self::assertSame('frozen', $dims['scope_state']);
        self::assertSame('global', $dims['scope_kind']);
        self::assertSame('default', $dims['website']);
        self::assertSame('default', $dims['store']);
        self::assertSame('default', $dims['channel']);
    }

    public function testCurrentOrRequestFenceUsesGlobalFallbackWhenScopeIsAllNull(): void
    {
        $this->enterEmptyScopeRequest();
        $first = StorefrontCacheKeyContext::currentOrRequestFence('unit_global');
        self::assertTrue($first->cacheable, '全 null 作用域必须按全局缓存，而不是不可缓存的请求栅栏');
        self::assertTrue($first->isGlobalScopeFallback());
        self::assertSame('frozen', $first->keyDimensions()['scope_state']);

        // 跨请求确定性：清空请求上下文后重新进入空作用域，同一全局键必须再次得到相同指纹。
        RequestContext::cleanup();
        Context::leave();
        $this->enterEmptyScopeRequest();
        $second = StorefrontCacheKeyContext::currentOrRequestFence('unit_global_again');
        self::assertSame(
            $first->cacheKeyFingerprint,
            $second->cacheKeyFingerprint,
            '全局回退键必须跨请求稳定，否则 FPC 仍然永久 MISS',
        );
    }

    private function enterEmptyScopeRequest(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Runtime::setMode(Runtime::WLS);
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('global-scope-test-' . bin2hex(random_bytes(4)));
        // 故意不安装 ScopeIdentity，也不写 route.website_id / route.website_code。
    }
}
