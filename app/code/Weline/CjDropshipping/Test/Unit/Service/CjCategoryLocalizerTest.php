<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Service\CjCategoryLocalizer;

final class CjCategoryLocalizerTest extends TestCase
{
    public function testLocalePrefersZhDefaultsTrue(): void
    {
        self::assertTrue(CjCategoryLocalizer::localePrefersZh(''));
        self::assertTrue(CjCategoryLocalizer::localePrefersZh('zh_Hans_CN'));
        self::assertFalse(CjCategoryLocalizer::localePrefersZh('en_US'));
    }

    public function testLocalizeNodesUsesZhMap(): void
    {
        $nodes = [[
            'id' => 'x',
            'name' => 'Face Masks',
            'path' => "Women's Clothing / Accessories / Face Masks",
        ]];
        $zh = CjCategoryLocalizer::localizeNodes($nodes, 'zh_Hans_CN');
        self::assertSame('口罩', $zh[0]['name']);
        self::assertSame('女装 / 配饰 / 口罩', $zh[0]['path']);
        $en = CjCategoryLocalizer::localizeNodes($nodes, 'en_US');
        self::assertSame('Face Masks', $en[0]['name']);
    }

    public function testLocalizeApprovedStorefrontNavLabels(): void
    {
        $nodes = [
            ['id' => 'a', 'name' => 'Steering Covers', 'path' => 'Steering Covers'],
            ['id' => 'b', 'name' => 'Home, Garden & Furniture', 'path' => 'Home, Garden & Furniture / Home Storage'],
        ];
        $zh = CjCategoryLocalizer::localizeNodes($nodes, 'zh_Hans_CN');
        self::assertSame('方向盘套', $zh[0]['name']);
        self::assertSame('家居、园艺与家具', $zh[1]['name']);
        self::assertSame('家居、园艺与家具 / 家居收纳', $zh[1]['path']);
        self::assertSame('方向盘套', CjCategoryLocalizer::translateLabel('Steering Covers', 'zh_Hans_CN'));
        self::assertSame('Steering Covers', CjCategoryLocalizer::translateLabel('Steering Covers', 'en_US'));
    }
}
