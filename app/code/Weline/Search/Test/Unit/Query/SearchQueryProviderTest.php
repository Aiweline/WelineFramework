<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Search\Extends\Module\Weline_Framework\Query\SearchQueryProvider;
use Weline\Search\Service\HotWordsService;
use Weline\Search\Test\Unit\Support\NoopSearchAnalytics;
use Weline\Search\Service\SearchEngineResolver;
use Weline\Search\Service\SearchExpression;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchParamGuard;
use Weline\Search\Service\SearchProviderRegistry;
use Weline\Search\Model\SearchHotWord;

final class SearchQueryProviderTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        Context::enter(new Context());
    }

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        Context::leave();
        $_SERVER = $this->server;
    }

    public function testDescriptorPublishesSearchHotWordsAndTypes(): void
    {
        $provider = $this->provider();
        $operations = $provider->getDescriptor()['operations'];
        $names = \array_column($operations, 'name');

        self::assertSame(['search', 'hotWords', 'types'], $names);
    }

    public function testProviderRejectsUnknownParams(): void
    {
        $this->installScope();
        $result = $this->provider()->execute('search', [
            'q' => 'P3C',
            'website_id' => 99,
        ]);
        self::assertFalse($result['success']);
        self::assertSame(SearchParamGuard::ERROR_PARAMS, $result['error_code']);
    }

    public function testProviderSearchReturnsHitsFromHub(): void
    {
        $this->installScope();
        $result = $this->provider()->execute('search', ['q' => 'P3C', 'type' => 'product']);
        self::assertTrue($result['success']);
        self::assertSame(1, $result['hit_count']);
    }

    private function provider(): SearchQueryProvider
    {
        $product = new class implements SearchProviderInterface {
            public function code(): string { return 'product'; }
            public function label(): string { return 'product'; }
            public function sortOrder(): int { return 10; }
            public function expression(SearchRequest $request): SearchExpression {
                return SearchExpression::of($request);
            }
            public function allowedClientParams(): array { return []; }
            public function hitTemplate(): string { return ''; }
            public function execute(SearchRequest $request, SearchExpression $expression): SearchResult {
                return new SearchResult(
                    ok: true,
                    type: 'product',
                    hits: [new SearchHit('product', 'product', 'p3c-002', 'P3C 搜索商品', 'product/p3c-002')],
                    hitCount: 1,
                );
            }
            public function documentsForIndex(SearchRequest $request): array { return []; }
        };
        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('all')->willReturn(['product' => $product]);
        $registry->method('get')->willReturnCallback(static fn (string $code) => $code === 'product' ? $product : null);
        $registry->method('listTypes')->willReturn([
            ['code' => 'all', 'label' => '全部', 'children' => []],
            ['code' => 'product', 'label' => '商品', 'children' => []],
        ]);

        $analytics = new NoopSearchAnalytics();
        $hub = new SearchHubService(
            new SearchParamGuard(),
            $registry,
            SearchEngineResolver::forTesting('mysql'),
            $analytics,
        );
        return new SearchQueryProvider(
            $hub,
            new HotWordsService(
                new class extends SearchHotWord {
                    public function reset(): static { return $this; }
                    public function where(...$args): static { return $this; }
                    public function order(...$args): static { return $this; }
                    public function limit(...$args): static { return $this; }
                    public function select(): static { return $this; }
                    public function fetchArray(): array { return []; }
                },
                $this->createMock(StorefrontScopeHotCache::class),
            ),
            new SearchParamGuard(),
        );
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
