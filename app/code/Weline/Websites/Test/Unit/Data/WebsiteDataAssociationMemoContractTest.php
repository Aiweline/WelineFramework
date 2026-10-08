<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class WebsiteDataAssociationMemoContractTest extends TestCase
{
    public function testWebsiteDataExposesMemoizedAssociationReaders(): void
    {
        $path = dirname(__DIR__, 3) . '/Data/WebsiteData.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function languageCodesForWebsite', $source);
        self::assertStringContainsString('function currencyCodesForWebsite', $source);
        self::assertStringContainsString('function defaultLanguageForWebsite', $source);
        self::assertStringContainsString('function defaultCurrencyForWebsite', $source);
        self::assertStringContainsString('function preferDefaultLanguageFirst', $source);
        self::assertStringContainsString('function domainsForWebsite', $source);
        self::assertStringContainsString('function loadAndPublishSnapshotById', $source);
        self::assertStringContainsString('fetchAssociationCodesFromDatabase', $source);
        self::assertStringContainsString('fetchAssociationDomainsFromDatabase', $source);
        self::assertStringContainsString('$processDomainsByWebsiteId', $source);
        self::assertStringNotContainsString(
            'getWebsiteLanguageCodes($websiteId)',
            $source,
            'WebsiteData must not recurse through memoizing model getters.',
        );
    }

    public function testModelsDelegatePublicReadersToWebsiteData(): void
    {
        $language = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/WebsiteLanguage.php'
        );
        $currency = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/WebsiteCurrency.php'
        );
        $domain = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/WebsiteDomain.php'
        );
        $provider = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php'
        );

        self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $language);
        self::assertStringContainsString('function fetchAssociationCodesFromDatabase', $language);
        self::assertStringNotContainsString(
            'getDefaultLanguage()',
            $language,
            'Association raw reader must not load Website for default_language reorder.',
        );
        self::assertStringNotContainsString(
            'Website::schema_fields_ID',
            $language,
            'Association raw reader must not Website::find by id.',
        );
        self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $currency);
        self::assertStringContainsString('function fetchAssociationCodesFromDatabase', $currency);
        self::assertStringContainsString('WebsiteData::domainsForWebsite', $domain);
        self::assertStringContainsString('function fetchAssociationDomainsFromDatabase', $domain);
        self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $provider);
        self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $provider);
        self::assertStringContainsString('WebsiteData::domainsForWebsite', $provider);
        self::assertStringContainsString("'getWebsiteCurrencyCodes'", $provider);
    }

    public function testLocalizationProviderUsesWebsiteDataOnly(): void
    {
        $path = dirname(__DIR__, 3) . '/Api/Localization/LocalizationProvider.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('WebsiteData::languageCodesForWebsite', $source);
        self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $source);
        self::assertStringContainsString('function defaultLanguage', $source);
        self::assertStringContainsString('function defaultCurrency', $source);
        self::assertStringContainsString('WebsiteData::defaultLanguageForWebsite', $source);
        self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $source);
        self::assertStringNotContainsString('WebsiteLanguage::class', $source);
        self::assertStringNotContainsString('WebsiteCurrency::class', $source);
    }
}
