<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcAllowlistedQueryIdentity;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\QueryBinFpcPath;

final class FpcAllowlistedQueryIdentityTest extends TestCase
{
    public function testOrderIndependentAndNoiseDropped(): void
    {
        $allow = ['af_x' => true, 'price' => true];
        $a = FpcAllowlistedQueryIdentity::filterQueryString(
            'utm_source=x&af_x=%E6%99%8B%E5%88%B6&price=100-299',
            $allow,
            false,
        );
        $b = FpcAllowlistedQueryIdentity::filterQueryString(
            'price=100-299&af_x=晋制&fbclid=y',
            $allow,
            false,
        );
        self::assertSame($a, $b);
        self::assertSame(
            'af_x=' . \rawurlencode('晋制') . '&price=100-299',
            $a,
        );
        self::assertSame(
            FpcAllowlistedQueryIdentity::querySha1($a),
            FpcAllowlistedQueryIdentity::querySha1($b),
        );
    }

    public function testEmptyAllowlistedValuesDoNotForkIdentity(): void
    {
        $allow = [
            'blog_category_id' => true,
            'category_id' => true,
            'q' => true,
            'type' => true,
        ];
        $withEmpty = FpcAllowlistedQueryIdentity::filterQueryString(
            'q=hanfu&category_id=&type=blog',
            $allow,
            false,
        );
        $without = FpcAllowlistedQueryIdentity::filterQueryString(
            'type=blog&q=hanfu',
            $allow,
            false,
        );
        self::assertSame('q=hanfu&type=blog', $withEmpty);
        self::assertSame($without, $withEmpty);
        self::assertSame(
            FpcAllowlistedQueryIdentity::querySha1($withEmpty),
            FpcAllowlistedQueryIdentity::querySha1($without),
        );
        $fromParams = FpcAllowlistedQueryIdentity::filterGetParams(
            ['q' => 'hanfu', 'category_id' => '', 'type' => 'blog'],
            $allow,
            false,
        );
        self::assertSame(['q' => 'hanfu', 'type' => 'blog'], $fromParams);
    }

    public function testQueryBinFpcPathParse(): void
    {
        $sha = \str_repeat('a', 40);
        $parsed = QueryBinFpcPath::parseFpcLeaf('/api/framework/query-bin/~fpc/products/~q/' . $sha);
        self::assertNotNull($parsed);
        self::assertSame('products', $parsed['storefront_rel']);
        self::assertSame($sha, $parsed['sha']);
        self::assertTrue(QueryBinFpcPath::isFpcLeafPath('/api/framework/query-bin/~fpc/category/women/~q/' . $sha));
        self::assertFalse(QueryBinFpcPath::isPlainQueryBinPath('/api/framework/query-bin/~fpc/products/~q/' . $sha));
        self::assertTrue(QueryBinFpcPath::isPlainQueryBinPath('/api/framework/query-bin'));
        self::assertNull(QueryBinFpcPath::parseFpcLeaf('/api/framework/query-bin/~fpc/../etc/~q/' . $sha));
    }

    public function testBqPathSharesDirectoryWithHtmlVariant(): void
    {
        $uri = 'https://identity-test.example/products?price=1-2';
        $htmlPath = FpcHtmlDiskStore::htmlPath($uri);
        $bqPath = FpcHtmlDiskStore::bqPath($uri);
        self::assertIsString($htmlPath);
        self::assertIsString($bqPath);
        self::assertSame(\dirname((string)$htmlPath), \dirname((string)$bqPath));
        self::assertStringContainsString('/' . FpcHtmlDiskStore::QUERY_VARIANT_SEGMENT . '/', (string)$bqPath);
        self::assertStringEndsWith(FpcHtmlDiskStore::BQ_BASENAME, (string)$bqPath);
    }
}
