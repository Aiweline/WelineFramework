<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesViewContractsPass().
 */
final class WebsitesViewContractsTest extends TestCase
{
    public function testAllWebsitesViewContractsPass(): void
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
            'ScopeSwitcherWidget::testScopeSwitcherRendersChannelOnlyMenuWithThemeChrome' => function () {
            $this->assert_ScopeSwitcherWidget_testScopeSwitcherRendersChannelOnlyMenuWithThemeChrome();
        },
            'WebsiteFormSaveLoading::testWebsiteFormJsSetsAriaBusyOnSubmit' => function () {
            $this->assert_WebsiteFormSaveLoading_testWebsiteFormJsSetsAriaBusyOnSubmit();
        },
            'WebsiteFormSaveLoading::testStickyActionsCssKeepsWarningWithSaveBar' => function () {
            $this->assert_WebsiteFormSaveLoading_testStickyActionsCssKeepsWarningWithSaveBar();
        },
        ];
    }

private function assert_ScopeSwitcherWidget_testScopeSwitcherRendersChannelOnlyMenuWithThemeChrome(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    $template = $moduleRoot . '/view/templates/frontend/widgets/scope-switcher.phtml';
    $css = $moduleRoot . '/view/statics/css/widgets/scope-switcher.css';
    $widget = $moduleRoot . '/extends/module/Weline_Widget/Weline_Websites/widget.php';
    $presenter = $moduleRoot . '/Service/ScopeSwitcherPresenter.php';
    self::assertFileExists($template);
    self::assertFileExists($css);
    self::assertFileExists($widget);
    self::assertFileExists($presenter);
    $tpl = (string) file_get_contents($template);
    $cssBody = (string) file_get_contents($css);
    $widgetSrc = (string) file_get_contents($widget);
    $presenterSrc = (string) file_get_contents($presenter);
    self::assertStringContainsString('data-w-component="menu"', $tpl);
    self::assertStringContainsString('data-w-placement="bottom-end"', $tpl);
    self::assertStringContainsString('data-w-anchor-mode="element"', $tpl);
    self::assertStringContainsString('data-testid="scope-switcher-link"', $tpl);
    self::assertStringContainsString('scope-switcher-current', $tpl);
    self::assertStringContainsString("i18nChrome('切换渠道')", $tpl);
    // Labels are projected server-side (ScopeSwitcherPresenter::displayName); no browser label prefetch.
    self::assertStringContainsString('$channelLabel = (string)$channel[\'label\'];', $tpl);
    self::assertStringContainsString("\$triggerText = \$currentLabel !== '' ? \$currentLabel : \$i18nChrome('切换渠道');", $tpl);
    self::assertStringNotContainsString('prefetchLabels', $tpl);
    self::assertStringNotContainsString('label_source', $tpl);
    self::assertStringNotContainsString('data-testid="scope-switcher-website"', $tpl);
    self::assertStringNotContainsString('data-testid="scope-switcher-store"', $tpl);
    self::assertStringNotContainsString("i18nChrome('网站')", $tpl);
    self::assertStringNotContainsString("i18nChrome('店铺')", $tpl);
    // CSS rides the Theme header shell layout pack instead of a per-widget @widget.source.
    self::assertStringNotContainsString('@widget.source', $tpl);
    self::assertStringContainsString('SharedChromeService::PARTIALS_HEADER_SHELL_LAYOUT_CSS', $tpl);
    self::assertDoesNotMatchRegularExpression('/<style\b/i', $tpl);
    self::assertDoesNotMatchRegularExpression('/<details\b/i', $tpl);
    self::assertStringContainsString('w-scope-switcher__channel', $cssBody);
    self::assertStringContainsString('w-scope-switcher__trigger.w-button[data-tone="quiet"]', $cssBody);
    self::assertMatchesRegularExpression('/\.w-scope-switcher__trigger\.w-button\[data-tone="quiet"\][\s\S]{0,400}?background:\s*transparent/s', $cssBody);
    self::assertStringContainsString('data-tone="quiet"', $tpl);
    self::assertStringContainsString('class="w-button w-scope-switcher__trigger"', $tpl);
    self::assertStringNotContainsString('w-scope-switcher__website', $cssBody);
    self::assertStringNotContainsString('w-scope-switcher__store-head', $cssBody);
    self::assertStringNotContainsString('position:absolute', $cssBody);
    self::assertStringContainsString('Weline_Websites::templates/frontend/widgets/scope-switcher.phtml', $widgetSrc);
    $headerChromeCss = (string) file_get_contents($moduleRoot . '/../Theme/Service/SharedChromeService.php');
    self::assertStringContainsString("'Weline_Websites::css/widgets/scope-switcher.css'", $headerChromeCss);
    self::assertStringContainsString('formatCurrentLabel($channelLabel)', $presenterSrc);
    self::assertStringContainsString('byWebsite($websiteId)', $presenterSrc);
    self::assertStringContainsString('byStore($store->id)', $presenterSrc);
}

private function assert_WebsiteFormSaveLoading_testWebsiteFormJsSetsAriaBusyOnSubmit(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/statics/js/website-form.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('setSaveBusy', $source);
    self::assertStringContainsString('onWebsiteFormSubmit', $source);
    self::assertStringContainsString("setAttribute('aria-busy', 'true')", $source);
    self::assertStringContainsString('data-website-save', $source);
    self::assertStringContainsString("listen(element, 'submit', onWebsiteFormSubmit, true)", $source);
}

private function assert_WebsiteFormSaveLoading_testStickyActionsCssKeepsWarningWithSaveBar(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/statics/css/websites-admin.css';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('.w-website-form__save-warning[data-placement="top"]', $source);
    self::assertStringContainsString('position: sticky', $source);
    self::assertStringContainsString('.w-website-form__save-warning--actions', $source);
}
}
