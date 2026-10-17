<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Service\FaqHubContent;
use Weline\Faq\Service\FaqSeedCopyCatalog;
use Weline\Faq\Service\FaqSeedLocaleResolver;
use Weline\Faq\Service\FaqTemplatePacks;
use Weline\Faq\Service\FaqTemplateSeedService;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllFaqServiceContractsPass().
 */
final class FaqServiceContractsTest extends TestCase
{
    public function testAllFaqServiceContractsPass(): void
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
            'FaqHubContentDbPrefer::testFaqsForLocaleSourcePrefersDbLoaderOverCatalogOnly' => function () {
            $this->assert_FaqHubContentDbPrefer_testFaqsForLocaleSourcePrefersDbLoaderOverCatalogOnly();
        },
            'FaqHubContentDbPrefer::testCatalogStillHasEightHubRowsForMaintainedLocales' => function () {
            $this->assert_FaqHubContentDbPrefer_testCatalogStillHasEightHubRowsForMaintainedLocales();
        },
            'FaqHubContentDbPrefer::testHubContentClassIsFinalAndWired' => function () {
            $this->assert_FaqHubContentDbPrefer_testHubContentClassIsFinalAndWired();
        },
            'FaqPdpConfigSeed::testSystemConfigTemplateDeclaresScopeAllowedFields' => function () {
            $this->assert_FaqPdpConfigSeed_testSystemConfigTemplateDeclaresScopeAllowedFields();
        },
            'FaqPdpConfigSeed::testTemplateSeedServiceHasFourPacks' => function () {
            $this->assert_FaqPdpConfigSeed_testTemplateSeedServiceHasFourPacks();
        },
            'FaqPdpConfigSeed::testUpgradeAndModuleWireSeedsAndSystemConfigOptional' => function () {
            $this->assert_FaqPdpConfigSeed_testUpgradeAndModuleWireSeedsAndSystemConfigOptional();
        },
            'FaqPdpConfigSeed::testSearchSkipsTemplateType' => function () {
            $this->assert_FaqPdpConfigSeed_testSearchSkipsTemplateType();
        },
            'FaqPdpWidgetSeo::testWidgetUsesResolverNotListForEntityProduct' => function () {
            $this->assert_FaqPdpWidgetSeo_testWidgetUsesResolverNotListForEntityProduct();
        },
            'FaqPdpWidgetSeo::testSeoProviderUsesSeoFaqsForPdp' => function () {
            $this->assert_FaqPdpWidgetSeo_testSeoProviderUsesSeoFaqsForPdp();
        },
            'FaqPdpWidgetSeo::testBackendItemEditHasScopeAndFaqKey' => function () {
            $this->assert_FaqPdpWidgetSeo_testBackendItemEditHasScopeAndFaqKey();
        },
            'FaqWebsiteLocaleSeed::testCatalogCoversDefaultWebsiteLocalesAndFourPacks' => function () {
            $this->assert_FaqWebsiteLocaleSeed_testCatalogCoversDefaultWebsiteLocalesAndFourPacks();
        },
            'FaqWebsiteLocaleSeed::testHindiRetailShippingIsNotEnglishFallbackCopy' => function () {
            $this->assert_FaqWebsiteLocaleSeed_testHindiRetailShippingIsNotEnglishFallbackCopy();
        },
            'FaqWebsiteLocaleSeed::testRequiredLocalesPreferWebsiteSetIntersectCatalog' => function () {
            $this->assert_FaqWebsiteLocaleSeed_testRequiredLocalesPreferWebsiteSetIntersectCatalog();
        },
            'FaqWebsiteLocaleSeed::testModuleVersionAndUpgradeWireWebsiteLocaleSeed' => function () {
            $this->assert_FaqWebsiteLocaleSeed_testModuleVersionAndUpgradeWireWebsiteLocaleSeed();
        },
            'FaqWebsiteLocaleSeed::testRetailReturnsAndHub0UseComplianceCopyNotAbsoluteSevenDays' => function () {
            $this->assert_FaqWebsiteLocaleSeed_testRetailReturnsAndHub0UseComplianceCopyNotAbsoluteSevenDays();
        },
        ];
    }

