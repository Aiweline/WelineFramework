<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 验收（T2）：解析器在「全 null 作用域」下必须冻结全局作用域（确定性、可缓存）；
 * 仅有 Website 的部分作用域仍保留请求栅栏（不可缓存），保持既有语义。
 */
final class StorefrontGlobalScopeFallbackResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testFreezeCurrentUsesDeterministicGlobalScopeWhenAllNull(): void
    {
        $resolver = new StorefrontCacheKeyContextResolver($this->generation(), new NamespacePath());

        $this->enterEmptyScopeRequest();
        $first = $resolver->freezeCurrent();
        self::assertTrue($first->cacheable, '全 null 作用域必须可缓存');
        self::assertTrue($first->isGlobalScopeFallback());
        self::assertTrue($first->hasCompleteFrozenScope());
        $again = $resolver->freezeCurrent();
        self::assertSame($first->cacheKeyFingerprint, $again->cacheKeyFingerprint, '同请求内必须复用同一冻结上下文');

        RequestContext::cleanup();
        Context::leave();
        $this->enterEmptyScopeRequest();
        $crossRequest = (new StorefrontCacheKeyContextResolver($this->generation(), new NamespacePath()))->freezeCurrent();
        self::assertSame(
            $first->cacheKeyFingerprint,
            $crossRequest->cacheKeyFingerprint,
            '跨请求全局键必须稳定',
        );
    }

    public function testWebsiteOnlyPartialScopeStillUsesRequestFence(): void
    {
        $resolver = new StorefrontCacheKeyContextResolver($this->generation(), new NamespacePath());
        $this->enterRequest();
        RequestContext::installScopeIdentity(ScopeIdentity::website(7, 'shop_a'));

        $context = $resolver->freezeCurrent();
        self::assertFalse($context->cacheable, '部分作用域仍不可缓存，避免串站');
        self::assertSame('storefront_scope_incomplete', $context->failureCode);
        self::assertFalse($context->hasCompleteFrozenScope());
    }

    private function generation(): NamespaceGenerationInterface
    {
        return new class implements NamespaceGenerationInterface {
            /** @var array<string,int> */
            private array $generations = [];

            public function fingerprint(array $namespaces): string
            {
                $vector = [];
                foreach ($namespaces as $namespace) {
                    $vector[$namespace] = $this->generations[$namespace] ?? 0;
                }
                ksort($vector, SORT_STRING);

                return hash('sha256', json_encode($vector, JSON_UNESCAPED_SLASHES) ?: '');
            }

            public function bumpMany(array $namespaces): array
            {
                $changes = [];
                foreach (array_values(array_unique($namespaces)) as $namespace) {
                    $changes[$namespace] = $this->generations[$namespace] = ($this->generations[$namespace] ?? 0) + 1;
                }

                return ['authority_clock' => array_sum($this->generations), 'changes' => $changes];
            }

            public function bump(string $namespace): array
            {
                return $this->bumpMany([$namespace]);
            }
        };
    }

    private function enterRequest(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Runtime::setMode(Runtime::WLS);
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('global-scope-resolver-' . bin2hex(random_bytes(4)));
    }

    private function enterEmptyScopeRequest(): void
    {
        $this->enterRequest();
        // 不安装 ScopeIdentity / route.website_id / route.website_code。
    }
}
