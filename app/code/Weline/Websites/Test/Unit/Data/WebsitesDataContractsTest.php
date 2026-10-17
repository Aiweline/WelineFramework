<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Data;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesDataContractsPass().
 */
final class WebsitesDataContractsTest extends TestCase
{
    public function testAllWebsitesDataContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WebsiteDataAssociationMemo::testWebsiteDataExposesMemoizedAssociationReaders' => function () {
            $this->assert_WebsiteDataAssociationMemo_testWebsiteDataExposesMemoizedAssociationReaders();
        },
            'WebsiteDataAssociationMemo::testModelsDelegatePublicReadersToWebsiteData' => function () {
            $this->assert_WebsiteDataAssociationMemo_testModelsDelegatePublicReadersToWebsiteData();
        },
            'WebsiteDataAssociationMemo::testLocalizationProviderUsesWebsiteDataOnly' => function () {
            $this->assert_WebsiteDataAssociationMemo_testLocalizationProviderUsesWebsiteDataOnly();
        },
            'WebsiteLocalRowsProcessBag::testSourceUsesProcessBagNotRequestContextMirror' => function () {
            $this->setUp_WebsiteLocalRowsProcessBag();
            try {
                $this->assert_WebsiteLocalRowsProcessBag_testSourceUsesProcessBagNotRequestContextMirror();
            } finally {
                $this->tearDown_WebsiteLocalRowsProcessBag();
            }
        },
            'WebsiteLocalRowsProcessBag::testClearProcessCacheDropsLocalRows' => function () {
            $this->setUp_WebsiteLocalRowsProcessBag();
            try {
                $this->assert_WebsiteLocalRowsProcessBag_testClearProcessCacheDropsLocalRows();
            } finally {
                $this->tearDown_WebsiteLocalRowsProcessBag();
            }
        },
            'WebsiteLocalRowsProcessBag::testInjectedProcessBagServesAlternateNameWithoutRequestBag' => function () {
            $this->setUp_WebsiteLocalRowsProcessBag();
            try {
                $this->assert_WebsiteLocalRowsProcessBag_testInjectedProcessBagServesAlternateNameWithoutRequestBag();
            } finally {
                $this->tearDown_WebsiteLocalRowsProcessBag();
            }
        },
        ];
    }

private function assert_WebsiteDataAssociationMemo_testWebsiteDataExposesMemoizedAssociationReaders(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Data/WebsiteData.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function languageCodesForWebsite', $source);
    self::assertStringContainsString('function currencyCodesForWebsite', $source);
    self::assertStringContainsString('function defaultLanguageForWebsite', $source);
    self::assertStringContainsString('function defaultCurrencyForWebsite', $source);
    self::assertStringNotContainsString('preferDefaultLanguageFirst', $source, 'language_codes must keep association sort_order; routing uses default_language field.');
    self::assertStringContainsString('function domainsForWebsite', $source);
    self::assertStringContainsString('function loadAndPublishSnapshotById', $source);
    self::assertStringContainsString('fetchAssociationCodesFromDatabase', $source);
    self::assertStringContainsString('fetchAssociationDomainsFromDatabase', $source);
    self::assertStringContainsString('$processDomainsByWebsiteId', $source);
    self::assertStringNotContainsString('getWebsiteLanguageCodes($websiteId)', $source, 'WebsiteData must not recurse through memoizing model getters.');
}

private function assert_WebsiteDataAssociationMemo_testModelsDelegatePublicReadersToWebsiteData(): void
{
    $language = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/WebsiteLanguage.php');
    $currency = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/WebsiteCurrency.php');
    $domain = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Model/WebsiteDomain.php');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php');
    self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $language);
    self::assertStringContainsString('function fetchAssociationCodesFromDatabase', $language);
    self::assertStringNotContainsString('getDefaultLanguage()', $language, 'Association raw reader must not load Website for default_language reorder.');
    self::assertStringNotContainsString('Website::schema_fields_ID', $language, 'Association raw reader must not Website::find by id.');
    self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $currency);
    self::assertStringContainsString('function fetchAssociationCodesFromDatabase', $currency);
    self::assertStringContainsString('WebsiteData::domainsForWebsite', $domain);
    self::assertStringContainsString('function fetchAssociationDomainsFromDatabase', $domain);
    self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $provider);
    self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $provider);
    self::assertStringContainsString('WebsiteData::domainsForWebsite', $provider);
    self::assertStringContainsString("'getWebsiteCurrencyCodes'", $provider);
}

