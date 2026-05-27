<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Service\FilterCacheService;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

class FilterCacheServiceTest extends TestCase
{
    public function testGenerateCacheKeyScopesByLanguageAndCurrency(): void
    {
        $snapshot = WelineEnv::getInstance()->capture();
        $hadContext = Context::getCurrent() !== null;

        try {
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/USD/en_US/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);
            $service = new FilterCacheService();
            $usdEnglishKey = $service->generateCacheKey(9, [
                'color' => ['red', 'blue'],
                'brand' => ['nike'],
            ]);
            $usdEnglishKeyWithSortedParams = $service->generateCacheKey(9, [
                'brand' => ['nike'],
                'color' => ['blue', 'red'],
            ]);

            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/CNY/zh_Hans_CN/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);
            $cnyChineseKey = $service->generateCacheKey(9, [
                'brand' => ['nike'],
                'color' => ['blue', 'red'],
            ]);

            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/CNY/en_US/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);
            $cnyEnglishKey = $service->generateCacheKey(9, [
                'brand' => ['nike'],
                'color' => ['blue', 'red'],
            ]);

            self::assertSame($usdEnglishKey, $usdEnglishKeyWithSortedParams);
            self::assertNotSame($usdEnglishKey, $cnyEnglishKey);
            self::assertNotSame($cnyChineseKey, $cnyEnglishKey);
            self::assertMatchesRegularExpression('/^weshop_filter_9_[a-f0-9]{40}_[a-f0-9]{32}$/', $usdEnglishKey);
        } finally {
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }
}
