<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Framework\Query\ProductQueryProvider;
use Weline\Product\Model\Shard\Product;

final class ProductQueryProviderTest extends TestCase
{
    public function testDescriptorPublishesFrontendGetProductByIds(): void
    {
        $descriptor = (new ProductQueryProvider())->getDescriptor();

        self::assertSame('product', $descriptor['provider']);
        self::assertSame('Weline_Product', $descriptor['module']);
        self::assertCount(2, $descriptor['operations']);
        self::assertSame('getProductByIds', $descriptor['operations'][0]['name']);
        self::assertTrue($descriptor['operations'][0]['frontend']);
        self::assertSame('read', $descriptor['operations'][0]['mode']);
        self::assertSame('product_ids', $descriptor['operations'][0]['params'][0]['name']);
        self::assertSame('getPurchasePanel', $descriptor['operations'][1]['name']);
        self::assertTrue($descriptor['operations'][1]['frontend']);
        self::assertTrue($descriptor['operations'][1]['external']);
        self::assertSame('any', $descriptor['operations'][1]['auth']);
    }

    public function testGetProductByIdsReturnsEmptyForBlankIds(): void
    {
        $result = (new ProductQueryProvider())->execute('getProductByIds', [
            'product_ids' => [],
        ]);

        self::assertSame([], $result);
    }

    public function testUnknownOperationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductQueryProvider())->execute('notARealOp', []);
    }

    public function testPublishedStatusConstantIsPublished(): void
    {
        self::assertSame('published', Product::STATUS_PUBLISHED);
    }
}
