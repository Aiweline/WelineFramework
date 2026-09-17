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

        self::assertStringContainsString('weline-ui-2-language-switcher-', $taglib);
        self::assertStringContainsString('SWITCHER_MARKUP_VERSION', $taglib);
        self::assertSame('component-26-trigger-flag-ssr', \Weline\I18n\Taglib\LanguageSwitcher::SWITCHER_MARKUP_VERSION);
        self::assertStringContainsString('data-w-component="menu language-switcher"', $taglib);
        self::assertStringContainsString('data-w-anchor-mode="element"', $taglib);
        self::assertStringContainsString('data-w-language-search', $taglib);
        self::assertStringContainsString('w-language-switcher__list', $taglib);
        self::assertStringContainsString('data-w-language-list', $taglib);
        self::assertStringContainsString('w-language-switcher__footer', $taglib);
        self::assertStringContainsString('data-w-search=', $taglib);
        self::assertStringContainsString('translateChrome', $taglib);
        self::assertStringContainsString('loadChromeDictionary', $taglib);
        self::assertStringContainsString('applySearchFilter', $runtime);
        self::assertStringContainsString('w-language-switcher__footer', $runtime);
        self::assertStringContainsString('installGlobalLanguageOptionCapture', $runtime);
        self::assertStringContainsString('resolveSwitcherRootForOption', $runtime);
        self::assertStringContainsString('isThemeEditorPreviewFrame', $runtime);
        self::assertStringContainsString('postThemeEditorPreviewLocaleChange', $runtime);
        self::assertStringContainsString("type: 'locale-change'", $runtime);
        self::assertStringContainsString("source: 'weline-theme-preview'", $runtime);
        self::assertStringContainsString('editor_mode', $runtime);
        self::assertStringContainsString('syncLanguageSwitcherTriggerFlag', $runtime);
        self::assertStringContainsString('Never wipe a painted trigger', $runtime);
        self::assertStringContainsString("img.loading = 'eager'", $runtime);
        self::assertStringContainsString("mode === 'trigger'", $runtime);
        self::assertStringContainsString('CountryFlagMarkup::triggerHtml', $taglib);
        self::assertStringContainsString('capture-editor', $runtime);
        self::assertStringContainsString('traceLocaleSwitch', $runtime);
        $captureFn = strpos($runtime, 'function installGlobalLanguageOptionCapture');
        self::assertNotFalse($captureFn);
        $captureBody = substr($runtime, $captureFn, 2200);
        $editorCheck = strpos($captureBody, 'isThemeEditorPreviewFrame()');
        $rootResolve = strpos($captureBody, 'resolveSwitcherRootForOption(option)');
        self::assertNotFalse($editorCheck);
        self::assertNotFalse($rootResolve);
        self::assertLessThan(
            $rootResolve,
            $editorCheck,
            'installGlobalLanguageOptionCapture must test editor frame before root resolve'
        );
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

        $i18nJs = file_get_contents(dirname(__DIR__, 4) . '/Framework/View/statics/js/i18n.js');
        self::assertIsString($i18nJs);
        self::assertStringContainsString('[data-country-flag], .w-language-switcher__flag, .weline-choice-flag', $i18nJs);
        self::assertStringContainsString('[data-w-menu-trigger] [data-country-flag]', $i18nJs);
        self::assertStringContainsString('Never wipe a painted', $i18nJs);
        self::assertStringContainsString('optionPainted', $i18nJs);

        $themeJs = file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/assets/js/theme.js'
        );
        self::assertIsString($themeJs);
        self::assertStringContainsString('Never wipe a painted', $themeJs);
        self::assertStringContainsString('optionPainted', $themeJs);
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
