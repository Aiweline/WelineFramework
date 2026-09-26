<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Storefront newsletter widgets must resolve copy via WidgetI18n (dictionary-aware),
 * not bare __() alone — WLS hot path can leave short CJK CTA like「订阅」untranslated.
 */
final class NewsletterWidgetI18nContractTest extends TestCase
{
    /** @return list<string> */
    private function templates(): array
    {
        $base = dirname(__DIR__, 3) . '/view/templates/frontend/widgets';

        return [
            $base . '/footer-newsletter/default.phtml',
            $base . '/newsletter-popup/default.phtml',
            $base . '/sidebar-newsletter/default.phtml',
        ];
    }

    public function testTemplatesUseWidgetI18nForConfigurableCopy(): void
    {
        foreach ($this->templates() as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('WidgetI18n::label', $src, $path);
            self::assertStringContainsString('WidgetI18n::prefetchLabels', $src, $path);
            self::assertStringContainsString('data-msg-email-required', $src, $path);
            self::assertStringContainsString('data-msg-subscribe-failed', $src, $path);
            self::assertStringNotContainsString(
                "__((string)(\$this->getData('button_text')",
                $src,
                $path . ' must not resolve button_text via bare __()'
            );
        }
    }

    public function testSubscribeJsPrefersDataMsgAttributes(): void
    {
        $js = dirname(__DIR__, 3) . '/view/statics/js/newsletter-subscribe.js';
        self::assertFileExists($js);
        $src = (string)file_get_contents($js);
        self::assertStringContainsString("data-msg-email-required", $src);
        self::assertStringContainsString("data-msg-subscribe-failed", $src);
    }

    public function testWidgetI18nPrefersNewsletterModule(): void
    {
        $helper = dirname(__DIR__, 4) . '/Theme/Helper/WidgetI18n.php';
        self::assertFileExists($helper);
        $src = (string)file_get_contents($helper);
        self::assertMatchesRegularExpression(
            "/PREFERRED_MODULES\s*=\s*\[[^\]]*Weline_Newsletter/s",
            $src
        );
    }
}
