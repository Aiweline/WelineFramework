<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App;
use Weline\Framework\App\Env as AppEnv;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RequestContext;

require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

final class AppRequestRuntimeContextTest extends TestCase
{
    private array $originalServer = [];
    private array $originalGet = [];
    private array $originalPost = [];
    private array $originalCookie = [];
    private array $originalFiles = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalCookie = $_COOKIE;
        $this->originalFiles = $_FILES;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        RequestContext::cleanup();
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        AppEnv::getInstance()->reload();
        Context::leave();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        AppEnv::getInstance()->reload();
        Context::leave();

        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_COOKIE = $this->originalCookie;
        $_FILES = $this->originalFiles;
    }

    public function testInitKeepsRequestUriFactsForCliRequestContext(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
            'input' => [
                'uri' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
                'origin_request_uri' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
                'scheme' => 'https',
                'host' => 'p11005ce4.test.weline.com',
            ],
        ]));

        $_SERVER = [
            'REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.test.weline.com',
            'SERVER_PORT' => '443',
        ];

        App::init();

        self::assertSame(
            '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            Context::current()?->get('input.server.WELINE_ORIGIN_REQUEST_URI')
        );
        self::assertSame(
            'https://p11005ce4.test.weline.com/pagebuilder/backend/ai-site-agent/index?legacy=1',
            Context::current()?->get('input.server.WELINE_FULL_REQUEST_URI')
        );
    }

    public function testConfiguredBenchmarkPathSkipsEagerSessionStart(): void
    {
        $app = $this->createAppForRequest('/__bench/framework?iteration=1');

        AppEnv::getInstance()->applyRuntimeConfig([
            'session' => [
                'eager_start_excluded_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        self::assertFalse($this->shouldEagerStartSession($app));
    }

    public function testNonExcludedPathKeepsDefaultEagerSessionStart(): void
    {
        $app = $this->createAppForRequest('/unit/path?id=1');

        AppEnv::getInstance()->applyRuntimeConfig([
            'session' => [
                'eager_start_excluded_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        self::assertTrue($this->shouldEagerStartSession($app));
    }

    public function testConfiguredBenchmarkPathSuppressesRouteStateCookies(): void
    {
        $app = $this->createAppForRequest('/__bench/framework?iteration=1', [
            'WELINE_USER_LANG' => 'zh_Hans_CN',
            'WELINE_USER_CURRENCY' => 'CNY',
            'WELINE_WEBSITE_ID' => '1',
            'WELINE_WEBSITE_CODE' => 'default',
            'WELINE_WEBSITE_URL' => 'http://127.0.0.1:21399',
        ]);

        AppEnv::getInstance()->applyRuntimeConfig([
            'cookie' => [
                'suppress_response_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        $method = new ReflectionMethod($app, 'syncCookieRouteStateFromServer');
        $method->setAccessible(true);
        $method->invoke($app);

        self::assertSame([], HeaderCollector::getInstance()->getCookies());
    }

    public function testAnonymousFrontendGetDefersRouteStateCookiesForFpc(): void
    {
        $app = $this->createAppForRequest('/en_US/products', [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'p05113ef3.test.weline.com',
            'WELINE_WEBSITE_ID' => '0',
            'WELINE_WEBSITE_CODE' => 'default',
            'WELINE_WEBSITE_URL' => 'https://p05113ef3.test.weline.com/',
        ]);
        WelineEnv::set('area', 'frontend', 'unit test');
        WelineEnv::set('is_backend', false, 'unit test');

        $method = new ReflectionMethod($app, 'syncCookieRouteStateFromServer');
        $method->setAccessible(true);
        $method->invoke($app);

        self::assertSame([], HeaderCollector::getInstance()->getCookies());
    }

    public function testLocalizedHomepageSynchronizesUnifiedCacheDimensionsFromEitherPrefixOrder(): void
    {
        $currencyMaps = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalCurrency = $currencyMaps->getValue(null);
        $originalLanguage = $languageMaps->getValue(null);

        try {
            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);

            foreach ([
                ['/USD/', '', 'zh_Hans_CN', 'USD'],
                ['/en_US/', '', 'en_US', 'CNY'],
                ['/USD/en_US/', '', 'en_US', 'USD'],
                ['/en_US/USD/', '', 'en_US', 'USD'],
                ['/site/en_US/USD/', 'https://example.test/site', 'en_US', 'USD'],
                ['/site/USD/en_US/catalog', 'https://example.test/site', 'en_US', 'USD'],
            ] as [$uri, $websiteUrl, $expectedLanguage, $expectedCurrency]) {
                RequestContext::cleanup();
                Context::leave();
                Context::enter(new Context([
                    'meta' => ['type' => 'request', 'mode' => 'wls'],
                    'input' => [
                        'uri' => '/',
                        'origin_request_uri' => $uri,
                        'server' => [
                            'REQUEST_URI' => '/',
                            'WELINE_ORIGIN_REQUEST_URI' => $uri,
                            'WELINE_WEBSITE_ID' => '0',
                            'WELINE_WEBSITE_CODE' => 'default',
                        ],
                    ],
                    'route' => [
                        'website_id' => 0,
                        'website_code' => 'default',
                        'language' => 'zh_Hans_CN',
                        'currency' => 'CNY',
                    ],
                ]));
                RequestContext::init();

                $scope = $this->currentWebsiteScopeKey();
                // URL currency is an authoritative route dimension even when
                // the current Website selector only exposes its default CNY.
                // It must not collapse /USD/... onto the CNY cache context.
                $this->seedAllowedCurrencyMap($scope, ['CNY' => true]);
                $this->seedAllowedLanguageMap($scope, ['zh_hans_cn' => true, 'en_us' => true]);

                $parse = [
                    'currency' => 'CNY',
                    'language' => 'zh_Hans_CN',
                    'server' => [
                        'WELINE_USER_CURRENCY' => 'CNY',
                        'WELINE_USER_LANG' => 'zh_Hans_CN',
                        'WELINE_WEBSITE_URL' => $websiteUrl,
                    ],
                ];
                $method->invokeArgs(new App(), [&$parse, $uri]);

                self::assertSame($expectedCurrency, RequestContext::getWelineUserCurrency(), $uri);
                self::assertSame($expectedLanguage, RequestContext::getWelineUserLang(), $uri);
                self::assertSame($expectedCurrency, $parse['currency'], $uri);
                self::assertSame($expectedLanguage, $parse['language'], $uri);
                self::assertSame($expectedCurrency, $parse['server']['WELINE_USER_CURRENCY'], $uri);
                self::assertSame($expectedLanguage, $parse['server']['WELINE_USER_LANG'], $uri);
            }
        } finally {
            $currencyMaps->setValue(null, $originalCurrency);
            $languageMaps->setValue(null, $originalLanguage);
        }
    }

    public function testCanonicalizeStorefrontPathStripsDefaultLocaleAndCurrency(): void
    {
        $scope = $this->currentWebsiteScopeKey();
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalLanguage = $languageMaps->getValue(null);
        try {
            $this->seedAllowedLanguageMap($scope, [
                'zh_hans_cn' => true,
                'en_us' => true,
                'fr_fr' => true,
            ]);

            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/zh_Hans_CN/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/CNY/zh_Hans_CN/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/USD/product/x',
                State::canonicalizeStorefrontLocalizationPath('/USD/zh_Hans_CN/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/en_US/product/x',
                State::canonicalizeStorefrontLocalizationPath('/CNY/en_US/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertNull(
                State::canonicalizeStorefrontLocalizationPath('/en_US/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertNull(
                State::canonicalizeStorefrontLocalizationPath('/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/',
                State::canonicalizeStorefrontLocalizationPath('/zh_Hans_CN/', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/',
                State::canonicalizeStorefrontLocalizationPath('/USD/USD', 'zh_Hans_CN', 'USD')
            );
            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/USD/USD/product/x', 'zh_Hans_CN', 'USD')
            );
            self::assertSame(
                '/',
                State::canonicalizeStorefrontLocalizationPath('/en_US/en_US', 'en_US', 'USD')
            );
            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/en_US/en_US/product/x', 'en_US', 'USD')
            );
        } finally {
            $languageMaps->setValue(null, $originalLanguage);
        }
    }

    public function testCanonicalizeStorefrontPathStripsUnenabledLanguageKeepsEnabledNonDefault(): void
    {
        $scope = $this->currentWebsiteScopeKey();
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalLanguage = $languageMaps->getValue(null);
        try {
            // Default site has fr/de-style allow-list but no ja_JP/ko_KR.
            $this->seedAllowedLanguageMap($scope, [
                'zh_hans_cn' => true,
                'en_us' => true,
                'fr_fr' => true,
                'de_de' => true,
            ]);

            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/ja_JP/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/USD/about',
                State::canonicalizeStorefrontLocalizationPath('/USD/ko_KR/about', 'zh_Hans_CN', 'CNY')
            );
            self::assertSame(
                '/',
                State::canonicalizeStorefrontLocalizationPath('/ja_JP/', 'zh_Hans_CN', 'CNY')
            );
            // Enabled non-default stays.
            self::assertNull(
                State::canonicalizeStorefrontLocalizationPath('/fr_FR/product/x', 'zh_Hans_CN', 'CNY')
            );
            self::assertNull(
                State::canonicalizeStorefrontLocalizationPath('/en_US/product/x', 'zh_Hans_CN', 'CNY')
            );
            // Default still strips.
            self::assertSame(
                '/product/x',
                State::canonicalizeStorefrontLocalizationPath('/zh_Hans_CN/product/x', 'zh_Hans_CN', 'CNY')
            );
            // Area-first backend path: canonicalize refuses (App also skips redirect on backend).
            $backendPrefix = (string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? '');
            if ($backendPrefix !== '') {
                self::assertNull(
                    State::canonicalizeStorefrontLocalizationPath(
                        '/' . $backendPrefix . '/ja_JP/dashboard',
                        'zh_Hans_CN',
                        'CNY'
                    )
                );
            }
        } finally {
            $languageMaps->setValue(null, $originalLanguage);
        }
    }

    public function testSynchronizeParsedLocalizationClearsPathLangWhenLanguageRejected(): void
    {
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $currencyMaps = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $originalLanguage = $languageMaps->getValue(null);
        $originalCurrency = $currencyMaps->getValue(null);

        try {
            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);

            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context([
                'meta' => ['type' => 'request', 'mode' => 'wls'],
                'input' => [
                    'uri' => '/ja_JP/about',
                    'origin_request_uri' => '/ja_JP/about',
                    'server' => [
                        'REQUEST_URI' => '/ja_JP/about',
                        'WELINE_ORIGIN_REQUEST_URI' => '/ja_JP/about',
                        'WELINE_WEBSITE_ID' => '0',
                        'WELINE_WEBSITE_CODE' => 'default',
                        'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                        'WELINE_URL_PATH_LANG' => 'ja_JP',
                    ],
                ],
                'route' => [
                    'website_id' => 0,
                    'website_code' => 'default',
                    'language' => 'zh_Hans_CN',
                    'currency' => 'CNY',
                ],
            ]));
            RequestContext::init();

            $scope = $this->currentWebsiteScopeKey();
            $this->seedAllowedLanguageMap($scope, ['zh_hans_cn' => true, 'en_us' => true]);
            $this->seedAllowedCurrencyMap($scope, ['CNY' => true]);

            WelineEnv::set('website.language', 'zh_Hans_CN', 'unit test');
            WelineEnv::setServer('WELINE_URL_PATH_LANG', 'ja_JP', 'unit test');

            $parse = [
                'currency' => 'CNY',
                'language' => 'ja_JP',
                'area' => 'frontend',
                'server' => [
                    'WELINE_USER_CURRENCY' => 'CNY',
                    'WELINE_USER_LANG' => 'ja_JP',
                    'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                    'WELINE_URL_PATH_LANG' => 'ja_JP',
                ],
            ];
            $method->invokeArgs(new App(), [&$parse, '/ja_JP/about']);

            self::assertSame('zh_Hans_CN', $parse['language']);
            self::assertArrayNotHasKey('WELINE_URL_PATH_LANG', $parse['server']);
            self::assertSame('', (string)WelineEnv::server('WELINE_URL_PATH_LANG', ''));
        } finally {
            try {
                WelineEnv::removeServer('WELINE_URL_PATH_LANG');
            } catch (\Throwable) {
            }
            $languageMaps->setValue(null, $originalLanguage);
            $currencyMaps->setValue(null, $originalCurrency);
        }
    }

    public function testUnprefixedPathUsesWebsiteDefaultLanguageNotCookie(): void
    {
        $currencyMaps = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalCurrency = $currencyMaps->getValue(null);
        $originalLanguage = $languageMaps->getValue(null);

        try {
            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);

            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context([
                'meta' => ['type' => 'request', 'mode' => 'wls'],
                'input' => [
                    'uri' => '/USD/faq',
                    'origin_request_uri' => '/USD/faq',
                    'server' => [
                        'REQUEST_URI' => '/USD/faq',
                        'WELINE_ORIGIN_REQUEST_URI' => '/USD/faq',
                        'WELINE_WEBSITE_ID' => '0',
                        'WELINE_WEBSITE_CODE' => 'default',
                        'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                    ],
                ],
                'route' => [
                    'website_id' => 0,
                    'website_code' => 'default',
                    'language' => 'zh_Hans_CN',
                    'currency' => 'CNY',
                ],
            ]));
            RequestContext::init();

            $scope = $this->currentWebsiteScopeKey();
            $this->seedAllowedCurrencyMap($scope, ['CNY' => true, 'USD' => true]);
            $this->seedAllowedLanguageMap($scope, ['zh_hans_cn' => true, 'en_us' => true]);

            WelineEnv::set('website.language', 'zh_Hans_CN', 'unit test website default');
            WelineEnv::set('user.lang', 'en_US', 'unit test stale worker locale');
            $_COOKIE['WELINE_USER_LANG'] = 'en_US';
            $_COOKIE['WELINE_USER_LANG_w0'] = 'en_US';
            WelineEnv::set('cookie.WELINE_USER_LANG', 'en_US', 'unit test');
            WelineEnv::set('cookie.WELINE_USER_LANG_w0', 'en_US', 'unit test');

            $parse = [
                'currency' => 'USD',
                'language' => 'en_US',
                'server' => [
                    'WELINE_USER_CURRENCY' => 'USD',
                    'WELINE_USER_LANG' => 'en_US',
                    'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                ],
            ];
            $method->invokeArgs(new App(), [&$parse, '/USD/faq']);

            self::assertSame('zh_Hans_CN', $parse['language']);
            self::assertSame('zh_Hans_CN', $parse['server']['WELINE_USER_LANG']);
        } finally {
            unset($_COOKIE['WELINE_USER_LANG'], $_COOKIE['WELINE_USER_LANG_w0']);
            WelineEnv::set('cookie.WELINE_USER_LANG', null, 'unit test cleanup');
            WelineEnv::set('cookie.WELINE_USER_LANG_w0', null, 'unit test cleanup');
            $currencyMaps->setValue(null, $originalCurrency);
            $languageMaps->setValue(null, $originalLanguage);
        }
    }

    /** @param array<string, true> $map */
    private function seedAllowedLanguageMap(string $scope, array $map): void
    {
        $property = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $maps = $property->getValue(null);
        if (!\is_array($maps)) {
            $maps = [];
        }
        $maps[$scope] = $map;
        $property->setValue(null, $maps);
    }

    /** @param array<string, true> $map */
    private function seedAllowedCurrencyMap(string $scope, array $map): void
    {
        $property = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $maps = $property->getValue(null);
        if (!\is_array($maps)) {
            $maps = [];
        }
        $maps[$scope] = $map;
        $property->setValue(null, $maps);
    }

    private function currentWebsiteScopeKey(): string
    {
        $method = new ReflectionMethod(State::class, 'currentWebsiteScopeKey');
        $method->setAccessible(true);

        return (string)$method->invoke(null);
    }

    private function createAppForRequest(string $uri, array $server = []): App
    {
        $server = ['REQUEST_URI' => $uri] + $server;
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'unit',
            ],
            'input' => [
                'uri' => $uri,
                'server' => $server,
            ],
        ]));
        WelineEnv::set('request.uri', $uri, 'unit test');
        WelineEnv::set('is_static_file', false, 'unit test');

        return new App();
    }

    private function shouldEagerStartSession(App $app): bool
    {
        $method = new ReflectionMethod($app, 'shouldEagerStartSessionForCurrentRequest');
        $method->setAccessible(true);

        return (bool)$method->invoke($app);
    }
}
