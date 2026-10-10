<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * D9/D10：Newsletter 唯一拥有 footer-newsletter / newsletter-popup（及 sidebar）。
 */
final class NewsletterWidgetOwnerContractTest extends TestCase
{
    public function testWidgetPhpRegistersFooterPopupWithRequiredInjections(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Newsletter/widget.php';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Newsletter::templates/frontend/widgets/footer-newsletter/default.phtml'
        ));
        $footer = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-newsletter/default.phtml'
        );
        self::assertStringContainsString('@widget.code {footer-newsletter}', $footer);
        self::assertStringContainsString('@widget.default_injections', $footer);
        self::assertStringContainsString('"required":true', $footer);

        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Newsletter::templates/frontend/widgets/newsletter-popup/default.phtml'
        ));
        $popup = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/newsletter-popup/default.phtml'
        );
        self::assertStringContainsString('@widget.code {newsletter-popup}', $popup);
        self::assertStringContainsString('@widget.default_injections {[]}', $popup);

        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate(
            $widgetPhp,
            'Weline_Newsletter::templates/frontend/widgets/sidebar-newsletter/default.phtml'
        ));
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/sidebar-newsletter/default.phtml'
        );
        self::assertStringContainsString('@widget.code {sidebar-newsletter}', $sidebar);
    }

    public function testTemplatesExposeStableTestIdsAndTopicsDefaultChecked(): void
    {
        $base = dirname(__DIR__, 3) . '/view/templates/frontend/widgets';
        $footer = (string)file_get_contents($base . '/footer-newsletter/default.phtml');
        $popup = (string)file_get_contents($base . '/newsletter-popup/default.phtml');

        self::assertStringContainsString('data-testid="newsletter-footer"', $footer);
        self::assertStringContainsString('data-testid="newsletter-footer-form"', $footer);
        self::assertStringContainsString('data-testid="newsletter-subscribe-success"', $footer);
        self::assertStringContainsString('data-widget-code="footer-newsletter"', $footer);
        self::assertStringContainsString('data-weline-load="newsletterSubscribe"', $footer);
        self::assertStringContainsString('name="topic_promo"', $footer);
        self::assertStringContainsString('name="topic_new_arrivals"', $footer);
        self::assertStringContainsString('checked', $footer);
        self::assertStringContainsString('可随时退订', $footer);
        self::assertStringContainsString("@url{'newsletter/subscribe'}", $footer);
        self::assertStringContainsString('enable_popup', $footer);
        self::assertStringContainsString(
            "Weline_Newsletter::templates/frontend/widgets/newsletter-popup/default.phtml",
            $footer
        );
        self::assertStringContainsString('->fetch(', $footer);

        self::assertStringContainsString('data-testid="newsletter-popup"', $popup);
        self::assertStringContainsString('data-testid="newsletter-popup-form"', $popup);
        self::assertStringContainsString('data-testid="newsletter-subscribe-success"', $popup);
        self::assertStringContainsString('data-testid="newsletter-letter-sheet"', $popup);
        self::assertStringContainsString('newsletter-xinjian-gufeng.webp', $popup);
        self::assertStringContainsString(
            "Weline_Newsletter::images/newsletter-xinjian-gufeng.webp",
            $popup
        );
        self::assertStringContainsString('fetchTagSource', $popup);
        self::assertStringContainsString('dir_type_STATICS', $popup);
        self::assertStringNotContainsString('/Weline/Newsletter/view/statics/', $popup);
        self::assertStringNotContainsString('newsletter-popup-mist-bg', $popup);
        self::assertStringNotContainsString('newsletter-letter-envelope-shell', $popup);
        self::assertStringContainsString('letter-shell', $popup);
        self::assertStringContainsString('data-cookie-days', $popup);
        self::assertStringContainsString('data-min-open', $popup);
        self::assertStringContainsString("getData('cookie_days') ?? 14", $popup);
        self::assertStringContainsString("getData('trigger') ?? 'deferred'", $popup);
        self::assertStringContainsString("getData('delay_seconds') ?? 15", $popup);
        self::assertStringContainsString("getData('scroll_percent') ?? 40", $popup);
        self::assertStringContainsString('可随时退订', $popup);
        self::assertStringContainsString('data-weline-load="newsletterSubscribe"', $popup);
        self::assertStringContainsString('is-open', $popup);
        $popupCss = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/css/widgets/widget-newsletter-popup-default.css');
        self::assertStringContainsString('pointer-events', $popupCss);
        self::assertStringContainsString('.is-open', $popupCss);
    }

    public function testSubscribeJsGuardsScrollLockAndStableCookie(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/newsletter-subscribe.js');
        self::assertStringContainsString('data-newsletter-popup-bound', $js);
        self::assertStringContainsString('mountPopupToBody', $js);
        self::assertStringContainsString('data-newsletter-popup-mounted', $js);
        self::assertStringContainsString('data-newsletter-scroll-lock', $js);
        self::assertStringContainsString('unlockNewsletterScroll', $js);
        self::assertStringContainsString("data-widget-code", $js);
        self::assertStringContainsString('weline-newsletter-popup-shown-', $js);
        self::assertStringContainsString('cookieSiteKey', $js);
        self::assertStringContainsString('cookiePath', $js);
        /* 关闭时立即解锁，禁止仅靠 420ms 延时才清 overflow */
        self::assertMatchesRegularExpression(
            '/function hidePopup\\(\\)[\\s\\S]*?unlockNewsletterScroll\\(\\)[\\s\\S]*?setTimeout/',
            $js
        );
    }

    public function testLetterStationeryPngAssetsExist(): void
    {
        $images = dirname(__DIR__, 3) . '/view/statics/images';
        self::assertFileExists($images . '/newsletter-xinjian-gufeng.webp');
        self::assertGreaterThan(5_000, filesize($images . '/newsletter-xinjian-gufeng.webp'));
        self::assertLessThan(200_000, filesize($images . '/newsletter-xinjian-gufeng.webp'));
    }

    public function testThemeShellNoLongerRegistersNewsletterCodes(): void
    {
        $themeWidget = dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($themeWidget);
        $src = (string)file_get_contents($themeWidget);
        self::assertStringNotContainsString(
            'widgets/newsletter/footer-newsletter/default.phtml',
            $src
        );
        self::assertStringNotContainsString(
            'widgets/newsletter/newsletter-popup/default.phtml',
            $src
        );
        self::assertStringNotContainsString(
            'widgets/sidebar/sidebar-newsletter/default.phtml',
            $src
        );

        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/newsletter/footer-newsletter/default.phtml'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/newsletter/newsletter-popup/default.phtml'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/sidebar/sidebar-newsletter/default.phtml'
        );

        $composer = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/Helper/FooterPartialComposer.php'
        );
        self::assertStringNotContainsString(
            'Weline_Theme::theme/frontend/widgets/newsletter/footer-newsletter',
            $composer
        );
        self::assertStringContainsString('return \'\'', $composer);
    }

    public function testGeneratedRegistryUniqueOwnerIsNewsletter(): void
    {
        // Widget → Unit → Test → Newsletter → Weline → code → app → repo root
        $generated = dirname(__DIR__, 7) . '/generated/widgets.php';
        if (!is_file($generated)) {
            self::markTestSkipped('generated/widgets.php missing; run php bin/w widget:refresh');
        }
        $src = (string)file_get_contents($generated);

        foreach (['footer-newsletter', 'newsletter-popup', 'sidebar-newsletter'] as $code) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($code, '/') . "'\\s*=>\\s*array\\s*\\([\\s\\S]*?'module'\\s*=>\\s*'Weline_Newsletter'/",
                $src,
                "expected unique owner Weline_Newsletter for {$code} in generated/widgets.php"
            );
            self::assertStringContainsString(
                "Weline_Newsletter::templates/frontend/widgets/{$code}/default.phtml",
                $src
            );
        }

        self::assertStringNotContainsString(
            'Weline_Theme::theme/frontend/widgets/newsletter/footer-newsletter/default.phtml',
            $src
        );
        self::assertStringNotContainsString(
            'Weline_Theme::theme/frontend/widgets/newsletter/newsletter-popup/default.phtml',
            $src
        );
        self::assertStringNotContainsString(
            'Weline_Theme::theme/frontend/widgets/sidebar/sidebar-newsletter/default.phtml',
            $src
        );
    }

    public function testFrontendJsUsesBinQueryNotNativeFetch(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/newsletter-subscribe.js'
        );
        self::assertStringContainsString("Weline.Api.resource('newsletter')", $js);
        self::assertStringContainsString('client.subscribe', $js);
        self::assertStringNotContainsString('fetch(', $js);
        self::assertStringNotContainsString('XMLHttpRequest', $js);
        self::assertStringContainsString('cookieDays', $js);
        self::assertStringContainsString('requestShow', $js);
        self::assertStringContainsString('minOpenMs', $js);
        self::assertStringContainsString('deferred', $js);
        self::assertStringContainsString('bindExitTrigger', $js);
        self::assertStringContainsString('bindScrollTrigger', $js);
    }
}
