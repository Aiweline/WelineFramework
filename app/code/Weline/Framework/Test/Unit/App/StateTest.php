<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\test;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

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

    public function testCurrencyRejectsStaleRouteCurrencyAndUsesAllowedWebsiteDefault(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/products/',
                'HTTP_HOST' => 'example.test',
                'WELINE_WEBSITE_ID' => 9514,
                'WELINE_USER_CURRENCY' => 'CNY',
                'WELINE_WEBSITE_CURRENCY' => 'USD',
            ]);
            State::resetRequestPathLocalizationCache();
            self::seedAllowedCurrencyCodes(['USD']);

            self::assertSame('USD', State::getCurrency());
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
            [
                'currency' => '',
                'language' => '',
                'area_offset' => 0,
                'consumed' => 0,
                'remaining' => [],
                'canonical' => [],
            ],
            State::resolveLocalizationFromPathSegments([])
        );
        self::assertSame(
            [
                'currency' => '',
                'language' => '',
                'area_offset' => 0,
                'consumed' => 0,
                'remaining' => ['catalog'],
                'canonical' => ['catalog'],
            ],
            State::resolveLocalizationFromPathSegments(['catalog'])
        );
    }

    public function testResolveLocalizationSupportsSingleAndEitherDoublePrefixOrder(): void
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

            $cases = [
                [['USD', 'catalog'], 'USD', '', 0, 1, ['catalog'], ['USD', 'catalog']],
                [['en_US', 'catalog'], '', 'en_US', 0, 1, ['catalog'], ['en_US', 'catalog']],
                [['USD', 'en_US', 'catalog'], 'USD', 'en_US', 0, 2, ['catalog'], ['USD', 'en_US', 'catalog']],
                [['en_US', 'USD', 'catalog'], 'USD', 'en_US', 0, 2, ['catalog'], ['USD', 'en_US', 'catalog']],
                [['api', 'en_US', 'USD', 'catalog'], 'USD', 'en_US', 1, 2, ['catalog'], ['api', 'USD', 'en_US', 'catalog']],
            ];

            foreach ($cases as [$segments, $currency, $language, $areaOffset, $consumed, $remaining, $canonical]) {
                self::assertSame(
                    [
                        'currency' => $currency,
                        'language' => $language,
                        'area_offset' => $areaOffset,
                        'consumed' => $consumed,
                        'remaining' => $remaining,
                        'canonical' => $canonical,
                    ],
                    State::resolveLocalizationFromPathSegments($segments)
                );
            }
        } finally {
            State::resetRequestPathLocalizationCache();
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }

    public function testResolveLocalizationDoesNotConsumeDuplicateTypes(): void
    {
        $duplicateCurrency = State::resolveLocalizationFromPathSegments(['CNY', 'USD', 'catalog']);
        self::assertSame('CNY', $duplicateCurrency['currency']);
        self::assertSame('', $duplicateCurrency['language']);
        self::assertSame(1, $duplicateCurrency['consumed']);
        self::assertSame(['USD', 'catalog'], $duplicateCurrency['remaining']);

        $duplicateLanguage = State::resolveLocalizationFromPathSegments(['en_US', 'zh_Hans_CN', 'catalog']);
        self::assertSame('', $duplicateLanguage['currency']);
        self::assertSame('en_US', $duplicateLanguage['language']);
        self::assertSame(1, $duplicateLanguage['consumed']);
        self::assertSame(['zh_Hans_CN', 'catalog'], $duplicateLanguage['remaining']);
    }

    public function testResolveLocalizationPreservesExactRuntimeBackendKeyFirst(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        $resolved = State::resolveLocalizationFromPathSegments([
            $backendPrefix,
            'en_US',
            'USD',
            'admin',
            'login',
        ]);
        self::assertSame(1, $resolved['area_offset']);
        self::assertSame(['admin', 'login'], $resolved['remaining']);
        self::assertSame([$backendPrefix, 'USD', 'en_US', 'admin', 'login'], $resolved['canonical']);

        $wrongCase = strtolower($backendPrefix);
        if ($wrongCase !== $backendPrefix) {
            $notArea = State::resolveLocalizationFromPathSegments([$wrongCase, 'USD', 'en_US', 'admin', 'login']);
            self::assertSame(0, $notArea['area_offset']);
            self::assertSame(0, $notArea['consumed']);
            self::assertSame([$wrongCase, 'USD', 'en_US', 'admin', 'login'], $notArea['remaining']);
        }
    }

    /** @param list<string> $codes */
    private static function seedAllowedCurrencyCodes(array $codes): void
    {
        $map = [];
        foreach ($codes as $code) {
            $map[strtoupper($code)] = true;
        }

        $scope = (string)\w_env('website_id', '')
            . '|' . (string)\w_env('website.code', '')
            . '|' . (string)WelineEnv::server('WELINE_WEBSITE_ID', '');

        $mapProperty = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap');
        $mapProperty->setValue(null, $map);
        $scopeProperty = new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope');
        $scopeProperty->setValue(null, $scope);
    }
}
