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
        self::assertStringContainsString('accept="layout-footer-above,trust-badges', $src);
        self::assertStringContainsString('class="weline-footer-above-slot"', $src);
        self::assertStringContainsString('Weline_Theme::frontend::partials::footer::above', $src);
        self::assertStringContainsString('<w:widget type="content" name="trust-badges" />', $src);

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

    public function testHomepageTrustSlotEmbedsTrustBadgesElseFallback(): void
    {
        $homepage = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/layouts/homepage/default.phtml'
        );
        self::assertStringContainsString('id="homepage-trust"', $homepage);
        self::assertStringContainsString('id="homepage-bottom"', $homepage);
        self::assertStringContainsString(
            'layouts::homepage::trust',
            $homepage
        );
        self::assertStringContainsString(
            '<w:widget type="content" name="trust-badges" />',
            $homepage
        );

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
