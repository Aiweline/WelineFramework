<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\Catalog;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\Catalog\DisplayNameCartesianSeeder;
use Weline\I18n\Service\Catalog\LocaleCatalogPackReader;

/**
 * Contract: locale/country display names come from the on-disk gzip pack + DB,
 * not Symfony Intl at runtime. Pack build script is the only Install-time Intl reader.
 */
final class LocaleCatalogPackDbOnlyContractTest extends TestCase
{
    public function testPackReaderAndSeederExistAndForbidSymfonyImports(): void
    {
        $readerFile = (string)(new \ReflectionClass(LocaleCatalogPackReader::class))->getFileName();
        $seederFile = (string)(new \ReflectionClass(DisplayNameCartesianSeeder::class))->getFileName();
        $reader = (string)\file_get_contents($readerFile);
        $seeder = (string)\file_get_contents($seederFile);

        self::assertStringNotContainsString('Symfony\\Component\\Intl', $reader);
        self::assertStringNotContainsString('Symfony\\Component\\Intl', $seeder);
        self::assertStringContainsString('expandForInstalledLocale', $seeder);
        self::assertStringContainsString('seedModuleInstallInventory', $seeder);
        self::assertSame(['zh_Hans_CN', 'en_US'], DisplayNameCartesianSeeder::BASELINE_DISPLAY_LOCALES);
    }

    public function testInstallAndUpgradeWirePackSeeder(): void
    {
        $install = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Setup/Install.php');
        $upgrade = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Setup/Upgrade.php');
        $lifecycle = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php');
        $event = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/etc/event.xml');

        self::assertStringContainsString('DisplayNameCartesianSeeder', $install);
        self::assertStringContainsString('seedModuleInstallInventory', $install);
        self::assertStringContainsString('expandForInstalledLocale', $upgrade);
        self::assertStringContainsString('expandForInstalledLocale', $lifecycle);
        self::assertMatchesRegularExpression(
            '/observer name="Weline_I18n::i18n_locals_upgrade"[^>]*disabled="true"/',
            $event,
            'I18nLocalsUpgrade must stay disabled; Cartesian expand replaces Intl upgrade observer'
        );
    }

    public function testRuntimeHubAndBackendForbidSymfonyIntl(): void
    {
        $paths = [
            \BP . 'app/code/Weline/I18n/Model/I18n.php',
            \BP . 'app/code/Weline/I18n/Taglib/LanguageSelect.php',
            \BP . 'app/code/Weline/I18n/Controller/Backend/Localization.php',
            \BP . 'app/code/Weline/I18n/Controller/Backend/Countries.php',
            \BP . 'app/code/Weline/I18n/Controller/Backend/Countries/Locales.php',
            \BP . 'app/code/Weline/I18n/Controller/Backend/BaseController.php',
            \BP . 'app/code/Weline/I18n/Controller/Backend/Words.php',
            \BP . 'app/code/Weline/I18n/Observer/SystemUpdateObserver.php',
            \BP . 'app/code/Weline/I18n/composer.json',
        ];
        foreach ($paths as $path) {
            $src = (string)\file_get_contents($path);
            self::assertStringNotContainsString(
                'use Symfony\\Component\\Intl',
                $src,
                $path . ' must not import Symfony Intl'
            );
            self::assertDoesNotMatchRegularExpression(
                '/\\\\Symfony\\\\Component\\\\Intl\\\\(Locales|Countries|Languages)::/',
                $src,
                $path . ' must not call Symfony Locales/Countries/Languages'
            );
            self::assertStringNotContainsString(
                'CountryDataUpdateService',
                $src,
                $path . ' must not use obsolete CountryDataUpdateService'
            );
            self::assertStringNotContainsString(
                'CountryUpdateService',
                $src,
                $path . ' must not use obsolete CountryUpdateService'
            );
        }
        self::assertFileDoesNotExist(\BP . 'app/code/Weline/I18n/Service/CountryDataUpdateService.php');
        self::assertFileDoesNotExist(\BP . 'app/code/Weline/I18n/Service/CountryUpdateService.php');
        self::assertFileDoesNotExist(\BP . 'app/code/Weline/I18n/Controller/Backend/Countries/AsyncUpdate.php');
        $countriesIndex = (string)\file_get_contents(
            \BP . 'app/code/Weline/I18n/view/templates/Backend/Countries/index.phtml'
        );
        self::assertStringNotContainsString('更新全球数据', $countriesIndex);
        self::assertStringNotContainsString('country-sync', $countriesIndex);
        $observer = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Observer/SystemUpdateObserver.php');
        self::assertStringContainsString('DisplayNameCartesianSeeder', $observer);
        self::assertStringContainsString('syncInventoryGaps', $observer);
        $composer = \json_decode((string)\file_get_contents(\BP . 'app/code/Weline/I18n/composer.json'), true);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('symfony/intl', $composer['require'] ?? []);
        self::assertSame('^5.2', $composer['require-dev']['symfony/intl'] ?? null);
    }

