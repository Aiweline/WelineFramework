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
        $this->installChannelScope(storeId: 11, channelId: 21);
        $guard = new SearchParamGuard();
        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->willReturn(null);
        $registry->method('all')->willReturn([]);

        $this->expectException(SearchParamException::class);
        $guard->guardSearch(['q' => '耳机', 'category_id' => '12'], $registry);
    }

    public function testAllowsProductCategoryIdWhenTypeProduct(): void
    {
        $this->installChannelScope(storeId: 11, channelId: 21);
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

    public function testAllowsDefaultWebsiteStoreChannelZeroIds(): void
    {
        $this->installChannelScope(storeId: 0, channelId: 0);
        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->willReturn(null);
        $registry->method('all')->willReturn([]);

        $request = (new SearchParamGuard())->guardSearch(['q' => '汉服'], $registry);

        self::assertSame(0, $request->websiteId);
        self::assertSame(0, $request->storeId);
        self::assertSame(0, $request->channelId);
        self::assertSame('zh_Hans_CN', $request->locale);
        self::assertSame('CNY', $request->currency);
    }

    public function testRejectsWebsiteOnlyScope(): void
    {
        RequestContext::setWelineWebsiteId(0);
        RequestContext::installScopeIdentity(ScopeIdentity::website(0, 'default'));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');

        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->willReturn(null);
        $registry->method('all')->willReturn([]);

        try {
            (new SearchParamGuard())->guardSearch(['q' => '汉服'], $registry);
            self::fail('Expected SearchParamException');
        } catch (SearchParamException $exception) {
            self::assertSame(SearchParamGuard::ERROR_SCOPE, $exception->errorCode);
            self::assertSame('metadata_null_or_not_channel', $exception->context['reason'] ?? null);
        }
    }

    public function testRejectsEmptyLocaleOnChannelScope(): void
    {
        $this->installChannelScope(storeId: 0, channelId: 0);
        RequestContext::setWelineUserLang('');

        $registry = $this->createMock(SearchProviderRegistry::class);
        $registry->method('get')->willReturn(null);
        $registry->method('all')->willReturn([]);

        try {
            (new SearchParamGuard())->guardSearch(['q' => '汉服'], $registry);
            self::fail('Expected SearchParamException');
        } catch (SearchParamException $exception) {
            self::assertSame(SearchParamGuard::ERROR_SCOPE, $exception->errorCode);
            self::assertSame('empty_locale', $exception->context['reason'] ?? null);
        }
    }

    private function installChannelScope(int $storeId, int $channelId): void
    {
        RequestContext::setWelineWebsiteId(0);
        RequestContext::setWelineStoreId($storeId);
        RequestContext::setWelineChannelId($channelId);
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        RequestContext::setStorefrontRoutePath('/');
    }
}