private function assert_FaqHubContentDbPrefer_testFaqsForLocaleSourcePrefersDbLoaderOverCatalogOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Service/FaqHubContent.php');
    self::assertStringContainsString('loadSiteHubFromDb', $src);
    self::assertStringContainsString('listForEntity', $src);
    self::assertStringContainsString('SiteFaqTypeProvider::ENTITY_UUID', $src);
    // Catalog remains fallback when DB empty — but must not be the only path.
    self::assertStringContainsString('FaqSeedCopyCatalog::hubForLocale', $src);
    self::assertLessThan(strpos($src, 'FaqSeedCopyCatalog::hubForLocale') ?: PHP_INT_MAX, strpos($src, 'loadSiteHubFromDb') ?: PHP_INT_MAX);
}

private function assert_FaqHubContentDbPrefer_testCatalogStillHasEightHubRowsForMaintainedLocales(): void
{
    foreach (FaqSeedCopyCatalog::maintainedLocales() as $locale) {
        self::assertCount(8, FaqSeedCopyCatalog::hubForLocale($locale), $locale);
    }
}

private function assert_FaqHubContentDbPrefer_testHubContentClassIsFinalAndWired(): void
{
    $ref = new \ReflectionClass(FaqHubContent::class);
    self::assertTrue($ref->isFinal());
    self::assertTrue($ref->hasMethod('faqsForLocale'));
    self::assertTrue($ref->hasMethod('faqs'));
}

private function assert_FaqPdpConfigSeed_testSystemConfigTemplateDeclaresScopeAllowedFields(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/extends/module/Weline_SystemConfig/Config/frontend/faq-pdp.phtml';
    self::assertFileExists($path);
    $body = (string) file_get_contents($path);
    self::assertStringContainsString('faq/pdp/merge_enabled', $body);
    self::assertStringContainsString('faq/pdp/active_pack', $body);
    self::assertStringContainsString('scope="website,store,channel"', $body);
    self::assertStringContainsString('retail:标准零售,cross_border:跨境,virtual:虚拟商品,b2b:B2B', $body);
    foreach (FaqTemplatePacks::codes() as $code) {
        self::assertStringContainsString($code, $body);
    }
}

private function assert_FaqPdpConfigSeed_testTemplateSeedServiceHasFourPacks(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Service/FaqTemplateSeedService.php');
    foreach (['FaqTemplatePacks', 'FaqSeedCopyCatalog', 'FaqSeedLocaleResolver', 'TYPE_CODE', 'zh_Hans_CN', 'en_US'] as $needle) {
        self::assertStringContainsString($needle, $source);
    }
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Service/FaqSeedCopyCatalog.php');
    foreach (['RETAIL', 'CROSS_BORDER', 'VIRTUAL', 'B2B', 'shipping', 'faq_key', 'How long does delivery take?', 'كم يستغرق التوصيل؟', 'hi_IN', 'ar_SA'] as $needle) {
        self::assertStringContainsString($needle, $catalog);
    }
}

private function assert_FaqPdpConfigSeed_testUpgradeAndModuleWireSeedsAndSystemConfigOptional(): void
{
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Setup/Upgrade.php');
    self::assertStringContainsString('FaqTemplateSeedService', $upgrade);
    self::assertStringContainsString('migrateEmptyLocaleToZhHans', $upgrade);
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/etc/module.php';
    self::assertSame('1.0.11', $module['version'] ?? null);
    self::assertArrayHasKey('Weline_SystemConfig', $module['optional'] ?? []);
}

private function assert_FaqPdpConfigSeed_testSearchSkipsTemplateType(): void
{
    $builder = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Service/FaqSearchIndexDocumentBuilder.php');
    self::assertStringContainsString("typeCode === 'template'", $builder);
    self::assertStringContainsString('return null', $builder);
}

