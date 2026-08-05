<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class StorefrontCacheKeyContextIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testObjectManagerResolvesDatabaseBackedFrozenVector(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('storefront-cache-integration-' . bin2hex(random_bytes(4)));
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');

        $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
        $context = $resolver->freezeCurrent();

        self::assertTrue($context->cacheable, $context->failureCode);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$context->namespaceFingerprint);
        self::assertSame($context->namespaceFingerprint, $context->cacheKeyFingerprint);
        self::assertSame('default', $context->keyDimensions()['website']);
        self::assertCount(8, $resolver->namespacePaths('default'));
    }
}
