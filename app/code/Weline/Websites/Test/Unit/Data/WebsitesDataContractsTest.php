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
            'WebsiteLocalRowsRequestMemo::testSourceUsesRequestScopedLocalRowsBagNotProcessStaticFieldCache' => function () {
            $this->setUp_WebsiteLocalRowsRequestMemo();
            try {
                $this->assert_WebsiteLocalRowsRequestMemo_testSourceUsesRequestScopedLocalRowsBagNotProcessStaticFieldCache();
            } finally {
                $this->tearDown_WebsiteLocalRowsRequestMemo();
            }
        },
            'WebsiteLocalRowsRequestMemo::testResetRequestStateClearsLocalRowsBag' => function () {
            $this->setUp_WebsiteLocalRowsRequestMemo();
            try {
                $this->assert_WebsiteLocalRowsRequestMemo_testResetRequestStateClearsLocalRowsBag();
            } finally {
                $this->tearDown_WebsiteLocalRowsRequestMemo();
            }
        },
            'WebsiteLocalRowsRequestMemo::testInjectedLocalRowsBagServesAlternateNameWithoutExtraShape' => function () {
            $this->setUp_WebsiteLocalRowsRequestMemo();
            try {
                $this->assert_WebsiteLocalRowsRequestMemo_testInjectedLocalRowsBagServesAlternateNameWithoutExtraShape();
            } finally {
                $this->tearDown_WebsiteLocalRowsRequestMemo();
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

private const LOCAL_ROWS_BAG_KEY_WebsiteLocalRowsRequestMemo = 'websites.website_local_rows.v1';

private function setUp_WebsiteLocalRowsRequestMemo(): void
{
    Context::leave();
    Context::enter(new Context());
    WebsiteData::resetRequestState();
    WebsiteData::clearProcessCache();
}

private function tearDown_WebsiteLocalRowsRequestMemo(): void
{
    WebsiteData::resetRequestState();
    WebsiteData::clearProcessCache();
    Context::leave();
    parent::tearDown();
}

private function assert_WebsiteLocalRowsRequestMemo_testSourceUsesRequestScopedLocalRowsBagNotProcessStaticFieldCache(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Data/WebsiteData.php');
    self::assertStringContainsString("websites.website_local_rows.v1", $src);
    self::assertStringContainsString('loadLocalRowsOnce', $src);
    self::assertStringContainsString('$processLocalRowsByWebsiteId', $src);
    self::assertStringContainsString('rememberProcessLocalRows', $src);
    self::assertStringNotContainsString('private static array $localizedFieldCache', $src, 'per-field process-static cache is Fiber-unsafe and misses listConfiguredLocalNames');
    self::assertMatchesRegularExpression('/function\s+listConfiguredLocalNames[\s\S]*loadLocalRowsOnce\s*\(/', $src);
    self::assertMatchesRegularExpression('/function\s+resolveLocalizedFieldForLocale[\s\S]*loadLocalRowsOnce\s*\(/', $src);
    self::assertMatchesRegularExpression('/function\s+resetRequestState[\s\S]*LOCAL_ROWS_BAG_KEY|website_local_rows\.v1/', $src);
    self::assertMatchesRegularExpression('/function\s+clearProcessCache[\s\S]*processLocalRowsByWebsiteId/', $src);
}

private function assert_WebsiteLocalRowsRequestMemo_testResetRequestStateClearsLocalRowsBag(): void
{
    RequestContext::set(self::LOCAL_ROWS_BAG_KEY_WebsiteLocalRowsRequestMemo, ['0' => [['local_code' => 'en_US', 'name' => 'EN', 'description' => '']]]);
    WebsiteData::resetRequestState();
    self::assertNull(RequestContext::get(self::LOCAL_ROWS_BAG_KEY_WebsiteLocalRowsRequestMemo));
}

private function assert_WebsiteLocalRowsRequestMemo_testInjectedLocalRowsBagServesAlternateNameWithoutExtraShape(): void
{
    $website = new WebsiteLocalRowsMemoWebsiteStub([Website::schema_fields_ID => 0, Website::schema_fields_CODE => 'default', Website::schema_fields_NAME => '主表站名', Website::schema_fields_URL => 'https://default.example.test', Website::schema_fields_DEFAULT_CURRENCY => 'CNY', Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN', Website::schema_fields_DEFAULT_TIMEZONE => 'UTC', Website::schema_fields_DESCRIPTION => '']);
    WebsiteData::setWebsite($website);
    RequestContext::set(self::LOCAL_ROWS_BAG_KEY_WebsiteLocalRowsRequestMemo, ['0' => [['local_code' => 'zh_Hans_CN', 'name' => '中文站名', 'description' => '中文简介'], ['local_code' => 'en_US', 'name' => 'EN Brand', 'description' => 'EN about']]]);
    // Prefer en_US when current display looks Chinese / empty locale path.
    $alt = WebsiteData::getOrganizationAlternateName('中文站名');
    self::assertSame('EN Brand', $alt);
    // Same bag must still serve after repeated SEO-style callers.
    self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
    self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
    self::assertSame('EN Brand', WebsiteData::getOrganizationAlternateName('中文站名'));
    $bag = RequestContext::get(self::LOCAL_ROWS_BAG_KEY_WebsiteLocalRowsRequestMemo);
    self::assertIsArray($bag);
    self::assertCount(1, $bag, 'request bag stays one website_id entry; no process static growth');
}
}

/**
 * Minimal Website stub for request-memo contract (no ORM).
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
