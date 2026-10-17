<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllAffiliateViewContractsPass().
 */
final class AffiliateViewContractsTest extends TestCase
{
    public function testAllAffiliateViewContractsPass(): void
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
            'AffiliateAfterAddBuyBoxBag::testAfterAddHookReadsBuyBoxBeforeSharePolicyColdPath' => function () {
            $this->assert_AffiliateAfterAddBuyBoxBag_testAfterAddHookReadsBuyBoxBeforeSharePolicyColdPath();
        },
            'AffiliateProductShareHook::testAfterAddToCartBuyboxDisclosureResolvesFromStorefrontOffer' => function () {
            $this->assert_AffiliateProductShareHook_testAfterAddToCartBuyboxDisclosureResolvesFromStorefrontOffer();
        },
            'AffiliateProductShareHook::testProductShareJsWaitsForAccountAndCachesShare' => function () {
            $this->assert_AffiliateProductShareHook_testProductShareJsWaitsForAccountAndCachesShare();
        },
            'AffiliateProductShareHook::testModulesRegisterAffiliateProductShare' => function () {
            $this->assert_AffiliateProductShareHook_testModulesRegisterAffiliateProductShare();
        },
            'AffiliateProductShareHook::testEarnCommissionIsTranslatedForDefaultWebsiteLocales' => function () {
            $this->assert_AffiliateProductShareHook_testEarnCommissionIsTranslatedForDefaultWebsiteLocales();
        },
            'AffiliateProductShareHook::testAffiliateBuyboxChromeIsTranslatedForDefaultWebsiteLocales' => function () {
            $this->assert_AffiliateProductShareHook_testAffiliateBuyboxChromeIsTranslatedForDefaultWebsiteLocales();
        },
            'FooterPromoteLinkWidget::testFooterPromoteLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterPromoteLinkWidget_testFooterPromoteLinkRegistersDefaultInjection();
        },
            'FooterPromoteLinkWidget::testFooterPromoteLinkTemplatePointsToAffiliate' => function () {
            $this->assert_FooterPromoteLinkWidget_testFooterPromoteLinkTemplatePointsToAffiliate();
        },
        ];
    }

private function assert_AffiliateAfterAddBuyBoxBag_testAfterAddHookReadsBuyBoxBeforeSharePolicyColdPath(): void
{
    $hook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml');
    self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('after_add')", $hook);
    $bagPos = strpos($hook, "StorefrontPdpBuyBoxBag::hasKey('after_add')");
    $policyPos = strpos($hook, 'isProductShareEnabled');
    self::assertNotFalse($bagPos);
    self::assertNotFalse($policyPos);
    self::assertLessThan($policyPos, $bagPos);
    self::assertStringContainsString('$afterAddBag !== null', $hook);
}

private function assert_AffiliateProductShareHook_testAfterAddToCartBuyboxDisclosureResolvesFromStorefrontOffer(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('storefront_offer', $src);
    self::assertStringContainsString('StorefrontOfferResolver', $src);
    self::assertStringContainsString('AffiliateStorefrontPolicy', $src);
    self::assertStringContainsString('data-affiliate-share-root', $src);
    self::assertStringContainsString('affiliate-share-panel--buybox', $src);
    self::assertStringContainsString('affiliate-share-disclosure', $src);
    self::assertStringContainsString('<details', $src);
    self::assertStringContainsString('<summary', $src);
    self::assertStringContainsString('分销分享', $src);
    self::assertStringContainsString('赚取佣金', $src);
    self::assertStringContainsString('data-weline-load="api,account,affiliateProductShare"', $src);
    self::assertStringContainsString('data-login-url', $src);
    self::assertStringContainsString('data-apply-url', $src);
    self::assertStringContainsString('data-free-share-url', $src);
    self::assertStringContainsString('data-affiliate-share-guest', $src);
    self::assertStringContainsString("getUrl('product/'", $src);
    self::assertStringNotContainsString("\$_SERVER['REQUEST_URI']", $src);
    self::assertStringNotContainsString('$_SERVER["REQUEST_URI"]', $src);
    self::assertStringContainsString('登录前往申请分销', $src);
    self::assertStringContainsString('data-affiliate-default-platform', $src);
    self::assertStringContainsString('affiliate-share-platform__logo', $src);
    self::assertStringContainsString('facebook.com/sharer', $src);
    self::assertStringContainsString('<svg', $src);
    self::assertStringContainsString('type="text"', $src);
    // Flat buybox chrome: one disclosure shell — no nested guest card / platform boxes.
    self::assertStringContainsString('no nested promo card', $src);
    self::assertStringContainsString('no per-platform card borders', $src);
    self::assertStringContainsString('background: transparent', $src);
    self::assertStringContainsString('border: 0', $src);
    self::assertStringContainsString('white-space: normal', $src);
    self::assertStringContainsString('overflow-wrap: anywhere', $src);
    self::assertStringContainsString('justify-self: stretch', $src);
    self::assertStringNotContainsString('也可以直接免费分享', $src);
    self::assertStringNotContainsString('data-affiliate-share-free', $src);
    self::assertStringNotContainsString('affiliate-share-panel--gallery', $src);
    self::assertStringNotContainsString('<script>', $src);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/hooks/Weline_Product/frontend/product/detail/after-gallery.phtml');
}

