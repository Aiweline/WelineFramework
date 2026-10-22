<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolution;

/**
 * 验收（T2b）：当 storefront scope provider 已配置、但请求作用域全 null 时，
 * 协调器必须把「全局作用域回退上下文」当作可发布的完整冻结作用域，
 * 并且读/写复用同一个上下文（不再每次重新冻结成不同键）。
 */
final class StorefrontGlobalScopeFallbackCoordinatorTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testGlobalFallbackIsCompleteFrozenScopeWhenProviderConfigured(): void
    {
        $this->enterEmptyScopeRequest();
        $context = StorefrontCacheKeyContext::globalScopeFallback('zh_Hans_CN', 'CNY');
        StorefrontCacheKeyContext::install($context);

        $coordinator = new FullPageCacheCoordinator();
        $provider = new ReflectionProperty(FullPageCacheCoordinator::class, 'storefrontProviderResolution');
        $provider->setAccessible(true);
        $provider->setValue($coordinator, new RuntimeProviderResolution(RuntimeProviderResolution::AVAILABLE));

        $frozen = new ReflectionMethod(FullPageCacheCoordinator::class, 'hasCompleteFrozenStorefrontScope');
        $frozen->setAccessible(true);
        self::assertTrue(
            $frozen->invoke($coordinator),
            'provider 已配置时，全 null 作用域回退也必须被视为完整冻结作用域（可读可写）',
        );

        $current = new ReflectionMethod(FullPageCacheCoordinator::class, 'currentStorefrontCacheKeyContext');
        $current->setAccessible(true);
        $resolved = $current->invoke($coordinator);
        self::assertInstanceOf(StorefrontCacheKeyContext::class, $resolved);
        self::assertSame(
            $context->cacheKeyFingerprint,
            $resolved->cacheKeyFingerprint,
            '读/写必须复用同一个全局上下文，而不是每次重新冻结出不同键',
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
        RequestContext::setId('global-scope-coordinator-' . bin2hex(random_bytes(4)));
        // 不安装 ScopeIdentity / route.website_id / route.website_code。
    }
}
