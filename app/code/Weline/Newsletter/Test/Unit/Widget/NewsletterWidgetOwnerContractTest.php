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
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Newsletter/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;

        $footer = $widgets['footer-newsletter'] ?? [];
        self::assertSame('footer-newsletter', $footer['code'] ?? null);
        self::assertSame(
            'Weline_Newsletter::templates/frontend/widgets/footer-newsletter/default.phtml',
            $footer['template'] ?? null
        );
        self::assertSame('footer-above', $footer['slot'] ?? null);
        self::assertFalse((bool)($footer['exclusive'] ?? true));
        self::assertContains('layout-footer-above', $footer['supports'] ?? []);
        self::assertContains('layout-footer-newsletter', $footer['supports'] ?? []);
        $finj = $footer['default_injections'][0] ?? [];
        self::assertSame('homepage', $finj['layout_type'] ?? null);
        self::assertSame('footer-above', $finj['slot'] ?? null);
        self::assertSame('content', $finj['area'] ?? null);
        self::assertTrue((bool)($finj['required'] ?? false));

        $popup = $widgets['newsletter-popup'] ?? [];
        self::assertSame('newsletter-popup', $popup['code'] ?? null);
        self::assertSame(['*'], $popup['page_layouts'] ?? null);
        self::assertSame(14, (int)(($popup['params']['cookie_days']['default'] ?? 0)));
        self::assertSame('deferred', (string)(($popup['params']['trigger']['default'] ?? '')));
        self::assertSame(15, (int)(($popup['params']['delay_seconds']['default'] ?? 0)));
        self::assertSame(40, (int)(($popup['params']['scroll_percent']['default'] ?? 0)));
        self::assertSame(3, (int)(($popup['params']['min_open_seconds']['default'] ?? 0)));
        $pinj = $popup['default_injections'][0] ?? [];
        self::assertSame('homepage', $pinj['layout_type'] ?? null);
        self::assertSame('content', $pinj['slot'] ?? null);
        self::assertSame('content', $pinj['area'] ?? null);
        self::assertTrue((bool)($pinj['required'] ?? false));
        self::assertGreaterThanOrEqual(900, (int)($pinj['sort_order'] ?? 0));
        self::assertSame('deferred', (string)(($pinj['config']['trigger'] ?? '')));
        self::assertSame(15, (int)(($pinj['config']['delay_seconds'] ?? 0)));
        self::assertSame(40, (int)(($pinj['config']['scroll_percent'] ?? 0)));
        self::assertSame(3, (int)(($pinj['config']['min_open_seconds'] ?? 0)));

        self::assertArrayHasKey('sidebar-newsletter', $widgets);
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
        self::assertStringNotContainsString('fetch(', $footer);

        self::assertStringContainsString('data-testid="newsletter-popup"', $popup);
        self::assertStringContainsString('data-testid="newsletter-popup-form"', $popup);
        self::assertStringContainsString('data-testid="newsletter-subscribe-success"', $popup);
        self::assertStringContainsString('data-testid="newsletter-letter-sheet"', $popup);
        self::assertStringContainsString('newsletter-xinjian-gufeng.webp', $popup);
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
        self::assertStringContainsString('pointer-events: none', $popup);
        self::assertStringContainsString('.is-open', $popup);
    }

    public function testSubscribeJsGuardsScrollLockAndStableCookie(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/newsletter-subscribe.js');
        self::assertStringContainsString('data-newsletter-popup-bound', $js);
        self::assertStringContainsString('data-newsletter-scroll-lock', $js);
        self::assertStringContainsString('unlockNewsletterScroll', $js);
        self::assertStringContainsString("data-widget-code", $js);
        self::assertStringContainsString('weline-newsletter-popup-shown-', $js);
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
