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
        self::assertSame('1.0.18', $module['version'] ?? null);
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('FaqTemplateSeedService', $upgrade);
        self::assertStringContainsString('seedSiteHubFaqs', $upgrade);
        $seed = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqTemplateSeedService.php');
        self::assertStringContainsString('FaqSeedCopyCatalog', $seed);
        self::assertStringContainsString('FaqSeedLocaleResolver', $seed);
    }

    public function testRetailReturnsAndHub0UseComplianceCopyNotAbsoluteSevenDays(): void
    {
        $retail = FaqSeedCopyCatalog::templatePack(FaqTemplatePacks::RETAIL);
        foreach (FaqSeedCopyCatalog::maintainedLocales() as $locale) {
            $returns = null;
            foreach ($retail[$locale] ?? [] as $row) {
                if (($row['faq_key'] ?? '') === 'returns') {
                    $returns = $row;
                    break;
                }
            }
            self::assertNotNull($returns, "returns/{$locale}");
            $answer = (string)($returns['answer'] ?? '');
            self::assertStringNotContainsString('签收后 7 日内', $answer);
            self::assertDoesNotMatchRegularExpression('/within 7 days of delivery/i', $answer);
            self::assertDoesNotMatchRegularExpression('/durante 7 días|sous 7 jours|dalam 7 hari|em até 7 dias|7 أيام من الاستلام|৭ দিনের মধ্যে|7 दिनों के भीतर|7 دنوں کے اندر/u', $answer);
            if ($locale === FaqTemplateSeedService::LOCALE_ZH) {
                self::assertStringContainsString('欧盟/EEA', $answer);
                self::assertStringContainsString('退换政策', $answer);
            }
            if ($locale === FaqTemplateSeedService::LOCALE_EN) {
                self::assertStringContainsString('EU/EEA', $answer);
                self::assertStringContainsString('Returns Policy', $answer);
            }

            $hub = FaqSeedCopyCatalog::hubForLocale($locale);
            $hub0 = (string)($hub[0]['a'] ?? '');
            self::assertStringNotContainsString('1–3', $hub0);
            self::assertDoesNotMatchRegularExpression('/1\s*[–-]\s*3\s*(business|work|días|jours|hari|dias|أيام|কর্মদিবস|कार्यदिवस|دنوں)/iu', $hub0);
            if ($locale === FaqTemplateSeedService::LOCALE_ZH) {
                self::assertStringContainsString('1–5', $hub0);
                self::assertStringContainsString('7–25', $hub0);
            }
            if ($locale === FaqTemplateSeedService::LOCALE_EN) {
                self::assertStringContainsString('1–5 business days', $hub0);
                self::assertStringContainsString('7–25 business days', $hub0);
            }
        }
    }
}
