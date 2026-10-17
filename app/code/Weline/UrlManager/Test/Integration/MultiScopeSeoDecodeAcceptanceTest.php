<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Integration;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Model\UrlRewrite;
use Weline\UrlManager\Observer\RouterRewrite;
use Weline\UrlManager\Observer\SeoUrlGenerateRewrite;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Live multi-scope / multi-currency / multi-language SEO decode acceptance.
 *
 * Builds cases from the real detect_website registry + url_rewrite rows +
 * per-website language/currency associations. Asserts Url::parser binds the
 * correct website and locale, and that pretty rewrites decode to the stored path.
 */
final class MultiScopeSeoDecodeAcceptanceTest extends TestCase
{
    /** @var list<array{website_id:int,code:string,base_url:string,rewrite:string,path:string,currency:string,language:string,prefix:string}> */
    private static array $cases = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $envSnapshot = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$cases = self::buildLiveCases();
        if (self::$cases === []) {
            self::markTestSkipped('No live website+rewrite matrix available on this machine.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
        $this->resetParseBags();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        $this->resetParseBags();
        parent::tearDown();
    }

    public function testLiveMatrixParsesWebsiteLocaleAndRewriteAcrossScopes(): void
    {
        $failures = [];
        $passed = 0;

        foreach (self::$cases as $case) {
            try {
                $absolute = \rtrim($case['base_url'], '/') . $case['prefix'] . '/' . \ltrim($case['rewrite'], '/');
                $parsed = $this->parseAbsolute($absolute);
                $websiteId = (int)($parsed['website']['website_id'] ?? $parsed['server']['WELINE_WEBSITE_ID'] ?? -1);
                $currency = (string)($parsed['currency'] ?? '');
                $language = (string)($parsed['language'] ?? '');
                $uri = (string)($parsed['uri'] ?? '');
                $requestUri = (string)($parsed['server']['REQUEST_URI'] ?? '');

                if ($websiteId !== $case['website_id']) {
                    $failures[] = $absolute . " website_id={$websiteId} expected={$case['website_id']}";
                    continue;
                }
                if ($currency !== $case['currency']) {
                    $failures[] = $absolute . " currency={$currency} expected={$case['currency']}";
                    continue;
                }
                if ($language !== $case['language']) {
                    $failures[] = $absolute . " language={$language} expected={$case['language']}";
                    continue;
                }

                $expectedPath = \ltrim((string)$case['path'], '/');
                $decodedOk = $uri === $expectedPath
                    || \str_starts_with($uri, $expectedPath)
                    || \str_contains($requestUri, \strtok($expectedPath, '?') ?: $expectedPath);
                if (!$decodedOk) {
                    $failures[] = $absolute . " uri={$uri} request_uri={$requestUri} expected_path={$expectedPath}";
                    continue;
                }
                ++$passed;
            } catch (\Throwable $e) {
                $failures[] = ($case['base_url'] ?? '') . $case['prefix'] . '/' . $case['rewrite'] . ' :: ' . $e->getMessage();
            }
        }

        self::assertSame(
            [],
            $failures,
            \sprintf(
                "Multi-scope SEO decode failures (%d/%d passed). First failures:\n- %s",
                $passed,
                \count(self::$cases),
                \implode("\n- ", \array_slice($failures, 0, 20))
            )
        );
        self::assertGreaterThan(0, $passed);
    }

    public function testInvalidateClearsProcessBagsThenMatrixStillParses(): void
    {
        $sample = self::$cases[0];
        $absolute = \rtrim($sample['base_url'], '/') . $sample['prefix'] . '/' . \ltrim($sample['rewrite'], '/');
        $first = $this->parseAbsolute($absolute);
        self::assertSame($sample['website_id'], (int)($first['website']['website_id'] ?? -1));

        SeoUrlGenerateRewrite::invalidateCaches();

        $rr = new \ReflectionClass(RouterRewrite::class);
        $processCache = $rr->getProperty('processCache');
        $processCache->setAccessible(true);
        self::assertSame([], $processCache->getValue());

        $urlReflection = new \ReflectionClass(Url::class);
        $decodeBag = $urlReflection->getProperty('processDecodeUrls');
        $decodeBag->setAccessible(true);
        self::assertSame([], $decodeBag->getValue());

        $second = $this->parseAbsolute($absolute);
        self::assertSame($sample['website_id'], (int)($second['website']['website_id'] ?? -1));
        self::assertSame((string)$sample['currency'], (string)($second['currency'] ?? ''));
        self::assertSame((string)$sample['language'], (string)($second['language'] ?? ''));
    }

    public function testWarmDecodeReusesProcessCacheWithoutChangingSemantics(): void
    {
        $sample = self::$cases[0];
        $absolute = \rtrim($sample['base_url'], '/') . $sample['prefix'] . '/' . \ltrim($sample['rewrite'], '/');
        $cold = $this->parseAbsolute($absolute);
        $warm = $this->parseAbsolute($absolute, false);

        self::assertSame(
            (int)($cold['website']['website_id'] ?? -1),
            (int)($warm['website']['website_id'] ?? -1)
        );
        self::assertSame((string)($cold['currency'] ?? ''), (string)($warm['currency'] ?? ''));
        self::assertSame((string)($cold['language'] ?? ''), (string)($warm['language'] ?? ''));
        self::assertSame((string)($cold['uri'] ?? ''), (string)($warm['uri'] ?? ''));
    }

    /**
     * @return list<array{website_id:int,code:string,base_url:string,rewrite:string,path:string,currency:string,language:string,prefix:string}>
     */
    private static function buildLiveCases(): array
    {
        $detect = new DataObject(['sites' => [], 'get_sites' => true]);
        w_obj(EventsManager::class)->dispatch('Weline_Framework_Url::detect_website', $detect);
        $sites = $detect->getData('sites');
        if (!\is_array($sites) || $sites === []) {
            return [];
        }

        /** @var array<int, array{website_id:int,code:string,default_currency:string,default_language:string,urls:list<string>}> $byId */
        $byId = [];
        foreach ($sites as $url => $site) {
            if (!\is_array($site)) {
                continue;
            }
            $id = (int)($site['website_id'] ?? -1);
            if ($id < 0) {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'website_id' => $id,
                    'code' => (string)($site['code'] ?? ''),
                    'default_currency' => \strtoupper((string)($site['default_currency'] ?? 'CNY')),
                    'default_language' => (string)($site['default_language'] ?? 'zh_Hans_CN'),
                    'urls' => [],
                ];
            }
            $candidate = \is_string($url) ? $url : (string)($site['url'] ?? '');
            if ($candidate !== '' && !\in_array($candidate, $byId[$id]['urls'], true)) {
                $byId[$id]['urls'][] = $candidate;
            }
        }

        $model = w_obj(UrlRewrite::class);
        $websiteLanguage = w_obj(WebsiteLanguage::class);
        $websiteCurrency = w_obj(WebsiteCurrency::class);
        $cases = [];
        $seen = [];

        foreach ($byId as $id => $meta) {
            $rows = $model->newQuery()
                ->where(UrlRewrite::schema_fields_WEBSITE_ID, $id)
                ->where(UrlRewrite::schema_fields_REWRITE, '', '!=')
                ->order(UrlRewrite::schema_fields_ID, 'DESC')
                ->limit(3)
                ->select()
                ->fetchArray();
            if (!\is_array($rows) || $rows === []) {
                continue;
            }
            if (\array_key_exists(UrlRewrite::schema_fields_ID, $rows)) {
                $rows = [$rows];
            }

            $baseUrl = self::preferAcceptanceBaseUrl($meta['urls']);
            if ($baseUrl === '') {
                continue;
            }

            $languages = self::normalizeLanguageCodes($websiteLanguage->getWebsiteLanguageCodes($id), $meta['default_language']);
            $currencies = self::normalizeCurrencyCodes($websiteCurrency->getWebsiteCurrencyCodes($id), $meta['default_currency']);
            // Default site may have dozens of locales — keep full currency set but
            // always include defaults + en_US/zh_Hans_CN and up to 6 additional langs.
            if (\count($languages) > 8) {
                $keep = [$meta['default_language'], 'zh_Hans_CN', 'en_US'];
                foreach ($languages as $code) {
                    if (\count($keep) >= 8) {
                        break;
                    }
                    if (!\in_array($code, $keep, true)) {
                        $keep[] = $code;
                    }
                }
                $languages = $keep;
            }

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $rewrite = \trim((string)($row[UrlRewrite::schema_fields_REWRITE] ?? ''), '/');
                $path = (string)($row[UrlRewrite::schema_fields_PATH] ?? '');
                if ($rewrite === '' || $path === '') {
                    continue;
                }

                foreach ($currencies as $currency) {
                    foreach ($languages as $language) {
                        foreach (self::localePrefixCases(
                            $currency,
                            $language,
                            $meta['default_currency'],
                            $meta['default_language']
                        ) as $prefixCase) {
                            $absolute = \rtrim($baseUrl, '/')
                                . $prefixCase['prefix']
                                . '/'
                                . \ltrim($rewrite, '/');
                            if (isset($seen[$absolute])) {
                                continue;
                            }
                            $seen[$absolute] = true;
                            $cases[] = [
                                'website_id' => $id,
                                'code' => $meta['code'],
                                'base_url' => $baseUrl,
                                'rewrite' => $rewrite,
                                'path' => $path,
                                'currency' => $prefixCase['currency'],
                                'language' => $prefixCase['language'],
                                'prefix' => $prefixCase['prefix'],
                            ];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @param list<string> $urls
     */
    private static function preferAcceptanceBaseUrl(array $urls): string
    {
        $ranked = [];
        foreach ($urls as $url) {
            $url = \rtrim($url, '/');
            if ($url === '') {
                continue;
            }
            $score = 0;
            if (\str_contains($url, '.test.weline.com')) {
                $score += 100;
            }
            if (\str_contains($url, '/~site/')) {
                $score += 40;
            }
            if (\str_contains($url, 'localhost') || \str_contains($url, '127.0.0.1')) {
                $score += 20;
            }
            if (\str_starts_with($url, 'https://www.')) {
                $score += 5;
            }
            $ranked[] = [$score, $url];
        }
        if ($ranked === []) {
            return '';
        }
        \usort($ranked, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return (string)$ranked[0][1];
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function normalizeLanguageCodes(array $codes, string $default): array
    {
        $out = [];
        foreach (\array_merge([$default], $codes) as $code) {
            $code = \str_replace('-', '_', \trim((string)$code));
            if ($code === '' || !State::isAllowedLanguageCode($code)) {
                continue;
            }
            if (!\in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out !== [] ? $out : ['zh_Hans_CN', 'en_US'];
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function normalizeCurrencyCodes(array $codes, string $default): array
    {
        $out = [];
        foreach (\array_merge([$default], $codes) as $code) {
            $code = \strtoupper(\trim((string)$code));
            if ($code === '' || !State::isAllowedCurrencyCode($code)) {
                continue;
            }
            if (!\in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out !== [] ? $out : ['CNY', 'USD'];
    }

    /**
     * @return list<array{prefix:string,currency:string,language:string}>
     */
    private static function localePrefixCases(
        string $currency,
        string $language,
        string $defaultCurrency,
        string $defaultLanguage
    ): array {
        return [
            // Bare pretty path → website defaults.
            ['prefix' => '', 'currency' => $defaultCurrency, 'language' => $defaultLanguage],
            ['prefix' => '/' . $language, 'currency' => $defaultCurrency, 'language' => $language],
            ['prefix' => '/' . $currency, 'currency' => $currency, 'language' => $defaultLanguage],
            ['prefix' => '/' . $currency . '/' . $language, 'currency' => $currency, 'language' => $language],
            // Language-then-currency is also accepted by path localization.
            ['prefix' => '/' . $language . '/' . $currency, 'currency' => $currency, 'language' => $language],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAbsolute(string $absolute, bool $clearProcessBags = true): array
    {
        Url::resetParserRequestCaches();
        if ($clearProcessBags) {
            Url::clearProcessDecodeCache();
            RouterRewrite::clearProcessCache();
        }

        $parts = \parse_url($absolute);
        if (!\is_array($parts)) {
            self::fail('Malformed absolute URL: ' . $absolute);
        }
        $host = (string)($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_NAME'] = (string)($parts['host'] ?? 'localhost');
        $_SERVER['REQUEST_SCHEME'] = (string)($parts['scheme'] ?? 'https');
        $_SERVER['REQUEST_URI'] = $path . $query;
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $path . $query;
        $_SERVER['ORIGIN_REQUEST_URI'] = $path . $query;
        foreach (['WELINE_WEBSITE_ID', 'WELINE_WEBSITE_CODE', 'WELINE_USER_LANG', 'WELINE_USER_CURRENCY', 'WELINE_URL_PATH_LANG'] as $key) {
            unset($_SERVER[$key]);
        }
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);

        $parsed = Url::parser($absolute);
        self::assertIsArray($parsed, 'Url::parser must return array for ' . $absolute);

        return $parsed;
    }

    private function resetParseBags(): void
    {
        Url::resetParserRequestCaches();
        Url::clearProcessDecodeCache();
        RouterRewrite::clearProcessCache();
    }
}
