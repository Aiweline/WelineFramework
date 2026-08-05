<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;

final class UrlPrefixNormalizationTest extends TestCase
{
    private array $serverBackup = [];
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
        $this->resetParserState();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        $this->resetParserState();
        parent::tearDown();
    }

    public function testPrefixDoesNotAppendApiAreaSegmentAsCurrency(): void
    {
        if (Env::getAreaRoutePrefix('rest_frontend') === null) {
            self::markTestSkipped('REST frontend route prefix is not configured.');
        }

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/backend/affiliate',
            'HTTP_HOST' => '127.0.0.1:9502',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '9502',
            'REQUEST_SCHEME' => 'http',
            'WELINE_AREA' => 'backend',
            'WELINE_USER_CURRENCY' => 'API',
            'WELINE_USER_LANG' => 'en_US',
        ];

        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);

        self::assertStringNotContainsString('/API', Url::getPrefix());
    }

    public function testDetectCurrencyRejectsApiAreaSegment(): void
    {
        if (Env::getAreaRoutePrefix('rest_frontend') === null) {
            self::markTestSkipped('REST frontend route prefix is not configured.');
        }

        $uri = '/API/en_US/affiliate/backend/affiliate';

        self::assertFalse(Url::detectCurrency($uri, 'API'));
        self::assertSame('/API/en_US/affiliate/backend/affiliate', $uri);
    }

    public function testParserStripsSingleAndEitherDoublePrefixOrder(): void
    {
        $cases = [
            ['/CNY/catalog', 'frontend'],
            ['/zh_Hans_CN/catalog', 'frontend'],
            ['/CNY/zh_Hans_CN/catalog', 'frontend'],
            ['/zh_Hans_CN/CNY/catalog', 'frontend'],
            ['/api/zh_Hans_CN/CNY/catalog', 'rest_frontend'],
        ];

        foreach ($cases as [$path, $area]) {
            $parsed = $this->parsePath($path);
            self::assertSame($area, $parsed['area'] ?? null, $path);
            self::assertSame('catalog', $parsed['uri'] ?? null, $path);
            self::assertSame('/catalog', $parsed['server']['REQUEST_URI'] ?? null, $path);
        }
    }

    public function testCanonicalWriterUsesCurrencyThenLocaleForEitherCompatibleOrderAndOmissions(): void
    {
        $currencyMap = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap');
        $currencyScope = new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope');
        $previousMap = $currencyMap->getValue();
        $previousScope = $currencyScope->getValue();

        try {
            foreach (['/USD/en_US/catalog', '/en_US/USD/catalog'] as $path) {
                $parsed = $this->parsePath($path);
                WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $parsed['server']);
                WelineEnv::set('website.currency', 'CNY', 'canonical writer test');
                WelineEnv::set('website.language', 'zh_Hans_CN', 'canonical writer test');
                $currencyMap->setValue(null, ['USD' => true]);
                $currencyScope->setValue(null, $this->currentCurrencyValidationScope());

                self::assertSame('USD', WelineEnv::get('user.currency'), $path);
                self::assertSame('en_US', WelineEnv::get('user.lang'), $path);
                self::assertSame('/USD/en_US', Url::getPrefix(), $path);
            }

            WelineEnv::set('user.currency', 'CNY', 'canonical writer omission test');
            WelineEnv::set('user.lang', 'zh_Hans_CN', 'canonical writer omission test');
            self::assertSame('', Url::getPrefix());

            WelineEnv::set('user.currency', 'USD', 'canonical writer omission test');
            $currencyScope->setValue(null, $this->currentCurrencyValidationScope());
            self::assertSame('/USD', Url::getPrefix());

            WelineEnv::set('user.currency', 'CNY', 'canonical writer omission test');
            WelineEnv::set('user.lang', 'en_US', 'canonical writer omission test');
            self::assertSame('/en_US', Url::getPrefix());
        } finally {
            $currencyMap->setValue(null, $previousMap);
            $currencyScope->setValue(null, $previousScope);
        }
    }

    public function testParserDoesNotConsumeDuplicateLocalizationTypes(): void
    {
        $duplicateCurrency = $this->parsePath('/CNY/USD/catalog');
        self::assertSame('USD/catalog', $duplicateCurrency['uri'] ?? null);
        self::assertSame('/USD/catalog', $duplicateCurrency['server']['REQUEST_URI'] ?? null);

        $duplicateLanguage = $this->parsePath('/en_US/zh_Hans_CN/catalog');
        self::assertSame('zh_Hans_CN/catalog', $duplicateLanguage['uri'] ?? null);
        self::assertSame('/zh_Hans_CN/catalog', $duplicateLanguage['server']['REQUEST_URI'] ?? null);
    }

    public function testParserRequiresExactRuntimeBackendKeyAndSupportsEitherOrderAfterIt(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        foreach ([
            '/' . $backendPrefix . '/CNY/zh_Hans_CN/admin/login',
            '/' . $backendPrefix . '/zh_Hans_CN/CNY/admin/login',
        ] as $path) {
            $parsed = $this->parsePath($path);
            self::assertSame('backend', $parsed['area'] ?? null, $path);
            self::assertSame('admin/login', $parsed['uri'] ?? null, $path);
            self::assertSame('/admin/login', $parsed['server']['REQUEST_URI'] ?? null, $path);
        }

        $wrongCase = strtolower($backendPrefix);
        if ($wrongCase !== $backendPrefix) {
            $parsed = $this->parsePath('/' . $wrongCase . '/CNY/zh_Hans_CN/admin/login');
            self::assertSame('frontend', $parsed['area'] ?? null);
            self::assertSame($wrongCase . '/CNY/zh_Hans_CN/admin/login', $parsed['uri'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function parsePath(string $path): array
    {
        $this->resetParserState();
        $parsed = Url::parser('http://localhost' . $path);
        self::assertIsArray($parsed);

        return $parsed;
    }

    private function resetParserState(): void
    {
        Url::$parserServer = [];
        Url::resetWebsiteParserSites();
        Url::resetParserRequestCaches();
        State::resetRequestPathLocalizationCache();
    }

    private function currentCurrencyValidationScope(): string
    {
        return (string)\w_env('website_id', '')
            . '|' . (string)\w_env('website.code', '')
            . '|' . (string)WelineEnv::server('WELINE_WEBSITE_ID', '');
    }
}