private function assert_AffiliateProductShareHook_testProductShareJsWaitsForAccountAndCachesShare(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/statics/js/affiliate-product-share.js';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('checkFrontendUserLogin', $src);
    self::assertStringContainsString('weline:account:frontend:login', $src);
    self::assertStringContainsString('weline:account:frontend:logout', $src);
    self::assertStringContainsString('syncDefaultPlatformHrefs', $src);
    self::assertStringContainsString('Never wipe SSR default icons', $src);
    self::assertStringContainsString('data-affiliate-default-platform', $src);
    self::assertStringContainsString('20260914-panel-share-url', file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/statics/frontend/weline.modules.js'));
    self::assertStringContainsString('/\/product\//i.test(path)', $src);
    self::assertStringContainsString('getProductShareLinks', $src);
    self::assertStringContainsString('showGuestPromo', $src);
    self::assertStringNotContainsString('freeShareHint', $src);
    self::assertStringNotContainsString('clip: rect(0, 0, 0, 0)', $src);
    self::assertStringContainsString('<svg', $src);
}

private function assert_AffiliateProductShareHook_testModulesRegisterAffiliateProductShare(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/statics/frontend/weline.modules.js';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('affiliateProductShare', $src);
    self::assertStringContainsString('affiliate-product-share.js', $src);
}

private function assert_AffiliateProductShareHook_testEarnCommissionIsTranslatedForDefaultWebsiteLocales(): void
{
    $expected = ['zh_Hans_CN' => '赚取佣金', 'en_US' => 'Earn commission', 'ar_SA' => 'اربح عمولة', 'bn_BD' => 'কমিশন উপার্জন করুন', 'es_ES' => 'Gana comisión', 'fr_FR' => 'Gagnez une commission', 'hi_IN' => 'कमीशन कमाएँ', 'id_ID' => 'Dapatkan komisi', 'pt_BR' => 'Ganhe comissão', 'ur_PK' => 'کمیشن کمائیں'];
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/i18n';
    foreach ($expected as $locale => $translate) {
        $map = $this->loadCsvMap_AffiliateProductShareHook($dir . '/' . $locale . '.csv');
        self::assertSame($translate, $map['赚取佣金'] ?? null, $locale);
        if ($locale !== 'zh_Hans_CN') {
            self::assertNotSame('赚取佣金', $map['赚取佣金'] ?? '赚取佣金', $locale);
        }
    }
}

private function assert_AffiliateProductShareHook_testAffiliateBuyboxChromeIsTranslatedForDefaultWebsiteLocales(): void
{
    $phrases = ['去登录' => ['zh_Hans_CN' => '去登录', 'en_US' => 'Sign in', 'ar_SA' => 'تسجيل الدخول', 'bn_BD' => 'লগ ইন করুন', 'es_ES' => 'Iniciar sesión', 'fr_FR' => 'Se connecter', 'hi_IN' => 'साइन इन करें', 'id_ID' => 'Masuk', 'pt_BR' => 'Entrar', 'ur_PK' => 'سائن اِن کریں'], '复制' => ['zh_Hans_CN' => '复制', 'en_US' => 'Copy', 'ar_SA' => 'نسخ', 'bn_BD' => 'কপি', 'es_ES' => 'Copiar', 'fr_FR' => 'Copier', 'hi_IN' => 'कॉपी', 'id_ID' => 'Salin', 'pt_BR' => 'Copiar', 'ur_PK' => 'کاپی'], '分享' => ['zh_Hans_CN' => '分享', 'en_US' => 'Share', 'ar_SA' => 'مشاركة', 'bn_BD' => 'শেয়ার', 'es_ES' => 'Compartir', 'fr_FR' => 'Partager', 'hi_IN' => 'साझा करें', 'id_ID' => 'Bagikan', 'pt_BR' => 'Compartilhar', 'ur_PK' => 'شیئر']];
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/i18n';
    foreach ($phrases as $word => $byLocale) {
        foreach ($byLocale as $locale => $translate) {
            $map = $this->loadCsvMap_AffiliateProductShareHook($dir . '/' . $locale . '.csv');
            self::assertSame($translate, $map[$word] ?? null, $locale . ':' . $word);
            if ($locale !== 'zh_Hans_CN') {
                self::assertNotSame($word, $map[$word] ?? $word, $locale . ':' . $word);
            }
        }
    }
}

/** @return array<string, string> */
private function loadCsvMap_AffiliateProductShareHook(string $path): array
{
    self::assertFileExists($path);
    $fh = fopen($path, 'rb');
    self::assertNotFalse($fh);
    $map = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[0], $row[1])) {
            continue;
        }
        $map[(string) $row[0]] = (string) $row[1];
    }
    fclose($fh);
    return $map;
}

private function assert_FooterPromoteLinkWidget_testFooterPromoteLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/extends/module/Weline_Widget/Weline_Affiliate/widget.php';
    $tpl = 'Weline_Affiliate::templates/frontend/widgets/footer-promote-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/templates/frontend/widgets/footer-promote-link.phtml');
    self::assertStringContainsString('@widget.code {footer-promote-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-partner-links}', $src);
    self::assertStringContainsString('"slot":"footer-partner-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterPromoteLinkWidget_testFooterPromoteLinkTemplatePointsToAffiliate(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/view/templates/frontend/widgets/footer-promote-link.phtml');
    self::assertStringContainsString('data-testid="footer-promote-link"', $template);
    self::assertStringContainsString("@url{'affiliate'}", $template);
    self::assertStringContainsString('footer-partner-links', $template);
    self::assertStringContainsString('我要推广', $template);
}
}
