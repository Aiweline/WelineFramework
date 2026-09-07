<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ThemeData;

final class LocalizedSwitcherWidgetRuntimeTest extends TestCore
{
    public function testLanguageWidgetUsesWebsiteScopedPathNavigationAtRuntime(): void
    {
        $html = $this->render('language-switcher');

        self::assertStringContainsString('data-i18n-switcher', $html);
        self::assertStringContainsString('data-i18n-navigation="path"', $html);
        self::assertStringContainsString('data-website-id="', $html);
        self::assertStringNotContainsString('?lang=', $html);
    }

    public function testCurrencyWidgetUsesWebsiteScopedRuntimeHook(): void
    {
        $html = $this->render('currency-switcher');

        self::assertStringContainsString('data-currency-switcher="true"', $html);
        self::assertStringContainsString('data-hook-source="Weline_I18n"', $html);
        self::assertStringContainsString('data-currency-option="true"', $html);
        self::assertStringNotContainsString('?currency=', $html);
    }

    public function testDefaultHeaderRendersOnlyWorkingLocaleAndCurrencyControls(): void
    {
        $html = $this->renderTemplate(
            'Weline_Theme::theme/frontend/partials/header/default.phtml',
        );

        self::assertStringNotContainsString('href="#"', $html);
        self::assertStringNotContainsString('?lang=', $html);
        self::assertStringNotContainsString('?currency=', $html);
        self::assertStringNotContainsString('class="language-option', $html);
        self::assertStringNotContainsString('class="currency-option', $html);
        self::assertGreaterThanOrEqual(3, substr_count($html, 'data-i18n-switcher'));
        self::assertGreaterThanOrEqual(2, substr_count($html, 'data-currency-switcher="true"'));
        self::assertMatchesRegularExpression(
            '/<button\s+type="button"\s+class="hamburger-menu-btn hamburger-menu-btn--fallback/s',
            $html,
        );
    }

    private function render(string $widget): string
    {
        return $this->renderTemplate(
            'Weline_Theme::theme/frontend/widgets/header/' . $widget . '/default.phtml',
            ['preview_mode' => true],
        );
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        ThemeData::setCurrentArea('frontend');

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchModuleThemeHtml($templatePath, $data);
    }
}
