<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ProductCardUrl;

final class ProductCardUrlTest extends TestCase
{
    public function testRelativeProductRoutesAreNormalizedBeforeTaglibAddsTheCurrentPrefix(): void
    {
        foreach ([
            '/product/hanfu-a' => 'product/hanfu-a',
            '/en_US/product/hanfu-a' => 'product/hanfu-a',
            '/en_US/en_US/product/hanfu-a' => 'product/hanfu-a',
        ] as $route => $expectedPath) {
            self::assertSame(
                ['url' => '', 'url_path' => $expectedPath],
                ProductCardUrl::splitForTaglib($route, '/en_US'),
            );
        }
    }

    public function testAbsoluteAndProtocolRelativeUrlsRemainDirectUrls(): void
    {
        foreach ([
            'https://shop.example/en_US/product/hanfu-a',
            'HTTP://shop.example/en_US/product/hanfu-a',
            '//cdn.example/product/hanfu-a',
        ] as $url) {
            self::assertSame(
                ['url' => $url, 'url_path' => ''],
                ProductCardUrl::splitForTaglib($url, '/en_US'),
            );
        }
    }

    public function testThemeCatalogWidgetsUseTheSharedUrlNormalizer(): void
    {
        $themeRoot = dirname(__DIR__, 3);
        foreach ([
            'view/theme/frontend/widgets/product/bestsellers/default.phtml',
            'view/theme/frontend/widgets/product/featured-products/default.phtml',
            'view/theme/frontend/widgets/product/new-arrivals/default.phtml',
        ] as $relativePath) {
            $path = $themeRoot . '/' . $relativePath;
            self::assertFileExists($path);
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringContainsString('ProductCardUrl::splitForTaglib($route)', $source);
            self::assertStringNotContainsString(
                '$product[\'url_path\'] = ltrim($route, \'/\');',
                $source,
            );
        }
    }
}
