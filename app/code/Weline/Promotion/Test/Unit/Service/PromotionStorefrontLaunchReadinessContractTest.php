<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontLaunchReadinessContractTest extends TestCase
{
    public function testStorefrontShelfUsesRealCatalogProductsWithoutAcceptancePlaceholders(): void
    {
        $servicePath = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        $templatePath = dirname(__DIR__, 3) . '/view/templates/frontend/promotion/index.phtml';
        self::assertFileExists($servicePath);
        self::assertFileExists($templatePath);

        $service = (string) file_get_contents($servicePath);
        $template = (string) file_get_contents($templatePath);

        self::assertStringContainsString('$this->resolveCatalog()', $service);
        self::assertStringContainsString('class_exists(StorefrontCatalogViewService::class)', $service);
        self::assertStringContainsString('ObjectManager::getInstance(StorefrontCatalogViewService::class)', $service);
        self::assertStringContainsString('$catalog->publishedOfferSummaries(', $service);
        self::assertStringContainsString("\$item['slug']", $service);
        self::assertStringContainsString("\$item['unit_price_minor']", $service);
        self::assertStringContainsString('StorefrontOfferDetailQuery::params', $service);
        self::assertStringContainsString('http_build_query($detailQuery)', $service);
        self::assertStringNotContainsString('stubProducts(', $service);
        self::assertStringNotContainsString('示例商品 A', $service);
        self::assertStringNotContainsString('data:image/svg+xml', $template);
        self::assertStringNotContainsString('<span class="price-current">¥', $template);
    }

    public function testBuiltInThemeCopyResolvesTheSpecificLocaleBeforeBaseRowFallback(): void
    {
        $servicePath = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        self::assertFileExists($servicePath);
        $service = (string) file_get_contents($servicePath);

        self::assertStringContainsString('Cookie::getLangLocal() ?: Cookie::getLang()', $service);
        self::assertStringContainsString('private function isBuiltInThemeSlug(string $pageSlug): bool', $service);
        self::assertStringContainsString("['deals', 'sale', 'weekend', 'wedding']", $service);
        self::assertStringContainsString('private function isCopyCompatibleWithLocale(string $value, string $locale): bool', $service);
        self::assertStringContainsString("preg_match('/\\p{Han}/u'", $service);
        self::assertStringContainsString("preg_match('/\\p{Arabic}/u'", $service);
    }

    public function testStorefrontPromotionCopyShipsEnglishAndArabicDictionaries(): void
    {
        $modulePath = dirname(__DIR__, 3);
        $englishPath = $modulePath . '/i18n/en_US.csv';
        $arabicPath = $modulePath . '/i18n/ar_SA.csv';
        self::assertFileExists($englishPath);
        self::assertFileExists($arabicPath);

        $english = (string) file_get_contents($englishPath);
        $arabic = (string) file_get_contents($arabicPath);
        foreach ([
            '活动首页',
            '今日特价',
            '今日特价专场',
            '看今日特价',
            '节令主题',
            '出游常服',
            '婚嫁礼服',
            '浏览活动商品',
            '浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。',
            '匹配商品',
            '活动入口',
            '活动商品',
            '查看商品',
            '围绕踏青、市集与日常出游，陈列常服套装与轻便搭配，只展示真实可售商品，不虚构折扣。',
            '围绕婚礼、订婚与敬酒仪式，陈列嫁衣与礼服套装，只展示真实成交价，不虚构折扣。',
        ] as $key) {
            self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ',(?!' . preg_quote($key, '/') . '$).+/m', $english);
            self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ',(?!' . preg_quote($key, '/') . '$).+/m', $arabic);
        }

        self::assertDoesNotMatchRegularExpression(
            '/^匹配商品,匹配商品$/m',
            $english,
        );
        self::assertMatchesRegularExpression(
            '/^匹配商品,"Matching products"$/m',
            $english,
        );
    }

    public function testPromotionTemplateDoesNotNestAMainLandmarkInsideTheThemeLayout(): void
    {
        $templatePath = dirname(__DIR__, 3) . '/view/templates/frontend/promotion/index.phtml';
        self::assertFileExists($templatePath);
        $template = (string) file_get_contents($templatePath);

        self::assertStringContainsString(
            '<div class="w-promotion-page weline-product-card-shelf"',
            $template,
        );
        self::assertStringNotContainsString(
            '<main class="w-promotion-page weline-product-card-shelf"',
            $template,
        );
        self::assertStringContainsString('<w:product:card', $template);
        self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $template);
        self::assertStringNotContainsString('amazon-product-card.css', $template);
    }

    public function testPromotionHeroMirrorsItsImageFocusForRtlLocales(): void
    {
        $cssPath = dirname(__DIR__, 3) . '/view/statics/css/promotion-hub.css';
        self::assertFileExists($cssPath);
        $css = (string) file_get_contents($cssPath);

        self::assertStringContainsString('--promo-hero-gradient-angle: 90deg;', $css);
        self::assertStringContainsString('--promo-hero-focus-x: 72%;', $css);
        self::assertStringContainsString('[dir="rtl"] .w-promotion-page', $css);
        self::assertStringContainsString('--promo-hero-gradient-angle: 270deg;', $css);
        self::assertStringContainsString('--promo-hero-focus-x: 28%;', $css);
        self::assertStringContainsString('linear-gradient(var(--promo-hero-gradient-angle)', $css);
    }
}
