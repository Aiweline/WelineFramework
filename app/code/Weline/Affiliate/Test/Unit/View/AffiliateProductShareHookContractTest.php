<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: buybox collapsed disclosure; guest default share shows social logos (trust).
 */
final class AffiliateProductShareHookContractTest extends TestCase
{
    public function testAfterAddToCartBuyboxDisclosureResolvesFromStorefrontOffer(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml';
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
        self::assertStringContainsString('repeat(4, minmax(0, 1fr))', $src);
        self::assertStringNotContainsString('也可以直接免费分享', $src);
        self::assertStringNotContainsString('data-affiliate-share-free', $src);
        self::assertStringNotContainsString('affiliate-share-panel--gallery', $src);
        self::assertStringNotContainsString('<script>', $src);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Product/frontend/product/detail/after-gallery.phtml'
        );
    }

    public function testProductShareJsWaitsForAccountAndCachesShare(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/affiliate-product-share.js';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('checkFrontendUserLogin', $src);
        self::assertStringContainsString('weline:account:frontend:login', $src);
        self::assertStringContainsString('weline:account:frontend:logout', $src);
        self::assertStringContainsString('syncDefaultPlatformHrefs', $src);
        self::assertStringContainsString('Never wipe SSR default icons', $src);
        self::assertStringContainsString('data-affiliate-default-platform', $src);
        self::assertStringContainsString('20260914-panel-share-url', file_get_contents(dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'));
        self::assertStringContainsString('/\\/product\\//i.test(path)', $src);
        self::assertStringContainsString('getProductShareLinks', $src);
        self::assertStringContainsString('showGuestPromo', $src);
        self::assertStringNotContainsString('freeShareHint', $src);
        self::assertStringNotContainsString('clip: rect(0, 0, 0, 0)', $src);
        self::assertStringContainsString('<svg', $src);
    }

    public function testModulesRegisterAffiliateProductShare(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('affiliateProductShare', $src);
        self::assertStringContainsString('affiliate-product-share.js', $src);
    }

    public function testEarnCommissionIsTranslatedForDefaultWebsiteLocales(): void
    {
        $expected = [
            'zh_Hans_CN' => '赚取佣金',
            'en_US' => 'Earn commission',
            'ar_SA' => 'اربح عمولة',
            'bn_BD' => 'কমিশন উপার্জন করুন',
            'es_ES' => 'Gana comisión',
            'fr_FR' => 'Gagnez une commission',
            'hi_IN' => 'कमीशन कमाएँ',
            'id_ID' => 'Dapatkan komisi',
            'pt_BR' => 'Ganhe comissão',
            'ur_PK' => 'کمیشن کمائیں',
        ];
        $dir = dirname(__DIR__, 3) . '/i18n';
        foreach ($expected as $locale => $translate) {
            $map = $this->loadCsvMap($dir . '/' . $locale . '.csv');
            self::assertSame($translate, $map['赚取佣金'] ?? null, $locale);
            if ($locale !== 'zh_Hans_CN') {
                self::assertNotSame('赚取佣金', $map['赚取佣金'] ?? '赚取佣金', $locale);
            }
        }
    }

    public function testAffiliateBuyboxChromeIsTranslatedForDefaultWebsiteLocales(): void
    {
        $phrases = [
            '去登录' => [
                'zh_Hans_CN' => '去登录',
                'en_US' => 'Sign in',
                'ar_SA' => 'تسجيل الدخول',
                'bn_BD' => 'লগ ইন করুন',
                'es_ES' => 'Iniciar sesión',
                'fr_FR' => 'Se connecter',
                'hi_IN' => 'साइन इन करें',
                'id_ID' => 'Masuk',
                'pt_BR' => 'Entrar',
                'ur_PK' => 'سائن اِن کریں',
            ],
            '复制' => [
                'zh_Hans_CN' => '复制',
                'en_US' => 'Copy',
                'ar_SA' => 'نسخ',
                'bn_BD' => 'কপি',
                'es_ES' => 'Copiar',
                'fr_FR' => 'Copier',
                'hi_IN' => 'कॉपी',
                'id_ID' => 'Salin',
                'pt_BR' => 'Copiar',
                'ur_PK' => 'کاپی',
            ],
            '分享' => [
                'zh_Hans_CN' => '分享',
                'en_US' => 'Share',
                'ar_SA' => 'مشاركة',
                'bn_BD' => 'শেয়ার',
                'es_ES' => 'Compartir',
                'fr_FR' => 'Partager',
                'hi_IN' => 'साझा करें',
                'id_ID' => 'Bagikan',
                'pt_BR' => 'Compartilhar',
                'ur_PK' => 'شیئر',
            ],
        ];
        $dir = dirname(__DIR__, 3) . '/i18n';
        foreach ($phrases as $word => $byLocale) {
            foreach ($byLocale as $locale => $translate) {
                $map = $this->loadCsvMap($dir . '/' . $locale . '.csv');
                self::assertSame($translate, $map[$word] ?? null, $locale . ':' . $word);
                if ($locale !== 'zh_Hans_CN') {
                    self::assertNotSame($word, $map[$word] ?? $word, $locale . ':' . $word);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function loadCsvMap(string $path): array
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
}
