<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LanguageSwitcherClickRaceContractTest extends TestCase
{
    public function testTaglibUsesTheLazyUiComponentAndAuthoritativeHrefs(): void
    {
        $taglib = $this->read('Taglib/LanguageSwitcher.php');
        $runtime = $this->read('view/statics/js/language-switcher.js');

        self::assertStringContainsString('|markup=weline-ui-2-language-switcher-component-20', $taglib);
        self::assertStringContainsString('data-w-component="menu language-switcher"', $taglib);
        self::assertStringContainsString('data-w-anchor-mode="element"', $taglib);
        self::assertStringContainsString('data-w-language-search', $taglib);
        self::assertStringContainsString('data-w-search=', $taglib);
        self::assertStringContainsString('translateChrome', $taglib);
        self::assertStringContainsString('loadChromeDictionary', $taglib);
        self::assertStringContainsString('applySearchFilter', $runtime);
        self::assertStringContainsString('installGlobalLanguageOptionCapture', $runtime);
        self::assertStringContainsString('resolveSwitcherRootForOption', $runtime);
        self::assertStringContainsString('Prefer the panel nested under this switcher root', $runtime);
        self::assertStringContainsString('bindSearch', $runtime);
        self::assertStringContainsString('focusSearch', $runtime);
        self::assertStringContainsString('window.setTimeout', $runtime);
        self::assertStringContainsString("navigation === 'emit'", $runtime);
        self::assertStringContainsString('writeLanguagePreference(locale', $runtime);
        self::assertStringContainsString('resolveLanguageNavigationHref', $runtime);
        self::assertStringContainsString('rebuildPathWithLocale', $runtime);
        self::assertStringContainsString('refreshLanguageOptionHrefs', $runtime);
        self::assertStringContainsString('window.urlWithLang', $runtime);
        self::assertStringContainsString('navigateLanguageOption', $runtime);
        self::assertStringContainsString('window.location.assign(', $runtime);
        self::assertStringContainsString('window.location.reload();', $runtime);
        self::assertStringNotContainsString('<script', $taglib);
        self::assertStringNotContainsString('window.WelineI18n', $runtime);
    }

    public function testLanguageRequestUsesTheSameScopedComponent(): void
    {
        $taglib = $this->read('Taglib/LanguageSwitcher.php');
        $runtime = $this->read('view/statics/js/language-switcher.js');

        self::assertStringContainsString('data-language-request-open', $taglib);
        self::assertStringContainsString('data-language-request-modal', $taglib);
        self::assertStringContainsString('data-language-request-body', $taglib);
        self::assertStringContainsString('data-w-component="dialog"', $taglib);
        self::assertStringContainsString('<dialog id="', $taglib);
        self::assertStringContainsString('getLanguageSupportRequestForm', $runtime);
        self::assertStringContainsString('submitLanguageSupportRequest', $runtime);
        self::assertStringContainsString('bindLanguageRequestForm', $runtime);
        self::assertStringContainsString('activateTrustedScripts', $runtime);
        self::assertStringContainsString('event.preventDefault()', $runtime);
        self::assertStringContainsString('i18n_language_requests', $runtime);
        self::assertStringContainsString('UI.dialog.open', $runtime);
        self::assertStringNotContainsString('WelineLanguageSupportRequest', $runtime);
        self::assertStringNotContainsString('/i18n/frontend/language-support-request', $taglib);
    }

    public function testLanguageSupportRequestFormHasNoInlineSubmitScript(): void
    {
        $form = $this->read('view/templates/Frontend/language-support-request.phtml');
        self::assertStringContainsString('data-language-request-form-shell', $form);
        self::assertStringContainsString('data-language-request-feedback', $form);
        self::assertStringContainsString('data-msg-success=', $form);
        self::assertStringNotContainsString('<script', $form);
        self::assertStringNotContainsString('addEventListener(\'submit\'', $form);
    }

    public function testLiveHttpRequestBeatsStaleThemeDataBackendArea(): void
    {
        $taglib = $this->read('Taglib/LanguageSwitcher.php');
        self::assertStringContainsString('Live HTTP request is authoritative', $taglib);
        self::assertStringContainsString('ThemeData area can remain', $taglib);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
