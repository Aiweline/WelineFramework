<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Url;

/**
 * Process-L1 SEO decode keys must be stable across product page URIs.
 */
final class UrlProcessDecodeCacheKeyContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Url::resetParserRequestCaches();
        parent::tearDown();
    }

    public function testProcessDecodeKeyIgnoresCurrentRequestUri(): void
    {
        $method = new ReflectionMethod(Url::class, 'buildUrlProcessDecodeCacheKey');
        $method->setAccessible(true);

        $seoUrl = '/zh_Hans_CN/product/demo-sku';
        $serverBase = [
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'shop.test:9555',
            'WELINE_AREA' => 'frontend',
            'WELINE_WEBSITE_ID' => '0',
            'WELINE_WEBSITE_CODE' => 'default',
            'WELINE_USER_LANG' => 'zh_Hans_CN',
            'WELINE_USER_CURRENCY' => 'CNY',
        ];

        $_SERVER = \array_replace($_SERVER, $serverBase, [
            'REQUEST_URI' => '/zh_Hans_CN/product/aaa',
            'WELINE_ORIGIN_REQUEST_URI' => '/zh_Hans_CN/product/aaa',
            'WELINE_FULL_REQUEST_URI' => 'https://shop.test:9555/zh_Hans_CN/product/aaa',
            'WELINE_AREA_ROUTE' => 'product/aaa',
        ]);
        Url::resetParserRequestCaches();
        $keyA = (string)$method->invoke(null, $seoUrl, '0');

        $_SERVER = \array_replace($_SERVER, $serverBase, [
            'REQUEST_URI' => '/zh_Hans_CN/product/bbb',
            'WELINE_ORIGIN_REQUEST_URI' => '/zh_Hans_CN/product/bbb',
            'WELINE_FULL_REQUEST_URI' => 'https://shop.test:9555/zh_Hans_CN/product/bbb',
            'WELINE_AREA_ROUTE' => 'product/bbb',
        ]);
        Url::resetParserRequestCaches();
        $keyB = (string)$method->invoke(null, $seoUrl, '0');

        self::assertNotSame('', $keyA);
        self::assertSame($keyA, $keyB, 'process decode key must not shard by current PDP request_uri');
    }
}
