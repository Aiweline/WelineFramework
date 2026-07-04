<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\test;

use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class StateTest extends TestCore
{
    public function testGetStateCode()
    {
        /**@var $ob State */
        $ob = ObjectManager::getInstance(State::class);
        self::assertIsObject($ob);
    }

    public function testLangAndCurrencyPreferUrlSegmentsOverDefaultContext(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/CNY/zh_Hans_CN/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame('zh_Hans_CN', State::getLang());
            self::assertSame('CNY', State::getCurrency());
        } finally {
            State::resetRequestPathLocalizationCache();
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }

    public function testIsAllowedLanguageCodeRejectsNonLocaleSegments(): void
    {
        self::assertFalse(State::isAllowedLanguageCode('api'));
        self::assertFalse(State::isAllowedLanguageCode('catalog'));
        self::assertFalse(State::isAllowedLanguageCode('CNY'));
    }

    public function testCurrencySkipsRestApiPathSegmentBeforeRealCurrencyCode(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/product/demo',
                'WELINE_ORIGIN_REQUEST_URI' => '/api/CNY/zh_Hans_CN/catalog/product/demo',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame('CNY', State::getCurrency());
        } finally {
            State::resetRequestPathLocalizationCache();
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }

    public function testLangAndCurrencyPreferOriginUriWhenRouterUriIsStripped(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/category/home/furniture/sofas',
                'WELINE_ORIGIN_REQUEST_URI' => '/CNY/zh_Hans_CN/catalog/category/home/furniture/sofas',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame('zh_Hans_CN', State::getLang());
            self::assertSame('CNY', State::getCurrency());
        } finally {
            State::resetRequestPathLocalizationCache();
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }

    public function testResolveLocalizationSkipsEmptyAndBusinessOnlyPaths(): void
    {
        self::assertSame(
            ['currency' => '', 'language' => ''],
            State::resolveLocalizationFromPathSegments([])
        );
        self::assertSame(
            ['currency' => '', 'language' => ''],
            State::resolveLocalizationFromPathSegments(['catalog'])
        );
    }

    public function testResolveLocalizationFollowsAreaCurrencyLanguageOrder(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame(
                ['currency' => 'CNY', 'language' => 'zh_Hans_CN'],
                State::resolveLocalizationFromPathSegments(['CNY', 'zh_Hans_CN'])
            );
            self::assertSame(
                ['currency' => 'CNY', 'language' => 'zh_Hans_CN'],
                State::resolveLocalizationFromPathSegments(['api', 'CNY', 'zh_Hans_CN'])
            );
        } finally {
            State::resetRequestPathLocalizationCache();
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }
}
