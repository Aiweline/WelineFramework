<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Search\Extends\Module\Weline_Framework\Query\SearchQueryProvider;
use Weline\Search\Service\ArrayProductDirectCatalogReader;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchQueryException;
use Weline\Search\Service\SearchQueryService;

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

    public function testDescriptorPublishesOnlyReadOnlySearch(): void
    {
        $provider = $this->provider();
        $operations = $provider->getDescriptor()['operations'];

        self::assertCount(1, $operations);
        self::assertSame('search', $operations[0]['name']);
        self::assertSame('read', $operations[0]['mode']);
        self::assertTrue($operations[0]['frontend']);
        self::assertSame(['q'], \array_column($operations[0]['params'], 'name'));
        self::assertSame('string', $operations[0]['params'][0]['type']);
        self::assertFalse($operations[0]['params'][0]['required']);
        self::assertSame(255, $operations[0]['params'][0]['max_length']);
    }

    public function testProviderUsesFrozenZeroWebsiteScopeAndRejectsClientScope(): void
    {
        $this->installScope();
        $provider = $this->provider();

        $result = $provider->execute('search', ['q' => 'P3C']);
        self::assertTrue($result['success']);
        self::assertSame(0, $result['website_id']);
        self::assertSame(11, $result['store_id']);
        self::assertSame(21, $result['channel_id']);
        self::assertSame('zh_Hans_CN', $result['locale']);
        self::assertSame('CNY', $result['currency']);
        self::assertSame(1, $result['hit_count']);

        $rejected = $provider->execute('search', [
            'q' => 'P3C',
            'website_id' => 99,
        ]);
        self::assertFalse($rejected['success']);
        self::assertSame(SearchQueryException::ERROR_SCOPE, $rejected['error_code']);
        self::assertSame(['website_id'], $rejected['context']['unknown_params']);
    }

    public function testProviderFailsClosedWithoutFrozenChannelScope(): void
    {
        $result = $this->provider()->execute('search', []);

        self::assertFalse($result['success']);
        self::assertSame(SearchQueryException::ERROR_SCOPE, $result['error_code']);
    }

    private function provider(): SearchQueryProvider
    {
        $direct = ArrayProductDirectCatalogReader::forTesting([
            [
                'website_id' => 0,
                'store_id' => 11,
                'channel_id' => 21,
                'entity_id' => 'p3c-002',
                'sku' => 'P3C-002',
                'title' => 'P3C 搜索商品',
                'document_version' => 1,
            ],
        ]);

        return new SearchQueryProvider(SearchQueryService::forTesting(
            SearchIndexBuilder::forTesting(),
            $direct,
        ));
    }

    private function installScope(): void
    {
        RequestContext::setWelineWebsiteId(0);
        RequestContext::setWelineWebsiteCode('default');
        RequestContext::setWelineStoreId(11);
        RequestContext::setWelineStoreCode('default');
        RequestContext::setWelineStoreMode(ScopeIdentity::MODE_NORMAL);
        RequestContext::setWelineChannelId(21);
        RequestContext::setWelineChannelCode('default');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        RequestContext::setWelineTimezone('Asia/Shanghai');
        RequestContext::setStorefrontRoutePath('/');
    }
}
