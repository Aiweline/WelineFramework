<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Maintenance\Helper\UrlParser;

final class UrlParserTest extends TestCase
{
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $env = WelineEnv::getInstance();
        $this->envSnapshot = $env->capture();
        $env->reset();
        $env->initFromSnapshot([], [], [], [], [
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.test',
        ]);
    }

    protected function tearDown(): void
    {
        WelineEnv::getInstance()->restore($this->envSnapshot);

        parent::tearDown();
    }

    public function testParseKeepsStandardCurrencyLanguagePrefix(): void
    {
        $parsed = UrlParser::parse('/CNY/en_US/frontend/product/view?id=652');

        self::assertSame('', $parsed['server']['WELINE_WEBSITE_CODE']);
        self::assertSame('CNY', $parsed['currency']);
        self::assertSame('en_US', $parsed['language']);
        self::assertSame('/frontend/product/view', $parsed['uri']);
        self::assertTrue($parsed['all_match']);
    }

    public function testParseDoesNotTreatRouteSegmentAsWebsitePrefix(): void
    {
        $parsed = UrlParser::parse('/product/CNY/en_US/frontend/product/view?id=652');

        self::assertSame('', $parsed['server']['WELINE_WEBSITE_CODE']);
        self::assertSame('CNY', $parsed['currency']);
        self::assertSame('zh_Hans_CN', $parsed['language']);
        self::assertSame('/product/CNY/en_US/frontend/product/view', $parsed['uri']);
        self::assertFalse($parsed['all_match']);
    }
}
