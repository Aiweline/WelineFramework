<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Search\Api\SearchProjectionQueueAdmissionInterface;
use Weline\Search\Observer\ProductSearchProjectionChangedObserver;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ProductSearchProjectionDefaultStoreTest extends TestCase
{
    public function testStoreIdZeroResolvesTheRealDefaultStoreScope(): void
    {
        $store = new StoreSummary(0, 0, 'default', '默认店铺', 'normal', true, true, 'active', null);
        $catalog = $this->createMock(StoreCatalogInterface::class);
        $catalog->expects(self::once())->method('byId')->with(0)->willReturn($store);
        $observer = new ProductSearchProjectionChangedObserver(
            $catalog,
            $this->createMock(SearchProjectionQueueAdmissionInterface::class),
        );
        $method = new \ReflectionMethod($observer, 'scope');

        $scope = $method->invoke($observer, $this->change(), [
            'scope_kind' => ScopeIdentity::KIND_STORE,
            'store_id' => 0,
        ]);

        self::assertInstanceOf(ScopeIdentity::class, $scope);
        self::assertSame(ScopeIdentity::KIND_STORE, $scope->scopeKind);
        self::assertSame('default', $scope->storeCode);
    }

    private function change(): ResourceChange
    {
        return ResourceChange::fromArray([
            'schema_version' => ResourceChange::SCHEMA_VERSION,
            'event_id' => '1234567890abcdef1234567890abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-09-02T03:16:00.123456Z',
            'resource' => [
                'type' => 'product_search_projection',
                'id' => '0:1',
                'action' => 'upsert',
                'revision' => 1,
            ],
            'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0],
            'impact' => ['namespaces' => [], 'previous_namespaces' => [], 'urls' => [], 'previous_urls' => []],
            'changed_fields' => ['store_id'],
            'before' => [],
            'after' => ['store_id' => 0],
            'origin' => [
                'area' => 'backend',
                'entry' => 'product.search_projection.store_product',
                'request_id' => 'test-request',
                'instance' => 'unit-test',
                'trigger_by' => ['type' => 'system', 'id' => 0],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'backend',
                'timezone' => 'Asia/Shanghai',
                'user' => ['type' => 'system', 'id' => 0],
            ],
        ]);
    }
}
