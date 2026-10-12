<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\ResourceChange;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;

/**
 * Multi-website product purge must carry impact.website_ids + url_matrix_ready through factory validation.
 */
final class ResourceChangeImpactWebsiteIdsContractTest extends TestCase
{
    public function testFactoryPreservesWebsiteIdsAndUrlMatrixReady(): void
    {
        $change = (new ResourceChangeFactory(new ContextSnapshot()))->create(
            resourceType: 'product_search_projection',
            resourceId: '0:9',
            action: 'upsert',
            revision: 9,
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: ['contract' => 'product.search_projection_changed.v1'],
            changedFields: ['contract'],
            impact: [
                'namespaces' => ['website/default/catalog', 'website/daocharms/catalog'],
                'urls' => ['https://www.daocharms.com/product/x'],
                'previous_urls' => [],
                'website_ids' => [158, 0],
                'url_matrix_ready' => true,
            ],
            origin: ['entry' => 'unit'],
            siteId: 0,
        );
        $impact = $change->toArray()['impact'];
        self::assertSame([0, 158], $impact['website_ids']);
        self::assertTrue($impact['url_matrix_ready']);
        self::assertContains('https://www.daocharms.com/product/x', $impact['urls']);
    }
}
