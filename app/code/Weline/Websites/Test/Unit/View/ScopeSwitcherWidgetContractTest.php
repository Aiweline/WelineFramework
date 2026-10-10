<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ScopeSwitcherWidgetContractTest extends TestCase
{
    public function testScopeSwitcherRendersChannelOnlyMenuWithThemeChrome(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $template = $moduleRoot . '/view/templates/frontend/widgets/scope-switcher.phtml';
        $css = $moduleRoot . '/view/statics/css/widgets/scope-switcher.css';
        $widget = $moduleRoot . '/extends/module/Weline_Widget/Weline_Websites/widget.php';
        $presenter = $moduleRoot . '/Service/ScopeSwitcherPresenter.php';

        self::assertFileExists($template);
        self::assertFileExists($css);
        self::assertFileExists($widget);
        self::assertFileExists($presenter);

        $tpl = (string)file_get_contents($template);
        $cssBody = (string)file_get_contents($css);
        $widgetSrc = (string)file_get_contents($widget);
        $presenterSrc = (string)file_get_contents($presenter);

        self::assertStringContainsString('data-w-component="menu"', $tpl);
        self::assertStringContainsString('data-w-placement="bottom-end"', $tpl);
        self::assertStringContainsString('data-testid="scope-switcher-link"', $tpl);
        self::assertStringContainsString('scope-switcher-current', $tpl);
        self::assertStringContainsString("i18nChrome('切换渠道')", $tpl);
        self::assertStringContainsString('prefetchLabels', $tpl);
        self::assertStringContainsString('label_source', $tpl);
        self::assertStringContainsString('$i18nChrome($currentLabelRaw)', $tpl);
        self::assertStringNotContainsString('data-testid="scope-switcher-website"', $tpl);
        self::assertStringNotContainsString('data-testid="scope-switcher-store"', $tpl);
        self::assertStringNotContainsString("i18nChrome('网站')", $tpl);
        self::assertStringNotContainsString("i18nChrome('店铺')", $tpl);
        self::assertStringContainsString('@widget.source {Weline_Websites::css/widgets/scope-switcher.css}', $tpl);
        self::assertDoesNotMatchRegularExpression('/<style\b/i', $tpl);
        self::assertDoesNotMatchRegularExpression('/<details\b/i', $tpl);

        self::assertStringContainsString('w-scope-switcher__channel', $cssBody);
        self::assertStringContainsString('w-scope-switcher__trigger.w-button[data-tone="quiet"]', $cssBody);
        self::assertMatchesRegularExpression(
            '/\.w-scope-switcher__trigger\.w-button\[data-tone="quiet"\][\s\S]{0,400}?background:\s*transparent/s',
            $cssBody
        );
        self::assertStringContainsString('data-tone="quiet"', $tpl);
        self::assertStringContainsString('class="w-button w-scope-switcher__trigger"', $tpl);
        self::assertStringNotContainsString('w-scope-switcher__website', $cssBody);
        self::assertStringNotContainsString('w-scope-switcher__store-head', $cssBody);
        self::assertStringNotContainsString('position:absolute', $cssBody);

        self::assertStringContainsString("'source' => 'Weline_Websites::css/widgets/scope-switcher.css'", $widgetSrc);
        self::assertStringContainsString('formatCurrentLabel($channelLabel)', $presenterSrc);
        self::assertStringContainsString('byWebsite($websiteId)', $presenterSrc);
        self::assertStringContainsString('byStore($store->id)', $presenterSrc);
    }
}
