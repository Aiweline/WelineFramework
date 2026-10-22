<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BuyerAftersaleReturnAddressResolver;

final class BuyerAftersaleReturnAddressResolverTest extends TestCase
{
    public function testProductAddressOutranksCustomAndOrder(): void
    {
        $result = (new BuyerAftersaleReturnAddressResolver())->resolve(
            [9 => '商品仓 A', 3 => '  ', 4 => '商品仓 A'],
            true,
            '自定义地址',
            [8],
        );

        self::assertSame(BuyerAftersaleReturnAddressResolver::SOURCE_PRODUCT, $result['source']);
        self::assertSame('商品仓 A', $result['address']);
        self::assertSame([], $result['warehouse_ids']);
    }

    public function testDistinctProductAddressesStayTogetherInProductIdOrder(): void
    {
        $result = (new BuyerAftersaleReturnAddressResolver())->resolve(
            [20 => '地址乙', 7 => '地址甲'],
            true,
            '自定义地址',
            [1],
        );

        self::assertSame("地址甲\n地址乙", $result['address']);
        self::assertSame(BuyerAftersaleReturnAddressResolver::SOURCE_PRODUCT, $result['source']);
    }

    public function testCustomAddressIsUsedOnlyWhenEnabled(): void
    {
        $resolver = new BuyerAftersaleReturnAddressResolver();

        $off = $resolver->resolve([], false, '自定义地址', [4, 4, 0]);
        self::assertSame(BuyerAftersaleReturnAddressResolver::SOURCE_ORDER, $off['source']);
        self::assertSame('', $off['address']);
        self::assertSame([4], $off['warehouse_ids']);

        $on = $resolver->resolve([], true, ' 自定义地址 ', []);
        self::assertSame(BuyerAftersaleReturnAddressResolver::SOURCE_CUSTOM, $on['source']);
        self::assertSame('自定义地址', $on['address']);
    }

    public function testEmptyCustomFallsThroughToOrderWarehouses(): void
    {
        $result = (new BuyerAftersaleReturnAddressResolver())->resolve([], true, ' ', [2, 9]);

        self::assertSame(BuyerAftersaleReturnAddressResolver::SOURCE_ORDER, $result['source']);
        self::assertSame([2, 9], $result['warehouse_ids']);
    }
}
