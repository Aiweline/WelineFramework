<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Observer\ControllerFetchFileBefore;

final class ControllerFetchFileBeforeSanitizeHeavyBagsContractTest extends TestCase
{
    public function testSanitizeDropsLeakedCommerceBagsKeepsStringSlots(): void
    {
        $method = new \ReflectionMethod(ControllerFetchFileBefore::class, 'sanitizeRuntimeLayoutParams');
        $method->setAccessible(true);
        $observer = (new \ReflectionClass(ControllerFetchFileBefore::class))
            ->newInstanceWithoutConstructor();

        $out = $method->invoke($observer, [
            'showHeader' => true,
            'title' => '商品详情',
            'filters' => '<div class="filters">ok</div>',
            'product' => ['id' => 1, 'offers' => \array_fill(0, 100, 'x')],
            'offers' => \array_fill(0, 50, ['sku' => 'a']),
            'variant_catalog' => ['a' => 1],
            'seo' => ['title' => 'x'],
            'cart' => ['items' => []],
            'customer' => ['id' => 9],
            'content' => '<big>leak</big>',
        ]);

        self::assertTrue((bool)($out['showHeader'] ?? false));
        self::assertSame('商品详情', $out['title'] ?? null);
        self::assertSame('<div class="filters">ok</div>', $out['filters'] ?? null);
        self::assertArrayNotHasKey('product', $out);
        self::assertArrayNotHasKey('offers', $out);
        self::assertArrayNotHasKey('variant_catalog', $out);
        self::assertArrayNotHasKey('seo', $out);
        self::assertArrayNotHasKey('cart', $out);
        self::assertArrayNotHasKey('customer', $out);
        self::assertArrayNotHasKey('content', $out);
    }
}
