<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllNewsletterViewContractsPass().
 */
final class NewsletterViewContractsTest extends TestCase
{
    public function testAllNewsletterViewContractsPass(): void
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
            'NewsletterWidgetI18n::testTemplatesUseWidgetI18nForConfigurableCopy' => function () {
            $this->assert_NewsletterWidgetI18n_testTemplatesUseWidgetI18nForConfigurableCopy();
        },
            'NewsletterWidgetI18n::testSubscribeJsPrefersDataMsgAttributes' => function () {
            $this->assert_NewsletterWidgetI18n_testSubscribeJsPrefersDataMsgAttributes();
        },
            'NewsletterWidgetI18n::testWidgetI18nPrefersNewsletterModule' => function () {
            $this->assert_NewsletterWidgetI18n_testWidgetI18nPrefersNewsletterModule();
        },
        ];
    }

/** @return list<string> */
private function templates_NewsletterWidgetI18n(): array
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/templates/frontend/widgets';
    return [$base . '/footer-newsletter/default.phtml', $base . '/newsletter-popup/default.phtml', $base . '/sidebar-newsletter/default.phtml'];
}

private function assert_NewsletterWidgetI18n_testTemplatesUseWidgetI18nForConfigurableCopy(): void
{
    foreach ($this->templates_NewsletterWidgetI18n() as $path) {
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::label', $src, $path);
        self::assertStringContainsString('WidgetI18n::prefetchLabels', $src, $path);
        self::assertStringContainsString('data-msg-email-required', $src, $path);
        self::assertStringContainsString('data-msg-subscribe-failed', $src, $path);
        self::assertStringNotContainsString("__((string)(\$this->getData('button_text')", $src, $path . ' must not resolve button_text via bare __()');
    }
}

private function assert_NewsletterWidgetI18n_testSubscribeJsPrefersDataMsgAttributes(): void
{
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/statics/js/newsletter-subscribe.js';
    self::assertFileExists($js);
    $src = (string) file_get_contents($js);
    self::assertStringContainsString("data-msg-email-required", $src);
    self::assertStringContainsString("data-msg-subscribe-failed", $src);
}

private function assert_NewsletterWidgetI18n_testWidgetI18nPrefersNewsletterModule(): void
{
    $helper = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Helper/WidgetI18n.php';
    self::assertFileExists($helper);
    $src = (string) file_get_contents($helper);
    self::assertMatchesRegularExpression("/PREFERRED_MODULES\\s*=\\s*\\[[^\\]]*Weline_Newsletter/s", $src);
}
}
