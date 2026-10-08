<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\RequestContext;

/**
 * Live preview /~preview/{token}/EUR/… must feed path currency into
 * App::synchronizeParsedLocalization (route identity), matching formal /~site/…/EUR.
 */
final class AppSynchronizeLivePreviewCurrencyContractTest extends TestCase
{
    private const TOKEN = 'pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    public function testFrameworkSyncUsesNormalizeVisitorUriBeforePathCurrency(): void
    {
        $source = (string)file_get_contents(
            APP_CODE_PATH . 'Weline/Framework/App.php'
        );
        self::assertStringContainsString(
            'private function synchronizeParsedLocalization',
            $source
        );
        $syncPos = strpos($source, 'private function synchronizeParsedLocalization');
        self::assertNotFalse($syncPos);
        $slice = substr($source, (int)$syncPos, 1800);
        self::assertStringContainsString('Url::normalizeVisitorUri', $slice);
        self::assertStringContainsString('routing_uri', $slice);
    }

    public function testLivePreviewPathCurrencyWinsWhenWebsiteOnlyAdvertisesDefault(): void
    {
        $currencyMaps = new ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $languageMaps = new ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalCurrency = $currencyMaps->getValue(null);
        $originalLanguage = $languageMaps->getValue(null);

        try {
            // Visible live-preview URLs omit /~site; include it in origin here so the
            // unit peel does not need a real PreviewToken website binding.
            $origin = '/~preview/' . self::TOKEN . '/~site/grocery/EUR/en_US/products';

            $normalized = Url::normalizeVisitorUri($origin);
            self::assertSame($origin, $normalized['origin_uri']);
            self::assertSame('/~site/grocery/EUR/en_US/products', $normalized['routing_uri']);

            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context([
                'meta' => ['type' => 'request', 'mode' => 'wls'],
                'input' => [
                    'uri' => '/products',
                    'origin_request_uri' => $origin,
                    'server' => [
                        'REQUEST_URI' => '/products',
                        'WELINE_ORIGIN_REQUEST_URI' => $origin,
                        'WELINE_WEBSITE_ID' => '544',
                        'WELINE_WEBSITE_CODE' => 'grocery',
                    ],
                ],
                'route' => [
                    'website_id' => 544,
                    'website_code' => 'grocery',
                    'language' => 'zh_Hans_CN',
                    'currency' => 'CNY',
                ],
            ]));
            RequestContext::init();

            $scopeMethod = new ReflectionMethod(State::class, 'currentWebsiteScopeKey');
            $scopeMethod->setAccessible(true);
            $scope = (string)$scopeMethod->invoke(null);

            // Grocery selector only advertises CNY — path EUR must still win.
            $currencyMaps->setValue(null, [$scope => ['CNY' => true]]);
            $languageMaps->setValue(null, [$scope => ['zh_hans_cn' => true, 'en_us' => true]]);

            $parse = [
                'currency' => 'CNY',
                'language' => 'zh_Hans_CN',
                'area' => 'frontend',
                'server' => [
                    'WELINE_USER_CURRENCY' => 'CNY',
                    'WELINE_USER_LANG' => 'zh_Hans_CN',
                    'WELINE_WEBSITE_URL' => 'https://p05113ef3.test.weline.com/~site/grocery',
                    'WELINE_WEBSITE_CURRENCY' => 'CNY',
                    'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                ],
            ];

            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);
            $method->invokeArgs(new App(), [&$parse, $origin]);

            self::assertSame('EUR', $parse['currency'], $origin);
            self::assertSame('en_US', $parse['language'], $origin);
            self::assertSame('EUR', $parse['server']['WELINE_USER_CURRENCY']);
            self::assertSame('en_US', $parse['server']['WELINE_USER_LANG']);
        } finally {
            $currencyMaps->setValue(null, $originalCurrency);
            $languageMaps->setValue(null, $originalLanguage);
            try {
                WelineEnv::getInstance()->reset();
            } catch (\Throwable) {
            }
            RequestContext::cleanup();
            Context::leave();
        }
    }

    public function testVisibleLivePreviewCurrencySegmentWithoutSiteStackAlsoWins(): void
    {
        $currencyMaps = new ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $languageMaps = new ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalCurrency = $currencyMaps->getValue(null);
        $originalLanguage = $languageMaps->getValue(null);

        try {
            // Visible form: /~preview/{token}/EUR/en_US/products — Theme rehydrates
            // /~site/{code} into routing when Token carries website identity.
            $origin = '/~preview/' . self::TOKEN . '/EUR/en_US/products';
            $normalized = Url::normalizeVisitorUri($origin);
            self::assertSame($origin, $normalized['origin_uri']);
            // Token may or may not resolve grocery in unit bootstrap; require that
            // EUR is the first localization segment after any /~site/{code} peel.
            $routingPath = (string)(\parse_url($normalized['routing_uri'], \PHP_URL_PATH) ?: '');
            self::assertMatchesRegularExpression(
                '#(?:/~site/[a-z0-9_-]+)?/EUR/en_US/products$#',
                $routingPath
            );

            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context([
                'meta' => ['type' => 'request', 'mode' => 'wls'],
                'input' => [
                    'uri' => '/products',
                    'origin_request_uri' => $origin,
                    'server' => [
                        'REQUEST_URI' => '/products',
                        'WELINE_ORIGIN_REQUEST_URI' => $origin,
                        'WELINE_WEBSITE_ID' => '544',
                        'WELINE_WEBSITE_CODE' => 'grocery',
                    ],
                ],
                'route' => [
                    'website_id' => 544,
                    'website_code' => 'grocery',
                    'language' => 'zh_Hans_CN',
                    'currency' => 'CNY',
                ],
            ]));
            RequestContext::init();

            $scopeMethod = new ReflectionMethod(State::class, 'currentWebsiteScopeKey');
            $scopeMethod->setAccessible(true);
            $scope = (string)$scopeMethod->invoke(null);
            $currencyMaps->setValue(null, [$scope => ['CNY' => true]]);
            $languageMaps->setValue(null, [$scope => ['zh_hans_cn' => true, 'en_us' => true]]);

            $parse = [
                'currency' => 'CNY',
                'language' => 'zh_Hans_CN',
                'area' => 'frontend',
                'server' => [
                    'WELINE_USER_CURRENCY' => 'CNY',
                    'WELINE_USER_LANG' => 'zh_Hans_CN',
                    'WELINE_WEBSITE_URL' => 'https://p05113ef3.test.weline.com/~site/grocery',
                    'WELINE_WEBSITE_CURRENCY' => 'CNY',
                    'WELINE_WEBSITE_LANGUAGE' => 'zh_Hans_CN',
                ],
            ];

            $method = new ReflectionMethod(App::class, 'synchronizeParsedLocalization');
            $method->setAccessible(true);
            $method->invokeArgs(new App(), [&$parse, $origin]);

            self::assertSame('EUR', $parse['currency'], 'routing=' . $normalized['routing_uri']);
            self::assertSame('en_US', $parse['language'], 'routing=' . $normalized['routing_uri']);
        } finally {
            $currencyMaps->setValue(null, $originalCurrency);
            $languageMaps->setValue(null, $originalLanguage);
            try {
                WelineEnv::getInstance()->reset();
            } catch (\Throwable) {
            }
            RequestContext::cleanup();
            Context::leave();
        }
    }
}
