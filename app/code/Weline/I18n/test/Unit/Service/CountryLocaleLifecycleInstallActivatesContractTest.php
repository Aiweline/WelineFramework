<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: installing a country/locale must default-activate and list sorting
 * must prefer active/installed rows first.
 */
final class CountryLocaleLifecycleInstallActivatesContractTest extends TestCase
{
    public function testInstallLocaleDelegatesToActivateLocale(): void
    {
        $source = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php'
        );

        self::assertStringContainsString(
            'public function installLocale(string $localeCode): array',
            $source
        );
        self::assertMatchesRegularExpression(
            '/public function installLocale\(string \$localeCode\): array\s*\{[\s\S]*?return \$this->activateLocale\(\$localeCode\);[\s\S]*?\n    \}/',
            $source,
            'installLocale must default-activate by delegating to activateLocale'
        );
    }

    public function testInstallCountryDelegatesToActivateCountry(): void
    {
        $source = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php'
        );

        self::assertStringContainsString(
            'public function installCountry(string $countryCode): array',
            $source
        );
        self::assertMatchesRegularExpression(
            '/public function installCountry\(string \$countryCode\): array\s*\{[\s\S]*?return \$this->activateCountry\(\$countryCode\);[\s\S]*?\n    \}/',
            $source,
            'installCountry must default-activate by delegating to activateCountry'
        );
    }

    public function testLocaleListingOrdersActiveAndInstalledFirst(): void
    {
        $source = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/Controller/Backend/Countries/Locales.php'
        );

        self::assertStringContainsString(
            "->order('main_table.' . Locale::schema_fields_IS_ACTIVE, 'DESC')",
            $source
        );
        self::assertStringContainsString(
            "->order('main_table.' . Locale::schema_fields_IS_INSTALL, 'DESC')",
            $source
        );
    }

    public function testCountryListingOrdersActiveAndInstalledFirst(): void
    {
        $source = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/Controller/Backend/Countries.php'
        );

        self::assertStringContainsString(
            "->order('main_table.' . CountriesModel::schema_fields_IS_ACTIVE, 'DESC')",
            $source
        );
        self::assertStringContainsString(
            "->order('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 'DESC')",
            $source
        );
    }

    public function testInstallAsyncFormsReloadToFirstPage(): void
    {
        $localeTpl = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/view/templates/Backend/Countries/Locales/getIndex.phtml'
        );
        $countryTpl = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/view/templates/Backend/Countries/index.phtml'
        );
        $localizationTpl = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/view/templates/Backend/Localization/index.phtml'
        );
        $js = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/view/statics/js/backend-admin.js'
        );

        self::assertStringContainsString('data-async-reload="1"', $localeTpl);
        self::assertStringContainsString('data-async-reload="1"', $countryTpl);
        self::assertStringContainsString('data-async-reload="1"', $localizationTpl);
        self::assertStringContainsString("getAttribute('data-async-reload') === '1'", $js);
        self::assertStringContainsString("next.searchParams.delete('page')", $js);
        self::assertStringContainsString('isInstall', $js);
    }

    public function testLifecycleInvalidatesLocaleCatalogCachesImmediately(): void
    {
        $source = (string)file_get_contents(
            \BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php'
        );

        self::assertStringContainsString('private function invalidateLocaleCatalogCaches(): void', $source);
        self::assertStringContainsString('private function syncLocalsStateForLocale(', $source);
        self::assertStringContainsString('ActiveLocaleCodeProvider::class)->reset()', $source);
        self::assertStringContainsString('LanguageSwitcher::clearProcessCaches()', $source);
        self::assertStringContainsString('LanguageSelect::clearProcessCaches()', $source);
        self::assertStringContainsString('Url::bumpWebsiteParserSitesVersion()', $source);

        self::assertMatchesRegularExpression(
            '/public function activateLocale\(string \$localeCode\): array\s*\{[\s\S]*?syncLocalsStateForLocale\(\$localeCode, true, true\);[\s\S]*?invalidateLocaleCatalogCaches\(\);[\s\S]*?\n    \}/',
            $source,
            'activateLocale must sync Locals and invalidate caches before return'
        );
        self::assertMatchesRegularExpression(
            '/public function deactivateLocale\(string \$localeCode\): array\s*\{[\s\S]*?syncLocalsStateForLocale\(\$localeCode, true, false\);[\s\S]*?invalidateLocaleCatalogCaches\(\);[\s\S]*?\n    \}/',
            $source,
            'deactivateLocale must sync Locals and invalidate caches before return'
        );
        self::assertMatchesRegularExpression(
            '/public function uninstallLocale\(string \$localeCode\): array\s*\{[\s\S]*?syncLocalsStateForLocale\(\$localeCode, false, false\);[\s\S]*?invalidateLocaleCatalogCaches\(\);[\s\S]*?\n    \}/',
            $source,
            'uninstallLocale must sync Locals and invalidate caches before return'
        );
        self::assertMatchesRegularExpression(
            '/public function deactivateCountry\(string \$countryCode\): array\s*\{[\s\S]*?syncLocalsForCountry\(\$countryCode\);[\s\S]*?invalidateLocaleCatalogCaches\(\);[\s\S]*?\n    \}/',
            $source,
            'deactivateCountry must sync Locals and invalidate caches before return'
        );
        self::assertMatchesRegularExpression(
            '/public function uninstallCountry\(string \$countryCode\): array\s*\{[\s\S]*?syncLocalsForCountry\(\$countryCode\);[\s\S]*?invalidateLocaleCatalogCaches\(\);[\s\S]*?\n    \}/',
            $source,
            'uninstallCountry must sync Locals and invalidate caches before return'
        );
    }
}
