<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadPageTitleResolver;

class HeadPageTitleResolverTest extends TestCase
{
    public function testAutoUsesEntityWhenProductPresent(): void
    {
        $resolver = new HeadPageTitleResolver();
        $product = ['name' => '测试商品'];

        $title = $resolver->resolve(
            null,
            ['seo_title_source' => 'auto', 'name' => '商品详情页布局'],
            [],
            $product,
            null,
            null,
            [],
            static fn () => '商品详情页布局',
            static fn () => '',
            static fn () => '',
            static fn () => null,
            static function ($source, array $keys) {
                if (!is_array($source)) {
                    return null;
                }
                foreach ($keys as $key) {
                    if (array_key_exists($key, $source) && trim((string) $source[$key]) !== '') {
                        return $source[$key];
                    }
                }
                return null;
            },
            static fn (array $values) => array_values(array_filter($values, static fn ($v) => $v !== null && trim((string) $v) !== ''))[0] ?? '',
            static fn (mixed $value) => trim((string) $value),
            static fn () => '',
        );

        self::assertSame('测试商品', $title);
    }

    public function testAutoUsesLayoutNameWhenNoEntity(): void
    {
        $resolver = new HeadPageTitleResolver();

        $title = $resolver->resolve(
            null,
            ['seo_title_source' => 'auto', 'layout_name' => '个人中心仪表盘', 'title' => '个人中心'],
            [],
            null,
            null,
            null,
            [],
            static fn () => '个人中心仪表盘',
            static fn () => '',
            static fn () => '',
            static fn () => null,
            static fn () => null,
            static fn (array $values) => array_values(array_filter($values, static fn ($v) => $v !== null && trim((string) $v) !== ''))[0] ?? '',
            static fn (mixed $value) => trim((string) $value),
            static fn () => '',
        );

        self::assertSame('个人中心仪表盘', $title);
    }
}
