<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Service\FaqSeedCopyCatalog;
use Weline\Faq\Service\FaqSeedLocaleResolver;
use Weline\Faq\Service\FaqTemplatePacks;
use Weline\Faq\Service\FaqTemplateSeedService;

final class FaqWebsiteLocaleSeedContractTest extends TestCase
{
    public function testCatalogCoversDefaultWebsiteLocalesAndFourPacks(): void
    {
        $maintained = FaqSeedCopyCatalog::maintainedLocales();
        self::assertContains(FaqTemplateSeedService::LOCALE_ZH, $maintained);
        self::assertContains(FaqTemplateSeedService::LOCALE_EN, $maintained);
        foreach (['ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK'] as $locale) {
            self::assertContains($locale, $maintained);
        }

        foreach (FaqTemplatePacks::codes() as $pack) {
            $byLocale = FaqSeedCopyCatalog::templatePack($pack);
            foreach ($maintained as $locale) {
                self::assertArrayHasKey($locale, $byLocale, "{$pack}/{$locale}");
                self::assertCount(4, $byLocale[$locale], "{$pack}/{$locale}");
                foreach ($byLocale[$locale] as $row) {
                    self::assertNotSame('', trim((string)($row['faq_key'] ?? '')));
                    self::assertNotSame('', trim((string)($row['question'] ?? '')));
                    self::assertNotSame('', trim((string)($row['answer'] ?? '')));
                }
            }
        }

        foreach ($maintained as $locale) {
            $hub = FaqSeedCopyCatalog::hubForLocale($locale);
            self::assertCount(8, $hub, "hub/{$locale}");
            self::assertNotSame('', trim((string)($hub[0]['q'] ?? '')));
            self::assertNotSame('', trim((string)($hub[0]['a'] ?? '')));
        }
    }

    public function testHindiRetailShippingIsNotEnglishFallbackCopy(): void
    {
        $hi = FaqSeedCopyCatalog::templatePack(FaqTemplatePacks::RETAIL)['hi_IN'] ?? [];
        $shipping = null;
        foreach ($hi as $row) {
            if (($row['faq_key'] ?? '') === 'shipping') {
                $shipping = $row;
                break;
            }
        }
        self::assertNotNull($shipping);
        self::assertSame('डिलीवरी में कितना समय लगता है?', $shipping['question'] ?? null);
        self::assertStringNotContainsString('How long does delivery take?', (string)($shipping['question'] ?? ''));
    }

    public function testRequiredLocalesPreferWebsiteSetIntersectCatalog(): void
    {
        $required = FaqTemplateSeedService::requiredLocales();
        self::assertContains(FaqTemplateSeedService::LOCALE_ZH, $required);
        self::assertContains(FaqTemplateSeedService::LOCALE_EN, $required);
        $maintained = array_fill_keys(FaqSeedCopyCatalog::maintainedLocales(), true);
        foreach ($required as $locale) {
            self::assertArrayHasKey($locale, $maintained);
        }
        // Resolver itself always keeps bilingual baseline.
        $resolved = FaqSeedLocaleResolver::forDefaultWebsite();
        self::assertContains(FaqTemplateSeedService::LOCALE_ZH, $resolved);
        self::assertContains(FaqTemplateSeedService::LOCALE_EN, $resolved);
    }

    public function testModuleVersionAndUpgradeWireWebsiteLocaleSeed(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame('1.0.11', $module['version'] ?? null);
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('FaqTemplateSeedService', $upgrade);
        self::assertStringContainsString('seedSiteHubFaqs', $upgrade);
        $seed = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqTemplateSeedService.php');
        self::assertStringContainsString('FaqSeedCopyCatalog', $seed);
        self::assertStringContainsString('FaqSeedLocaleResolver', $seed);
    }
}
