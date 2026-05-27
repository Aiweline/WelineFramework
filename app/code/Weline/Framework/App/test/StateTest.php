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
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/USD/en_US/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame('en_US', State::getLang());
            self::assertSame('USD', State::getCurrency());
        } finally {
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
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/category/home/furniture/sofas',
                'WELINE_ORIGIN_REQUEST_URI' => '/EUR/en_US/catalog/category/home/furniture/sofas',
                'HTTP_HOST' => 'example.test',
            ]);

            self::assertSame('en_US', State::getLang());
            self::assertSame('EUR', State::getCurrency());
        } finally {
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }
}
