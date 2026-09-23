<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme 壳层布局在 hook else 内嵌自有 <w:widget>，无继承也能有默认观感。
 */
final class ThemeShellLayoutDefaultWidgetsContractTest extends TestCase
{
    private function layout(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/' . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testDefaultLayoutEmbedsContentWidgetStack(): void
    {
        $src = $this->layout('default/default.phtml');
        self::assertStringContainsString('<w:widget type="content" name="section-heading" />', $src);
        self::assertStringContainsString('<w:widget type="content" name="text-block" />', $src);
        self::assertStringContainsString('<w:widget type="content" name="feature-list" />', $src);
        self::assertStringContainsString('<w:widget type="faq" name="faq-accordion" />', $src);
        self::assertStringContainsString('<w:widget type="content" name="contact-info" />', $src);
        self::assertStringNotContainsString('该页面尚未提供内容', $src);
    }

    public function testHomepageTrustAndMinimalEmbedWidgets(): void
    {
        $home = $this->layout('homepage/default.phtml');
        self::assertStringContainsString('layouts::homepage::trust<else/>', $home);
        // WO-HP-P2-05：首屏二折内嵌紧凑 trust-badges；页底 footer-above 另留精简版
        self::assertStringContainsString('name="trust-badges"', $home);
        self::assertStringContainsString('free-shipping', $home);
        self::assertStringContainsString('homepage-section--trust-strip', $home);

        $minimal = $this->layout('homepage/minimal.phtml');
        self::assertStringContainsString('<w:widget type="banner" name="hero-slider" />', $minimal);
        self::assertStringContainsString('<w:widget type="product" name="featured-products" />', $minimal);
        self::assertStringContainsString('<w:widget type="category" name="category-grid" />', $minimal);
    }

    public function testActivityGuideQaEmbedWidgets(): void
    {
        $activity = $this->layout('activity/default.phtml');
        self::assertStringContainsString('<w:widget type="banner" name="promo-banner" />', $activity);
        self::assertStringContainsString('<w:widget type="content" name="feature-list" />', $activity);
        self::assertStringContainsString('<w:widget type="product" name="featured-products" />', $activity);

        $guide = $this->layout('guide/default.phtml');
        self::assertStringContainsString('<w:widget type="faq" name="faq-accordion" />', $guide);
        self::assertStringContainsString('theme.guide.widget_stack', $guide);

        $qa = $this->layout('qa/default.phtml');
        self::assertStringContainsString('theme.qa.widget_stack', $qa);
        self::assertStringContainsString('<w:widget type="content" name="contact-info" />', $qa);
    }

    public function testAboutNotFoundErrorEmbedWidgets(): void
    {
        $about = $this->layout('about/default.phtml');
        self::assertStringContainsString('<w:widget type="content" name="team-grid" />', $about);
        self::assertStringContainsString('<w:widget type="faq" name="faq-accordion" />', $about);

        $notFound = $this->layout('not_found/default.phtml');
        self::assertStringContainsString(
            'layouts::not-found::recommendations',
            $notFound
        );
        self::assertStringContainsString('<w:widget type="product" name="featured-products" />', $notFound);

        $error = $this->layout('error/default.phtml');
        self::assertStringContainsString('<w:widget type="content" name="contact-info" />', $error);
    }
}
