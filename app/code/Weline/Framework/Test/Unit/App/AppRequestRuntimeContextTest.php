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
                'host' => 'p11005ce4.weline.test',
            ],
        ]));

        $_SERVER = [
            'REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.weline.test',
            'SERVER_PORT' => '443',
        ];

        App::init();

        self::assertSame(
            '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            Context::current()?->get('input.server.WELINE_ORIGIN_REQUEST_URI')
        );
        self::assertSame(
            'https://p11005ce4.weline.test/pagebuilder/backend/ai-site-agent/index?legacy=1',
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

    public function testLocalizedHomepageSynchronizesUnifiedCacheDimensionsFromEitherPrefixOrder(): void
    {
        $currencyMap = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap');
        $currencyScope = new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope');
        $languageMap = new \ReflectionProperty(State::class, 'allowedLanguageCodeMap');
        $languageScope = new \ReflectionProperty(State::class, 'allowedLanguageCodeScope');
        $original = [
            $currencyMap->getValue(),
            $currencyScope->getValue(),
            $languageMap->getValue(),
            $languageScope->getValue(),
        ];

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

                $scope = (string)WelineEnv::get('website_id', '')
                    . '|' . (string)WelineEnv::get('website.code', '')
                    . '|' . (string)WelineEnv::server('WELINE_WEBSITE_ID', '');
                // URL currency is an authoritative route dimension even when
                // the current Website selector only exposes its default CNY.
                // It must not collapse /USD/... onto the CNY cache context.
                $currencyMap->setValue(null, ['CNY' => true]);
                $currencyScope->setValue(null, $scope);
                $languageMap->setValue(null, ['zh_hans_cn' => true, 'en_us' => true]);
                $languageScope->setValue(null, $scope);

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
            $currencyMap->setValue(null, $original[0]);
            $currencyScope->setValue(null, $original[1]);
            $languageMap->setValue(null, $original[2]);
            $languageScope->setValue(null, $original[3]);
        }
    }

    public function testUnprefixedPathUsesStateLangInsteadOfStaleParserLocale(): void
    {
        $currencyMap = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap');
        $currencyScope = new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope');
        $languageMap = new \ReflectionProperty(State::class, 'allowedLanguageCodeMap');
        $languageScope = new \ReflectionProperty(State::class, 'allowedLanguageCodeScope');
        $original = [
            $currencyMap->getValue(),
            $currencyScope->getValue(),
            $languageMap->getValue(),
            $languageScope->getValue(),
        ];

        try {
            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);

            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context([
                'meta' => ['type' => 'request', 'mode' => 'wls'],
                'input' => [
                    'uri' => '/USD/help',
                    'origin_request_uri' => '/USD/help',
                    'server' => [
                        'REQUEST_URI' => '/USD/help',
                        'WELINE_ORIGIN_REQUEST_URI' => '/USD/help',
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

            $scope = (string)WelineEnv::get('website_id', '')
                . '|' . (string)WelineEnv::get('website.code', '')
                . '|' . (string)WelineEnv::server('WELINE_WEBSITE_ID', '');
            $currencyMap->setValue(null, ['CNY' => true, 'USD' => true]);
            $currencyScope->setValue(null, $scope);
            $languageMap->setValue(null, ['zh_hans_cn' => true, 'en_us' => true]);
            $languageScope->setValue(null, $scope);

            WelineEnv::set('user.lang', 'en_US', 'unit test stale worker locale');
            $_COOKIE['WELINE_USER_LANG_w0'] = 'zh_Hans_CN';
            WelineEnv::set('cookie.WELINE_USER_LANG_w0', 'zh_Hans_CN', 'unit test');

            $parse = [
                'currency' => 'USD',
                'language' => 'en_US',
                'server' => [
                    'WELINE_USER_CURRENCY' => 'USD',
                    'WELINE_USER_LANG' => 'en_US',
                ],
            ];
            $method->invokeArgs(new App(), [&$parse, '/USD/help']);

            self::assertSame('zh_Hans_CN', $parse['language']);
            self::assertSame('zh_Hans_CN', $parse['server']['WELINE_USER_LANG']);
        } finally {
            unset($_COOKIE['WELINE_USER_LANG_w0']);
            WelineEnv::set('cookie.WELINE_USER_LANG_w0', null, 'unit test cleanup');
            $currencyMap->setValue(null, $original[0]);
            $currencyScope->setValue(null, $original[1]);
            $languageMap->setValue(null, $original[2]);
            $languageScope->setValue(null, $original[3]);
        }
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
