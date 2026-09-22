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
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

class StateTest extends TestCore
{
    public function testResolvedScopeLocalizationIsReusedAfterRouting(): void
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        try {
            State::resetRequestPathLocalizationCache();
            State::clearProcessLocalizationCaches();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/products',
                'HTTP_HOST' => 'example.test',
            ]);
            StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
                ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL),
                'en_US', 'USD', str_repeat('a', 64), str_repeat('b', 64), true,
            ));

            self::assertSame('en_US', State::getLang());
            self::assertSame('USD', State::getCurrency());
            self::assertSame('en_US', State::getLangLocal());

            State::setRequestLanguageOverride('ja_JP');
            self::assertSame('ja_JP', State::getLangLocal());
            State::setRequestLanguageOverride('');
            self::assertSame('en_US', State::getLangLocal());
        } finally {
            State::resetLangLocalCache();
            State::resetRequestPathLocalizationCache();
            State::clearProcessLocalizationCaches();
            Context::leave();
            if ($previous !== null) {
                Context::enter($previous);
            }
        }
    }

    public function testGetLangAndCurrencyReuseParsedRouteWithoutPathLocale(): void
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        try {
            State::resetRequestPathLocalizationCache();
            State::clearProcessLocalizationCaches();
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/product/view',
                'HTTP_HOST' => 'example.test',
            ]);
            $context = Context::current();
            $context->set('route.url_parsed', true);
            $context->set('route.language', 'en_US');
            $context->set('route.currency', 'USD');
            WelineEnv::set('url_parsed', true, 'StateTest parsed route');
            WelineEnv::set('user.lang', 'en_US', 'StateTest parsed route');
            WelineEnv::set('user.currency', 'USD', 'StateTest parsed route');

            self::assertSame('en_US', State::getLang());
            self::assertSame('USD', State::getCurrency());
        } finally {
            State::resetLangLocalCache();
            State::resetRequestPathLocalizationCache();
            State::clearProcessLocalizationCaches();
            Context::leave();
            if ($previous !== null) {
                Context::enter($previous);
            }
        }
    }

    public function testAllowedLanguageMapsStayIsolatedPerWebsiteScope(): void
    {
        State::clearProcessLocalizationCaches();
        $mapsProperty = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $mapsProperty->setValue(null, [
            'id:1' => ['en_us' => true],
            'id:2' => ['zh_hans_cn' => true, 'ja_jp' => true],
        ]);

        $maps = $mapsProperty->getValue(null);
        self::assertIsArray($maps);
        self::assertTrue(isset($maps['id:1']['en_us']));
        self::assertTrue(isset($maps['id:2']['ja_jp']));
        self::assertFalse(isset($maps['id:1']['ja_jp']));

        State::clearProcessLocalizationCaches();
        self::assertSame([], $mapsProperty->getValue(null));
    }

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

    public function testRequestLanguageOverrideBeatsCookieWithoutWritingPreference(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();
        $previousCookie = $_COOKIE['WELINE_USER_LANG'] ?? null;

        try {
            State::resetRequestPathLocalizationCache();
            $_COOKIE['WELINE_USER_LANG'] = 'ja_JP';
            WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/category/home',
                'HTTP_HOST' => 'example.test',
            ]);

            // Preference cookies must not drive language.
            self::assertSame('zh_Hans_CN', State::getLang());
            State::setRequestLanguageOverride('en_US');
            self::assertSame('en_US', State::getLang());
            self::assertSame('ja_JP', $_COOKIE['WELINE_USER_LANG']);
            State::setRequestLanguageOverride('');
            self::assertSame('zh_Hans_CN', State::getLang());
        } finally {
            State::setRequestLanguageOverride('');
            State::resetRequestPathLocalizationCache();
            if ($previousCookie === null) {
                unset($_COOKIE['WELINE_USER_LANG']);
            } else {
                $_COOKIE['WELINE_USER_LANG'] = $previousCookie;
            }
            if ($hadContext) {
                WelineEnv::getInstance()->restore($snapshot);
            } else {
                WelineEnv::getInstance()->reset();
            }
        }
    }

    public function testLangAndCurrencyFallBackToQueryWhenPathMissing(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], ['lang' => 'en_US', 'currency' => 'USD'], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/catalog/category/home?lang=en_US&currency=USD',
                'QUERY_STRING' => 'lang=en_US&currency=USD',
                'HTTP_HOST' => 'example.test',
            ]);
            self::seedAllowedLanguageCodes(['en_US', 'zh_Hans_CN', 'ja_JP']);
            self::seedAllowedCurrencyCodes(['USD', 'CNY']);

            self::assertSame('en_US', State::getLang());
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

    public function testPathLanguageAndCurrencyBeatQuery(): void
    {
        $hadContext = Context::getCurrent() !== null;
        $snapshot = WelineEnv::getInstance()->capture();

        try {
            State::resetRequestPathLocalizationCache();
            WelineEnv::getInstance()->initFromSnapshot([], ['lang' => 'ja_JP', 'currency' => 'EUR'], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/CNY/zh_Hans_CN/catalog/category/home?lang=ja_JP&currency=EUR',
                'QUERY_STRING' => 'lang=ja_JP&currency=EUR',
                'HTTP_HOST' => 'example.test',
            ]);
            self::seedAllowedLanguageCodes(['en_US', 'zh_Hans_CN', 'ja_JP']);
            self::seedAllowedCurrencyCodes(['CNY', 'EUR', 'USD']);

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

    public function testResolveLocalizationCanSkipAreaPrefixForRestModuleRouter(): void
    {
        $restFrontend = (string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api');
        self::assertSame(
            [
                'currency' => '',
                'language' => '',
                'area_offset' => 0,
                'consumed' => 0,
                'remaining' => [$restFrontend, 'rest', 'v1', 'backend', 'auth', 'login'],
                'canonical' => [$restFrontend, 'rest', 'v1', 'backend', 'auth', 'login'],
            ],
            State::resolveLocalizationFromPathSegments(
                [$restFrontend, 'rest', 'v1', 'backend', 'auth', 'login'],
                false
            )
        );
        $withArea = State::resolveLocalizationFromPathSegments(
            [$restFrontend, 'rest', 'v1', 'backend', 'auth', 'login'],
            true
        );
        self::assertSame(1, (int)$withArea['area_offset']);
        self::assertSame(['rest', 'v1', 'backend', 'auth', 'login'], $withArea['remaining']);
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
    private static function seedAllowedLanguageCodes(array $codes): void
    {
        $map = [];
        foreach ($codes as $code) {
            $map[strtolower($code)] = true;
        }

        $scope = self::currentWebsiteScopeKeyForTest();
        $mapsProperty = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $maps = $mapsProperty->getValue(null);
        if (!\is_array($maps)) {
            $maps = [];
        }
        $maps[$scope] = $map;
        $mapsProperty->setValue(null, $maps);
    }

    /** @param list<string> $codes */
    private static function seedAllowedCurrencyCodes(array $codes): void
    {
        $map = [];
        foreach ($codes as $code) {
            $map[strtoupper($code)] = true;
        }

        $scope = self::currentWebsiteScopeKeyForTest();
        $mapsProperty = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $maps = $mapsProperty->getValue(null);
        if (!\is_array($maps)) {
            $maps = [];
        }
        $maps[$scope] = $map;
        $mapsProperty->setValue(null, $maps);
    }

    private static function currentWebsiteScopeKeyForTest(): string
    {
        $method = new \ReflectionMethod(State::class, 'currentWebsiteScopeKey');

        return (string)$method->invoke(null);
    }
}