private function assert_FaqPdpWidgetSeo_testWidgetUsesResolverNotListForEntityProduct(): void
{
    $widget = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/view/templates/frontend/widgets/product-faq.phtml');
    self::assertStringContainsString('FaqPdpResolveService', $widget);
    self::assertStringContainsString('resolveForPdp', $widget);
    self::assertStringNotContainsString("listForEntity('product'", $widget);
    self::assertStringContainsString('weline-product-faq__source', $widget);
    self::assertStringContainsString('wc-theme_widget_product_faq', $widget);
    self::assertStringContainsString('--weline-theme-', $widget);
    self::assertStringContainsString('--color-text', $widget);
}

private function assert_FaqPdpWidgetSeo_testSeoProviderUsesSeoFaqsForPdp(): void
{
    $seo = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/extends/module/Weline_Seo/SeoProfileProvider/ProductFaqSeoProfileProvider.php');
    self::assertStringContainsString('seoFaqsForPdp', $seo);
    self::assertStringNotContainsString("seoFaqsForEntity('product'", $seo);
    $api = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Api/FaqSeoFactsInterface.php');
    self::assertStringContainsString('seoFaqsForPdp', $api);
}

private function assert_FaqPdpWidgetSeo_testBackendItemEditHasScopeAndFaqKey(): void
{
    $edit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/view/templates/backend/Item/edit.phtml');
    self::assertStringContainsString('store_code', $edit);
    self::assertStringContainsString('channel_code', $edit);
    self::assertStringContainsString('faq_key', $edit);
    self::assertStringContainsString('抑制', $edit);
    $item = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Model/FaqItem.php');
    self::assertStringContainsString('idx_faq_item_scope_key_unique', $item);
    self::assertStringContainsString('UNIQUE', $item);
    self::assertStringContainsString('schema_fields_FAQ_KEY', $item);
}

private function assert_FaqWebsiteLocaleSeed_testCatalogCoversDefaultWebsiteLocalesAndFourPacks(): void
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
                self::assertNotSame('', trim((string) ($row['faq_key'] ?? '')));
                self::assertNotSame('', trim((string) ($row['question'] ?? '')));
                self::assertNotSame('', trim((string) ($row['answer'] ?? '')));
            }
        }
    }
    foreach ($maintained as $locale) {
        $hub = FaqSeedCopyCatalog::hubForLocale($locale);
        self::assertCount(8, $hub, "hub/{$locale}");
        self::assertNotSame('', trim((string) ($hub[0]['q'] ?? '')));
        self::assertNotSame('', trim((string) ($hub[0]['a'] ?? '')));
    }
}

private function assert_FaqWebsiteLocaleSeed_testHindiRetailShippingIsNotEnglishFallbackCopy(): void
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
    self::assertStringNotContainsString('How long does delivery take?', (string) ($shipping['question'] ?? ''));
}

private function assert_FaqWebsiteLocaleSeed_testRequiredLocalesPreferWebsiteSetIntersectCatalog(): void
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

private function assert_FaqWebsiteLocaleSeed_testModuleVersionAndUpgradeWireWebsiteLocaleSeed(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/etc/module.php';
    self::assertSame('1.0.18', $module['version'] ?? null);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Setup/Upgrade.php');
    self::assertStringContainsString('FaqTemplateSeedService', $upgrade);
    self::assertStringContainsString('seedSiteHubFaqs', $upgrade);
    $seed = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Service/FaqTemplateSeedService.php');
    self::assertStringContainsString('FaqSeedCopyCatalog', $seed);
    self::assertStringContainsString('FaqSeedLocaleResolver', $seed);
}

private function assert_FaqWebsiteLocaleSeed_testRetailReturnsAndHub0UseComplianceCopyNotAbsoluteSevenDays(): void
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
        $answer = (string) ($returns['answer'] ?? '');
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
        $hub0 = (string) ($hub[0]['a'] ?? '');
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
