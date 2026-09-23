<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * footer-above 槽在 Footer partial 统一提供，并默认嵌套 Theme trust-badges。
 */
final class FooterAboveTrustBadgesContractTest extends TestCase
{
    public function testFooterPartialDeclaresFooterAboveWithTrustBadgesDefault(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-above"', $src);
        self::assertStringContainsString('accept="layout-footer-above,trust-badges,footer-newsletter,layout-footer-newsletter', $src);
        self::assertStringContainsString('class="weline-footer-above-slot"', $src);
        self::assertStringContainsString('Weline_Theme::frontend::partials::footer::above', $src);
        self::assertStringContainsString('<w:widget type="content" name="trust-badges" params=\'{"preset_badges":["free-shipping","money-back","secure-payment"],"columns":"3","style":"icon-text"}\' />', $src);

        $abovePos = strpos($src, 'id="footer-above"');
        $footerPos = strpos($src, 'id="footer"');
        self::assertNotFalse($abovePos);
        self::assertNotFalse($footerPos);
        self::assertLessThan($footerPos, $abovePos, 'footer-above must precede footer slot');
    }

    public function testTrustBadgesSupportsFooterAboveProtocol(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/content/trust-badges/default.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('layout-footer-above', $src);
    }

    public function testTrustBadgesDefaultPresetsUseInkSealIconsNotModernCircles(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/content/trust-badges/default.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'icon' => 'coin'", $src);
        self::assertStringContainsString("'icon' => 'box'", $src);
        self::assertStringContainsString("'icon' => 'seal'", $src);
        self::assertStringContainsString("满 \$49 包邮", $src);
        self::assertStringNotContainsString("'icon' => 'cash'", $src);
        self::assertStringNotContainsString("'icon' => 'truck'", $src);
        self::assertStringNotContainsString('border-radius: 50%', $src);
        self::assertStringContainsString('border-radius: var(--weline-radius-sm', $src);
        self::assertStringContainsString('weline-font-display', $src);

        $icons = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/Ui/IconRegistry.php');
        self::assertStringContainsString("'coin' =>", $icons);
        self::assertStringContainsString("'seal' =>", $icons);
    }

    public function testHomepageTrustSlotEmbedsCompactTrustBadgesAfterHero(): void
    {
        $homepage = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/layouts/homepage/default.phtml'
        );
        self::assertStringContainsString('id="homepage-trust"', $homepage);
        self::assertStringContainsString('id="homepage-bottom"', $homepage);
        self::assertStringContainsString(
            'layouts::homepage::trust<else/>',
            $homepage
        );
        self::assertStringContainsString(
            '<w:widget type="content" name="trust-badges" params=\'{"preset_badges":["free-shipping","money-back","secure-payment"],"columns":"3","style":"icon-text"}\' />',
            $homepage
        );
        // 信任条须在 Hero 之后、Featured 之前（首屏二折）
        $heroPos = strpos($homepage, 'id="homepage-hero"');
        $trustPos = strpos($homepage, 'id="homepage-trust"');
        $featuredPos = strpos($homepage, 'id="homepage-featured"');
        self::assertNotFalse($heroPos);
        self::assertNotFalse($trustPos);
        self::assertNotFalse($featuredPos);
        self::assertLessThan($trustPos, $heroPos, 'homepage-trust must follow homepage-hero');
        self::assertLessThan($featuredPos, $trustPos, 'homepage-trust must precede homepage-featured');

        $hanfuHomepage = dirname(__DIR__, 4) . '/design/Weline/hanfu/frontend/layouts/homepage/default.phtml';
        if (is_file($hanfuHomepage)) {
            $hanfu = (string)file_get_contents($hanfuHomepage);
            self::assertStringContainsString('id="homepage-trust"', $hanfu);
            self::assertStringContainsString(
                'layouts::homepage::trust<else/>',
                $hanfu
            );
            self::assertStringContainsString(
                'name="trust-badges"',
                $hanfu
            );
            $hHero = strpos($hanfu, 'id="homepage-hero"');
            $hTrust = strpos($hanfu, 'id="homepage-trust"');
            $hFeat = strpos($hanfu, 'id="homepage-featured"');
            self::assertNotFalse($hHero);
            self::assertNotFalse($hTrust);
            self::assertNotFalse($hFeat);
            self::assertLessThan($hTrust, $hHero);
            self::assertLessThan($hFeat, $hTrust);
        }

        $checkout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Checkout/view/theme/frontend/layouts/checkout/default.phtml'
        );
        self::assertStringContainsString('id="checkout-trust"', $checkout);
        self::assertStringContainsString('id="checkout-bottom"', $checkout);
        self::assertStringNotContainsString(
            'layouts::checkout::trust<else/>',
            $checkout
        );
    }

    public function testBlogLayoutDeclaresBlogBottomEmptySlot(): void
    {
        $blog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Blog/view/theme/frontend/layouts/blog/default.phtml'
        );
        self::assertStringContainsString('<w:slot id="blog-bottom"', $blog);
        self::assertStringContainsString('layout-blog-bottom', $blog);
    }

    public function testFooterAboveHookIsRegistered(): void
    {
        $hooks = require dirname(__DIR__, 2) . '/hook.php';
        self::assertArrayHasKey('Weline_Theme::frontend::partials::footer::above', $hooks);
        $doc = dirname(__DIR__, 2) . '/doc/hook/frontend/partials/footer/above.md';
        self::assertFileExists($doc);
    }
}
