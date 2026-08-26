<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\SearchParamException;
use Weline\Search\Service\SearchParamGuard;
use Weline\Search\Service\SearchProviderRegistry;

final class SearchParamGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context());
    }

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        Context::leave();
    }

    public function testRejectsUnknownParamsForAllType(): void
    {
        $this->installScope();
        $guard = new SearchParamGuard();
        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->willReturn(null);
        $registry->method('all')->willReturn([]);

        $this->expectException(SearchParamException::class);
        $guard->guardSearch(['q' => '耳机', 'category_id' => '12'], $registry);
    }

    public function testAllowsProductCategoryIdWhenTypeProduct(): void
    {
        $this->installScope();
        $provider = new class implements \Weline\Search\Api\SearchProviderInterface {
            public function code(): string { return 'product'; }
            public function label(): string { return 'product'; }
            public function sortOrder(): int { return 10; }
            public function expression(SearchRequest $request): \Weline\Search\Service\SearchExpression {
                return \Weline\Search\Service\SearchExpression::of($request);
            }
            public function allowedClientParams(): array {
                return ['category_id' => ['type' => 'int', 'min' => 1]];
            }
            public function hitTemplate(): string { return ''; }
            public function execute(SearchRequest $request, \Weline\Search\Service\SearchExpression $expression): SearchResult {
                return new SearchResult(true, 'product', [], 0);
            }
            public function documentsForIndex(SearchRequest $request): array { return []; }
        };
        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->with('product')->willReturn($provider);

        $request = (new SearchParamGuard())->guardSearch([
            'q' => '耳机',
            'type' => 'product',
            'category_id' => '12',
        ], $registry);

        self::assertSame(12, $request->extras['category_id']);
    }

    private function installScope(): void
    {
        RequestContext::setWelineWebsiteId(0);
        RequestContext::setWelineStoreId(11);
        RequestContext::setWelineChannelId(21);
        RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        RequestContext::setStorefrontRoutePath('/');
    }
}