    public function testPackManifestOnDisk(): void
    {
        $root = \BP . 'app/code/Weline/I18n/data/locale-catalog';
        self::assertFileExists($root . '/MANIFEST.json');
        self::assertFileExists($root . '/locales.tsv.gz');
        self::assertFileExists($root . '/countries.tsv.gz');
        self::assertFileExists($root . '/locale-names.tsv.gz');
        self::assertFileExists($root . '/country-names.tsv.gz');
        $manifest = \json_decode((string)\file_get_contents($root . '/MANIFEST.json'), true);
        self::assertIsArray($manifest);
        $counts = $manifest['counts'] ?? [];
        self::assertGreaterThan(600, (int)($counts['locales'] ?? 0));
        self::assertGreaterThan(200, (int)($counts['countries'] ?? 0));
    }

    public function testInventoryGapFillAndLifecycleAvoidNestedPublish(): void
    {
        $seeder = (string)\file_get_contents(
            (string)(new \ReflectionClass(DisplayNameCartesianSeeder::class))->getFileName()
        );
        $lifecycle = (string)\file_get_contents(
            \BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php'
        );
        $publisher = (string)\file_get_contents(
            \BP . 'app/code/Weline/I18n/Service/I18nResourceChangePublisher.php'
        );
        $upgrade = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Setup/Upgrade.php');
        $reader = (string)\file_get_contents(
            (string)(new \ReflectionClass(LocaleCatalogPackReader::class))->getFileName()
        );

        self::assertStringContainsString('syncInventoryGaps', $seeder);
        self::assertStringContainsString('isset($have[$code])', $seeder);
        self::assertStringNotContainsString('if ($existing > 0) {\n            return;', $seeder);
        self::assertStringContainsString('localeCodesForCountry', $reader);
        // Request-time lifecycle must not fall back to the on-disk pack.
        self::assertStringNotContainsString('LocaleCatalogPackReader', $lifecycle);
        self::assertStringContainsString('地区库存尚未就绪', $lifecycle);
        self::assertStringContainsString('w_msg(', $lifecycle);
        self::assertStringContainsString('i18n_locale_inventory_missing', $lifecycle);
        self::assertStringContainsString('notifyLocaleInventoryMissing', $lifecycle);
        self::assertStringContainsString('assertLocaleInventoryReady', $lifecycle);
        self::assertMatchesRegularExpression(
            '/function activateCountry[\s\S]*?assertLocaleInventoryReady[\s\S]*?runLifecycleMutation/m',
            $lifecycle,
            'w_msg tip must run before lifecycle transaction'
        );
        self::assertStringContainsString('syncCatalogInventoryGaps', $upgrade);
        $i18nModel = (string)\file_get_contents(\BP . 'app/code/Weline/I18n/Model/I18n.php');
        self::assertDoesNotMatchRegularExpression(
            '/function getLocalesForCountry[\s\S]*?LocaleCatalogPackReader/m',
            $i18nModel,
            'getLocalesForCountry must not fall back to LocaleCatalogPackReader'
        );
        self::assertMatchesRegularExpression(
            '/function activateCountry[\s\S]*?runLifecycleMutation[\s\S]*?if \(\$changed\) \{\s*.*?\$this->invalidateLocaleCatalogCaches\(\);/ms',
            $lifecycle
        );
        self::assertStringContainsString('expandDisplayNamesAfterCommit', $lifecycle);
        self::assertSame(
            1,
            preg_match(
                '/private function activateLocaleRecord\(string \$localeCode, bool &\$changed\): array\s*\{(?P<body>.*?)\n    \}\s*\n\s*private function expandDisplayNamesAfterCommit/s',
                $lifecycle,
                $activateLocaleRecordMatch
            ),
            'activateLocaleRecord must be immediately followed by expandDisplayNamesAfterCommit'
        );
        self::assertStringNotContainsString(
            'expandForInstalledLocale',
            (string)($activateLocaleRecordMatch['body'] ?? ''),
            'Cartesian expand must run after lifecycle commit, not inside activateLocaleRecord'
        );
        self::assertStringContainsString('if ($transactions->isActive($connection))', $publisher);
    }
}