private function assert_WebsiteDataAssociationMemo_testLocalizationProviderUsesWebsiteDataOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Localization/LocalizationProvider.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $source);
    self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $source);
    self::assertStringContainsString('function defaultLanguage', $source);
    self::assertStringContainsString('function defaultCurrency', $source);
    self::assertStringContainsString('WebsiteData::defaultLanguageForWebsite', $source);
    self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $source);
    self::assertStringNotContainsString('WebsiteLanguage::class', $source);
    self::assertStringNotContainsString('WebsiteCurrency::class', $source);
}

private function setUp_WebsiteLocalRowsProcessBag(): void
{
    Context::leave();
    Context::enter(new Context());
    WebsiteData::resetRequestState();
    WebsiteData::clearProcessCache();
}

private function tearDown_WebsiteLocalRowsProcessBag(): void
{
    WebsiteData::resetRequestState();
    WebsiteData::clearProcessCache();
    Context::leave();
    parent::tearDown();
}

private function assert_WebsiteLocalRowsProcessBag_testSourceUsesProcessBagNotRequestContextMirror(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Data/WebsiteData.php');
    self::assertStringContainsString('loadLocalRowsOnce', $src);
    self::assertStringContainsString('$processLocalRowsByWebsiteId', $src);
    self::assertStringContainsString('rememberProcessLocalRows', $src);
    self::assertStringContainsString('function localRowsForWebsite', $src);
    self::assertStringNotContainsString('websites.website_local_rows.v1', $src);
    self::assertStringNotContainsString('private static array $localizedFieldCache', $src, 'per-field process-static cache is Fiber-unsafe and misses listConfiguredLocalNames');
    self::assertMatchesRegularExpression('/function\s+listConfiguredLocalNames[\s\S]*loadLocalRowsOnce\s*\(/', $src);
    self::assertMatchesRegularExpression('/function\s+resolveLocalizedFieldForLocale[\s\S]*loadLocalRowsOnce\s*\(/', $src);
    self::assertMatchesRegularExpression('/function\s+clearProcessCache[\s\S]*processLocalRowsByWebsiteId/', $src);
}

private function assert_WebsiteLocalRowsProcessBag_testClearProcessCacheDropsLocalRows(): void
{
    WebsiteData::putProcessLocalRows(0, [['local_code' => 'en_US', 'name' => 'EN', 'description' => '']]);
    self::assertSame(
        [['local_code' => 'en_US', 'name' => 'EN', 'description' => '']],
        WebsiteData::localRowsForWebsite(0),
    );
    WebsiteData::clearProcessCache();
    $prop = new \ReflectionProperty(WebsiteData::class, 'processLocalRowsByWebsiteId');
    self::assertSame([], $prop->getValue());
}

private function assert_WebsiteLocalRowsProcessBag_testInjectedProcessBagServesAlternateNameWithoutRequestBag(): void
{
    $website = new WebsiteLocalRowsMemoWebsiteStub([Website::schema_fields_ID => 0, Website::schema_fields_CODE => 'default', Website::schema_fields_NAME => '主表站名', Website::schema_fields_URL => 'https://default.example.test', Website::schema_fields_DEFAULT_CURRENCY => 'CNY', Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC', Website::schema_fields_DESCRIPTION => '']);
    WebsiteData::setWebsite($website);
    WebsiteData::putProcessLocalRows(0, [
        ['local_code' => 'zh_Hans_CN', 'name' => '中文站名', 'description' => '中文简介'],
        ['local_code' => 'en_US', 'name' => 'EN Brand', 'description' => 'EN about'],
    ]);
    $alt = WebsiteData::getOrganizationAlternateName('中文站名');
    self::assertSame('EN Brand', $alt);
    self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
    self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
    self::assertNull(RequestContext::get('websites.website_local_rows.v1'));
}
}

/**
 * Minimal Website stub for process-bag local-rows contract (no ORM).
 */
final class WebsiteLocalRowsMemoWebsiteStub extends Website
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }
    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            $this->values = $key;
        } else {
            $this->values[(string) $key] = $value;
        }
        return $this;
    }
    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->values;
        }
        return $this->values[$key] ?? null;
    }
    public function hasData(string $key = ''): bool
    {
        return $key === '' ? $this->values !== [] : \array_key_exists($key, $this->values);
    }
}
