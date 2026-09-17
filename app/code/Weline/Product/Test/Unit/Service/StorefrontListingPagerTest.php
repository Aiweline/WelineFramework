<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontListingPager;

final class StorefrontListingPagerTest extends TestCase
{
    private StorefrontListingPager $pager;

    protected function setUp(): void
    {
        $this->pager = new StorefrontListingPager();
    }

    public function testSinglePageReturnsEmpty(): void
    {
        self::assertSame([], $this->pager->buildPageOptions('/products', 1, 1));
    }

    public function testSmallTotalShowsEveryPageWithoutEllipsis(): void
    {
        $options = $this->pager->buildPageOptions('/products', 1, 5, [], 5, false);
        $types = array_column($options, 'type');

        self::assertSame(['page', 'page', 'page', 'page', 'page'], $types);
        self::assertCount(5, $options);
        self::assertTrue((bool)$options[0]['selected']);
        self::assertSame('/products', $options[0]['url']);
        self::assertSame('/products?page=5', $options[4]['url']);
    }

    public function testLargeTotalUsesEllipsisWindowNearStart(): void
    {
        $options = $this->pager->buildPageOptions('/products', 1, 100, [], 5, false);
        $labels = array_map(
            static fn(array $item): string => (string)($item['label'] ?? $item['page'] ?? ''),
            $options
        );

        self::assertSame(['1', '2', '3', '4', '5', '…', '100'], $labels);
        self::assertSame('ellipsis', $options[5]['type']);
        self::assertTrue((bool)$options[0]['selected']);
    }

    public function testLargeTotalUsesEllipsisWindowNearMiddle(): void
    {
        $options = $this->pager->buildPageOptions('/products', 50, 100, [], 5, false);
        $labels = array_map(
            static fn(array $item): string => (string)($item['label'] ?? $item['page'] ?? ''),
            $options
        );

        self::assertSame(['1', '…', '48', '49', '50', '51', '52', '…', '100'], $labels);
        self::assertTrue((bool)$options[4]['selected']);
    }

    public function testLargeTotalUsesEllipsisWindowNearEnd(): void
    {
        $options = $this->pager->buildPageOptions('/products', 100, 100, [], 5, false);
        $labels = array_map(
            static fn(array $item): string => (string)($item['label'] ?? $item['page'] ?? ''),
            $options
        );

        self::assertSame(['1', '…', '96', '97', '98', '99', '100'], $labels);
        self::assertTrue((bool)$options[6]['selected']);
    }

    public function testPrevNextWrapPagesAndPreserveFilters(): void
    {
        $options = $this->pager->buildPageOptions(
            '/category/demo',
            3,
            10,
            ['price' => '0-99', 'sort' => 'price_asc'],
            5,
            true,
        );

        self::assertSame('prev', $options[0]['type']);
        self::assertFalse((bool)$options[0]['disabled']);
        self::assertStringContainsString('page=2', (string)$options[0]['url']);
        self::assertStringContainsString('price=0-99', (string)$options[0]['url']);

        $last = $options[array_key_last($options)];
        self::assertSame('next', $last['type']);
        self::assertStringContainsString('page=4', (string)$last['url']);
    }

    public function testPrevDisabledOnFirstPage(): void
    {
        $options = $this->pager->buildPageOptions('/products', 1, 20, [], 5, true);
        self::assertSame('prev', $options[0]['type']);
        self::assertTrue((bool)$options[0]['disabled']);
    }
}
